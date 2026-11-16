"""Authenticated loopback HTTP service. From ml/: python -B -m glowme_ml.service.absa_service."""
from __future__ import annotations

import hmac
import json
import logging
import os
import signal
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from .predictor import ASPECTS, MAX_CHARS, Predictor, SegmenterError

API_VERSION = "glowme-absa-service-v1"
MAX_BODY_BYTES = 1024 * 1024
LOG = logging.getLogger("absa_service")


class ConfigError(ValueError):
    pass


def load_config(env=None):
    env = os.environ if env is None else env
    token = env.get("ABSA_SERVICE_TOKEN", "")
    if len(token) < 24:
        raise ConfigError("ABSA_SERVICE_TOKEN requires at least 24 characters")
    host = env.get("ABSA_SERVICE_HOST", "127.0.0.1")
    if host != "127.0.0.1":
        raise ConfigError("host must be 127.0.0.1")
    try:
        port = int(env.get("ABSA_SERVICE_PORT", "8765"))
        batch = int(env.get("ABSA_MAX_BATCH", "32"))
        chars = int(env.get("ABSA_MAX_TEXT_CHARS", "4000"))
        timeout = float(env.get("ABSA_READ_TIMEOUT", "10"))
    except (ValueError, TypeError):
        raise ConfigError("invalid numeric setting") from None
    if not (0 <= port <= 65535 and 1 <= batch <= 128 and 1 <= chars <= MAX_CHARS
            and 0 < timeout <= 60):
        raise ConfigError("numeric setting out of range")
    return {"host": host, "port": port, "token": token, "max_batch": batch,
            "max_chars": chars, "read_timeout": timeout}


class AbsaService:
    def __init__(self, predictor, config):
        self.predictor, self.config = predictor, config
        self.lock = threading.RLock()
        self.started = time.monotonic()
        self.stats = {"requests": 0, "reviews": 0, "item_errors": 0, "failed_requests": 0}

    def identity(self):
        m = self.predictor.metadata
        fields = ("seed", "checkpoint_sha256", "taxonomy_version", "preprocessing_version",
                  "base_model_revision", "aspects", "labels", "device", "precision", "probability_note")
        return {"id": m["model"], **{key: m[key] for key in fields}}

    def health(self):
        return {"status": "ok", "api_version": API_VERSION, "model": self.identity(),
                "uptime_s": round(time.monotonic() - self.started, 1), "stats": dict(self.stats),
                "limits": {"max_batch": self.config["max_batch"], "max_text_chars": self.config["max_chars"]}}

    def validate_request(self, payload):
        if not isinstance(payload, dict) or set(payload) != {"reviews"} or not isinstance(payload["reviews"], list):
            raise ValueError("body must contain reviews")
        if not 1 <= len(payload["reviews"]) <= self.config["max_batch"]:
            raise ValueError("invalid review count")
        ids, texts = [], []
        for row in payload["reviews"]:
            if (not isinstance(row, dict) or set(row) != {"id", "text"}
                    or not isinstance(row["id"], str) or not 1 <= len(row["id"]) <= 64
                    or not isinstance(row["text"], str)):
                raise ValueError("each review requires string id and text")
            try:
                row["id"].encode("utf-8")
            except UnicodeEncodeError:
                raise ValueError("invalid review id") from None
            if len(row["text"]) > self.config["max_chars"]:
                raise ValueError("review text too long")
            ids.append(row["id"])
            texts.append(row["text"])
        if len(set(ids)) != len(ids):
            raise ValueError("review ids must be unique")
        return ids, texts

    def predict(self, payload):
        ids, texts = self.validate_request(payload)
        with self.lock:
            records = self.predictor.predict_batch(texts, ids)
            items = []
            for r in records:
                if r["status"] != "ok":
                    items.append({"id": r["id"], "status": "error", "error": {"code": r["error"]["code"]}})
                else:
                    items.append({"id": r["id"], "status": "ok",
                        "labels": {a: r["predictions"][a]["label"] for a in ASPECTS},
                        "probabilities": {a: r["predictions"][a]["probabilities"] for a in ASPECTS},
                        "n_tokens": r["n_tokens"], "truncated": r["truncated"]})
            self.stats["requests"] += 1
            self.stats["reviews"] += len(items)
            self.stats["item_errors"] += sum(i["status"] != "ok" for i in items)
            return {"api_version": API_VERSION, "model": self.identity(), "items": items}


