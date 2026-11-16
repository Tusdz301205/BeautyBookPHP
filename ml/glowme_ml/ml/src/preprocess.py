"""prep-v1: exact normalization rules from the original verified implementation."""
from __future__ import annotations
import re
import unicodedata

PREP_VERSION = "prep-v1"
TEENCODE = {
    "ko": "không", "k": "không", "hông": "không", "hok": "không", "khum": "không", "kg": "không",
    "đc": "được", "dc": "được", "nv": "nhân viên", "j": "gì", "r": "rồi", "rùi": "rồi", "mn": "mọi người",
    "wá": "quá", "qá": "quá", "lun": "luôn", "thui": "thôi", "mk": "mình", "mik": "mình", "trc": "trước",
    "nhìu": "nhiều", "bít": "biết", "cg": "cũng", "cũg": "cũng", "vs": "với", "thw": "thương", "thươg": "thương",
    "iu": "yêu", "đt": "điện thoại", "bh": "bây giờ", "zậy": "vậy",
}
_TOKEN_RE = re.compile(r"(?<![\w])(" + "|".join(sorted(map(re.escape, TEENCODE), key=len, reverse=True)) + r")(?![\w])",
                       re.IGNORECASE)


def normalize(text: str) -> str:
    t = unicodedata.normalize("NFC", text)
    t = re.sub(r"\s+", " ", t).strip()

    def repl(m):
        src = m.group(0)
        out = TEENCODE[src.lower()]
        return out[0].upper() + out[1:] if src[0].isupper() else out
    return _TOKEN_RE.sub(repl, t)
