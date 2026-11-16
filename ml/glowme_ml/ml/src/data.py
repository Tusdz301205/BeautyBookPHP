"""Read-only dataset access and reproducibility gates. Never writes under dataset/."""
from __future__ import annotations

import csv
import hashlib
import json
import unicodedata
from collections import Counter
from dataclasses import dataclass
from itertools import combinations
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = ROOT.parent
TASK_ID = "TECH-ML-REBUILD-20261104"
RUN_ROOT = ROOT / "runs" / TASK_ID
SOURCE = ROOT / "dataset/nguon/vlsp2018_mo_rong.csv"
REVISION = "01daacda68afe13d83023d16ec647239e344a1e6"
MODEL_CACHE = ROOT / ".hf_cache/hub/models--vinai--phobert-base/snapshots" / REVISION
ASPECTS = ["SERVICE_QUALITY", "STAFF_ATTITUDE", "WAIT_TIME", "PRICE"]
LABELS = ["NOT_MENTIONED", "POS", "NEG", "NEU"]
SPLITS = {"train": ("1_huan_luyen", 2462, "21fbe28ef539"),
          "dev": ("2_kiem_dinh", 385, "cf3e8e5631a8"),
          "test": ("3_kiem_thu", 375, "cc7a44b17fab")}
TOOL_HASHES = {
    "VnCoreNLP-1.2.jar": "9e2811cdbc2ddfc71d04be5dc36e185c88dcd1ad4d5d69e4ff2e1369dccf7793",
    "models/wordsegmenter/wordsegmenter.rdr": "9e62f96bd93e37a24f364238e8d8ae986fa5dad6dbc9f4eae622ab3651b7fa06",
    "models/wordsegmenter/vi-vocab": "0a47c5b55bbce163029d37730a67b9479740388695c29c106c112b815613eaa5",
}


@dataclass(frozen=True)
class Example:
    uid: str
    text: str
    labels: list[int]


def sha256(path):
    digest = hashlib.sha256()
    with Path(path).open("rb") as f:
        for block in iter(lambda: f.read(4 * 1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def write_json(path, obj):
    path = Path(path)
    # A central guard also protects against accidental output path configuration.
    if path.resolve().is_relative_to((ROOT / "dataset").resolve()):
        raise ValueError("dataset is read-only")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(obj, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_data(splits=("train", "dev"), source=SOURCE):
    if not set(splits) <= set(SPLITS):
        raise ValueError("unknown split")
    out = {s: [] for s in splits}
    seen = set()
    with Path(source).open(encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        if reader.fieldnames != ["id", "linh_vuc", "tap", "Review", "nhan"]:
            raise ValueError("unexpected source columns")
        for row in reader:
            if row["linh_vuc"] != "lam_dep":
                continue
            if row["id"] in seen:
                raise ValueError("duplicate source id")
            seen.add(row["id"])
            if row["tap"] not in SPLITS:
                raise ValueError("unexpected source split")
            if row["tap"] not in out:
                continue
            labels = json.loads(row["nhan"])
            if set(labels) != set(ASPECTS) or any(type(v) is not int or v not in range(4) for v in labels.values()):
                raise ValueError("unexpected aspect labels")
            if not row["Review"].strip():
                raise ValueError("empty review")
            out[row["tap"]].append(Example(row["id"], row["Review"], [labels[a] for a in ASPECTS]))
    return out


def verify_data():
    data = load_data(tuple(SPLITS))
    counts = {s: len(ex) for s, ex in data.items()}
    labels = Counter(v for ex in data.values() for e in ex for v in e.labels)
    d1 = counts == {s: p[1] for s, p in SPLITS.items()} and labels == {0: 5805, 1: 2805, 2: 2684, 3: 1594}
    parity = {}
    for split, (filename, count, prefix) in SPLITS.items():
        p = ROOT / "dataset/nguon/vlsp2018_lam_dep" / (filename + ".csv")
        with p.open(encoding="utf-8-sig", newline="") as f:
            original = list(csv.DictReader(f))
        differences = [i for i, (e, r) in enumerate(zip(data[split], original), 1)
                       if e.uid != f"beauty_services.beauty.{split}.{i}"
                       or e.text != r["Review"] or e.labels != [int(r[a]) for a in ASPECTS]]
        digest = sha256(p)
        parity[split] = {"path": str(p.relative_to(ROOT)), "sha256": digest,
                         "n_source": len(original), "n_selected": len(data[split]),
                         "different_rows": differences,
                         "pass": len(original) == len(data[split]) == count and not differences and digest.startswith(prefix)}
    sets = {s: {unicodedata.normalize("NFC", e.text) for e in ex} for s, ex in data.items()}
    overlaps = {a + "_" + b: len(sets[a] & sets[b]) for a, b in combinations(SPLITS, 2)}
    tool_hashes = {name: sha256(ROOT / "tools/VnCoreNLP" / name) for name in TOOL_HASHES}
    metadata = json.loads((ROOT / "dataset/ho_tro/metadata/vlsp2018_mo_rong.json").read_text(encoding="utf-8-sig"))
    source_hash = sha256(SOURCE)
    card = {"task_id": TASK_ID, "source": str(SOURCE.relative_to(ROOT)), "source_sha256": source_hash,
            "selection": "linh_vuc == lam_dep; splits unchanged", "aspects": ASPECTS,
            "label_mapping": dict(enumerate(LABELS)), "counts": counts, "label_counts": dict(labels),
            "row_parity": parity, "id_check": "Original CSV has no id; compare stable beauty_services.beauty.<split>.<1-based source row> plus exact text and labels.",
            "nfc_cross_split_overlaps": overlaps, "vncorenlp_sha256": tool_hashes,
            "metadata_csv_hash_matches": metadata["csv_sha256"] == source_hash,
            "metadata_path_note": "Metadata tep_csv still names the former root location; actual user-designated input is nguon/vlsp2018_mo_rong.csv. Dataset is unchanged.",
            "synthetic": True, "real_world_evaluation": False,
            "gates": {"D1": d1, "D2": all(p["pass"] for p in parity.values()),
                      "D3": not any(overlaps.values()), "D4": tool_hashes == TOOL_HASHES}}
    write_json(RUN_ROOT / "data_card.json", card)
    if not all(card["gates"].values()) or not card["metadata_csv_hash_matches"]:
        raise RuntimeError("D gate failed: stop for user decision")
    return card


if __name__ == "__main__":
    print(json.dumps(verify_data()["gates"]))