def make_handler(service):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        server_version = "GlowMeAbsa/1"

        def setup(self):
            self.request.settimeout(service.config.get("read_timeout", 10))
            super().setup()

        def log_message(self, fmt, *args):
            # Never interpolate request line, method, path, header, body or exception.
            LOG.info("HTTP request completed")

        def _send(self, status, body):
            self.close_connection = True
            data = json.dumps(body, ensure_ascii=True, allow_nan=False).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(data)

        def _error(self, status, code):
            return self._send(status, {"error": {"code": code}})

        def send_error(self, code, message=None, explain=None):
            # Covers malformed request lines/headers without reflecting their contents.
            self._error(code, "BAD_REQUEST")

        def _dispatch(self):
            supplied = self.headers.get_all("X-ABSA-Token", [])
            if len(supplied) != 1 or not hmac.compare_digest(
                    supplied[0].encode("utf-8"), service.config["token"].encode("utf-8")):
                return self._error(401, "UNAUTHORIZED")
            if self.command not in ("GET", "POST"):
                return self._error(405, "METHOD_NOT_ALLOWED")
            path = self.path.split("?", 1)[0]
            if self.command == "GET":
                return self._send(200, service.health()) if path == "/health" else self._error(404, "NOT_FOUND")
            if path != "/v1/absa/predict":
                return self._error(404, "NOT_FOUND")
            lengths = self.headers.get_all("Content-Length", [])
            if self.headers.get("Transfer-Encoding") is not None or len(lengths) != 1:
                return self._error(400, "BAD_REQUEST")
            try:
                length = int(lengths[0])
            except ValueError:
                return self._error(400, "BAD_REQUEST")
            if not 0 < length <= MAX_BODY_BYTES:
                return self._error(413 if length > MAX_BODY_BYTES else 400,
                                   "PAYLOAD_TOO_LARGE" if length > MAX_BODY_BYTES else "BAD_REQUEST")
            if (self.headers.get("Content-Type") or "").split(";", 1)[0].strip().lower() != "application/json":
                return self._error(415, "UNSUPPORTED_MEDIA_TYPE")
            try:
                raw = self.rfile.read(length)
                if len(raw) != length:
                    return self._error(400, "BAD_REQUEST")
                payload = json.loads(raw.decode("utf-8"))
            except (UnicodeError, ValueError, RecursionError):
                return self._error(400, "BAD_REQUEST")
            except (TimeoutError, socket.timeout):
                return self._error(408, "REQUEST_TIMEOUT")
            try:
                result = service.predict(payload)
            except ValueError:
                return self._error(400, "BAD_REQUEST")
            except SegmenterError:
                service.stats["failed_requests"] += 1
                LOG.error("segmentation unavailable")
                return self._error(503, "SEGMENTER_FAILED")
            except Exception:
                service.stats["failed_requests"] += 1
                LOG.error("inference unavailable")
                return self._error(500, "INFERENCE_FAILED")
            return self._send(200, result)

        do_GET = do_POST = _dispatch

        def __getattr__(self, name):
            if name.startswith("do_"):
                return self._dispatch
            raise AttributeError(name)

    return Handler


class LocalServer(ThreadingHTTPServer):
    daemon_threads = False
    block_on_close = True

    def handle_error(self, request, client_address):
        LOG.error("HTTP connection failed")

    def server_close(self):
        try:
            super().server_close()
        finally:
            self.service.predictor.close()


def build_server(service, host, port):
    if host != "127.0.0.1":
        raise ConfigError("host must be 127.0.0.1")
    if len(service.config.get("token", "")) < 24:
        raise ConfigError("token requires at least 24 characters")
    server = LocalServer((host, port), make_handler(service))
    server.service = service
    return server


def main():
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    predictor = server = None
    try:
        config = load_config()
        predictor = Predictor(batch_size=config["max_batch"])
        probe = predictor.predict_batch(["Kiểm tra dịch vụ phân tích đánh giá."], ["readiness"])
        if not probe or probe[0]["status"] != "ok":
            raise RuntimeError("inference readiness check failed")
        server = build_server(AbsaService(predictor, config), config["host"], config["port"])
        LOG.info("AI service ready on %s:%s (%s)", config["host"], config["port"], predictor.metadata["model"])
        signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=server.shutdown, daemon=True).start())
        server.serve_forever(poll_interval=0.25)
    except KeyboardInterrupt:
        pass
    except Exception as error:
        LOG.error("service startup or runtime failed (%s)", type(error).__name__)
        return 2
    finally:
        if server is not None:
            server.server_close()
        elif predictor is not None:
            predictor.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
