"""Bounded, serialized persistent Java worker plus legacy batch equivalence route."""
from __future__ import annotations
import os
import queue
import shutil
import subprocess
import threading
import uuid
from pathlib import Path
from .data import ROOT, RUN_ROOT
from .preprocess import normalize

VDIR = ROOT / "tools/VnCoreNLP"
RUNTIME = ROOT / "ml/.runtime"
JAVA_SOURCE = Path(__file__).parent / "java/SegmentStream.java"
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def java_tools():
    """Use java and javac from one JDK, even if PATH points java at an older JRE."""
    suffix = ".exe" if os.name == "nt" else ""
    home = os.environ.get("JAVA_HOME")
    candidates = [Path(home) / "bin"] if home else []
    compiler = shutil.which("javac")
    if compiler:
        candidates.append(Path(compiler).resolve().parent)
    for directory in candidates:
        java, javac = directory / ("java" + suffix), directory / ("javac" + suffix)
        if java.is_file() and javac.is_file():
            return str(java), str(javac)
    raise RuntimeError("A JDK containing both java and javac is required")


def compile_worker():
    RUNTIME.mkdir(parents=True, exist_ok=True)
    target = RUNTIME / "SegmentStream.class"
    if not target.exists() or target.stat().st_mtime < JAVA_SOURCE.stat().st_mtime:
        subprocess.run([java_tools()[1], "-encoding", "UTF-8", "-cp", str(VDIR / "VnCoreNLP-1.2.jar"),
                        "-d", str(RUNTIME), str(JAVA_SOURCE)], check=True, timeout=60,
                       capture_output=True, creationflags=NO_WINDOW)


class PersistentSegmenter:
    def __init__(self, timeout=30.0):
        self.timeout = timeout
        self.process = None
        self.responses = None
        self.reader = None
        self.lock = threading.RLock()
        self.restarts = 0
        self.closed = False

    @staticmethod
    def _read(stream, responses):
        try:
            for line in stream:
                responses.put(line.rstrip("\r\n"))
        finally:
            responses.put(None)

    def _response(self):
        try:
            value = self.responses.get(timeout=self.timeout)
        except queue.Empty as exc:
            raise TimeoutError("segmenter response timed out") from exc
        if value is None:
            raise RuntimeError("segmenter terminated")
        return value

    def _start(self):
        compile_worker()
        self.responses = queue.Queue()
        self.process = subprocess.Popen(
            [java_tools()[0], "-Xmx2g", "-cp", os.pathsep.join([str(VDIR / "VnCoreNLP-1.2.jar"), str(RUNTIME)]), "SegmentStream"],
            cwd=VDIR, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, encoding="utf-8", bufsize=1, creationflags=NO_WINDOW)
        self.reader = threading.Thread(target=self._read, args=(self.process.stdout, self.responses), daemon=True)
        self.reader.start()
        if self._response() != "__READY__":
            raise RuntimeError("invalid segmenter startup")

    def _stop(self):
        p = self.process
        self.process = None
        if p is not None:
            if p.poll() is None:
                p.terminate()
                try:
                    p.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    p.kill()
                    p.wait(timeout=3)
            for stream in (p.stdin, p.stdout):
                if stream:
                    stream.close()
        if self.reader:
            self.reader.join(timeout=3)
            self.reader = None

    def segment(self, text):
        text = normalize(text)
        with self.lock:
            if self.closed:
                raise RuntimeError("segmenter is closed")
            for attempt in range(2):
                try:
                    if self.process is None or self.process.poll() is not None:
                        self._stop()
                        self._start()
                    self.process.stdin.write(text + "\n")
                    self.process.stdin.flush()
                    answer = self._response()
                    if text and not answer.strip():
                        raise RuntimeError("empty segmenter response")
                    return answer
                except (OSError, RuntimeError, TimeoutError):
                    self._stop()
                    if attempt:
                        raise
                    self.restarts += 1

    def segment_many(self, texts):
        return [self.segment(t) for t in texts]

    def close(self):
        with self.lock:
            self.closed = True
            self._stop()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.close()


def legacy_batch(texts, workdir=None):
    """No cache, no mutation of tools/; retain input/output as audit evidence."""
    directory = Path(workdir) if workdir else RUN_ROOT / "verification" / ("batch_" + uuid.uuid4().hex)
    directory.mkdir(parents=True, exist_ok=True)
    source, dest = directory / "input.txt", directory / "output.txt"
    normalized = [normalize(t) for t in texts]
    source.write_text("\n".join(normalized) + "\n", encoding="utf-8")
    cp = os.pathsep.join([str(VDIR / "VnCoreNLP-1.2.jar"), str(ROOT / "tools/segmenter")])
    subprocess.run([java_tools()[0], "-Xmx2g", "-cp", cp, "SegmentLines", str(source.resolve()), str(dest.resolve())],
                   cwd=VDIR, check=True, timeout=300, capture_output=True, creationflags=NO_WINDOW)
    output = dest.read_text(encoding="utf-8").splitlines()
    if len(output) != len(normalized):
        raise RuntimeError("legacy batch line count mismatch")
    return output
