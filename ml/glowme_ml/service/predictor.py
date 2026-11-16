"""CPU fp32 inference using the new training implementation and a local release."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import threading
import time

import numpy as np
import torch
from transformers import AutoConfig, AutoModel, AutoTokenizer

from ..ml.src.data import ASPECTS, LABELS, REVISION
from ..ml.src.model import MultiAspectPhoBERT
from ..ml.src.preprocess import normalize
from ..ml.src.segmenter import PersistentSegmenter

RELEASE = Path(__file__).resolve().parents[1] / "release/phobert-beauty-v2"
CHECKPOINT_SHA256 = "6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc"
MAX_LENGTH = 256
MAX_CHARS = 4000


class SegmenterError(RuntimeError):
    code = "SEGMENTER_FAILED"


def encode(segmented, tokenizer, max_length=MAX_LENGTH):
    ids = tokenizer(segmented, add_special_tokens=True, truncation=False)["input_ids"]
    n = len(ids)
    if n > max_length:
        ids = ids[:max_length - 1] + [tokenizer.sep_token_id]
    return ids, n, n > max_length


def validate_text(text):
    if not isinstance(text, str):
        return None, "INVALID_TYPE"
    try:
        text.encode("utf-8")
    except UnicodeEncodeError:
        return None, "INVALID_ENCODING"
    if len(text) > MAX_CHARS:
        return None, "TEXT_TOO_LONG"
    text = normalize(text)
    if not text:
        return None, "EMPTY_TEXT"
    if re.search(r"[\x00-\x1f\x7f]", text):
        return None, "INVALID_CONTROL_CHARACTER"
    return text, None


class Predictor:
    def __init__(self, release_dir=RELEASE, batch_size=32):
        started = time.perf_counter()
        if type(batch_size) is not int or not 1 <= batch_size <= 128:
            raise ValueError("invalid batch size")
        release = Path(release_dir)
        manifest = json.loads((release / "manifest.json").read_text(encoding="utf-8"))
        required = {"model", "seed", "checkpoint_sha256", "taxonomy_version", "preprocessing_version",
                    "base_model_revision", "aspects", "labels", "max_length", "probability_note", "segmenter"}
        if not required <= manifest.keys():
            raise ValueError("incomplete release manifest")
        if (manifest["model"] != "phobert-beauty-v2" or manifest["taxonomy_version"] != "beauty-4-v1"
                or manifest["base_model_revision"] != REVISION
                or manifest["aspects"] != ASPECTS or list(manifest["labels"]) != LABELS
                or manifest["segmenter"] != "persistent" or manifest["seed"] != 42
                or manifest["preprocessing_version"] != "prep-v1"
                or manifest["checkpoint_sha256"] != CHECKPOINT_SHA256):
            raise ValueError("release identity mismatch")
        self.max_length = manifest["max_length"]
        if type(self.max_length) is not int or not 2 <= self.max_length <= 256:
            raise ValueError("invalid release max length")
        checkpoint = release / "best_model.pt"
        digest = hashlib.sha256()
        with checkpoint.open("rb") as stream:
            for block in iter(lambda: stream.read(4 * 1024 * 1024), b""):
                digest.update(block)
        if digest.hexdigest() != CHECKPOINT_SHA256:
            raise ValueError("checkpoint integrity mismatch")
        self.tokenizer = AutoTokenizer.from_pretrained(str(release), local_files_only=True,
                                                      use_fast=False)
        config = AutoConfig.from_pretrained(str(release), local_files_only=True)
        encoder = AutoModel.from_config(config, add_pooling_layer=False)
        self.model = MultiAspectPhoBERT(encoder=encoder).cpu().float()
        state = torch.load(checkpoint, map_location="cpu", weights_only=True)
        self.model.load_state_dict(state.get("model", state), strict=True)
        self.model.eval()
        self.segmenter = PersistentSegmenter()
        self.batch_size = batch_size
        self.lock = threading.RLock()
        self.closed = False
        self.metadata = {**manifest, "device": "cpu", "precision": "fp32"}
        self.load_info = {"total_s": round(time.perf_counter() - started, 3),
                          "state_dict_tensors": len(self.model.state_dict())}

    def _forward(self, id_lists):
        out = []
        with torch.inference_mode():
            for start in range(0, len(id_lists), self.batch_size):
                rows = id_lists[start:start + self.batch_size]
                width = max(map(len, rows))
                ids = torch.full((len(rows), width), self.tokenizer.pad_token_id, dtype=torch.long)
                mask = torch.zeros_like(ids)
                for i, row in enumerate(rows):
                    ids[i, :len(row)] = torch.tensor(row, dtype=torch.long)
                    mask[i, :len(row)] = 1
                out.append(self.model(ids, mask).softmax(-1).cpu().numpy())
        return np.concatenate(out) if out else np.zeros((0, 4, 4), dtype=np.float32)

    def predict_batch(self, texts, ids=None):
        texts = list(texts)
        ids = list(range(1, len(texts) + 1)) if ids is None else list(ids)
        if len(ids) != len(texts):
            raise ValueError("ids and texts must have the same length")
        with self.lock:
            if self.closed:
                raise RuntimeError("predictor is closed")
            records, sequences, ready = [], [], []
            for rid, text in zip(ids, texts):
                norm, error = validate_text(text)
                record = {"id": rid, "status": "error", "error": None, "predictions": None,
                          "n_tokens": None, "truncated": None}
                records.append(record)
                if error is None:
                    try:
                        segmented = self.segmenter.segment(norm)
                    except Exception as exc:
                        raise SegmenterError("segmentation unavailable") from exc
                    if not segmented or not segmented.strip():
                        error = "SEGMENTATION_EMPTY"
                    else:
                        seq, n, truncated = encode(segmented, self.tokenizer, self.max_length)
                        record.update(n_tokens=n, truncated=truncated)
                        ready.append(record)
                        sequences.append(seq)
                if error:
                    record["error"] = {"code": error, "message": "invalid review text"}
            for record, probs in zip(ready, self._forward(sequences)):
                predictions = {}
                for i, aspect in enumerate(ASPECTS):
                    label = int(np.argmax(probs[i]))
                    predictions[aspect] = {"label": LABELS[label], "label_id": label,
                        "probabilities": {name: float(probs[i, j]) for j, name in enumerate(LABELS)}}
                record.update(status="ok", error=None, predictions=predictions)
            return records

    def predict(self, text, review_id=None):
        return {**self.metadata, **self.predict_batch([text], [review_id])[0]}

    def close(self):
        with self.lock:
            self.closed = True
            self.segmenter.close()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.close()
