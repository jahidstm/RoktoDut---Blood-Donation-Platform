"""Deterministic extractors for Bangla / English / Banglish blood-request text.

Each extractor returns (value, evidence, warnings). Every function receives text that
has already been through `normalize()`. The guiding rule: when unsure, return None
and say why. A wrong blood group is far worse than a missing one, because a human
fills a missing field but may not notice a wrong one.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

from nlp.normalize import BN, alternation

# ── Blood group ─────────────────────────────────────────────────────────────

_ABO = {
    "AB": ["ab", "এবি"],
    "A": ["a", "এ"],
    "B": ["b", "বি"],
    "O": ["o", "0", "ও"],  # "0" (zero) is a common typo for O
}
_RH_WORDS = {
    "+": ["positive", "pos", "+ve", "পজিটিভ", "পজেটিভ", "পজিটিব", "পজেটিব", "পসিটিভ", "পজিটিফ"],
    "-": ["negative", "neg", "-ve", "নেগেটিভ", "নেগেটিব", "নেগেটিফ", "নেগেটিভে"],
}

_ABO_ALT = alternation([w for words in _ABO.values() for w in words])
_RH_WORD_ALT = alternation([w for words in _RH_WORDS.values() for w in words])

# The ABO letter must stand alone: not preceded by a letter, digit or Bangla character
# (so "hb", "rajshahi", "10-", "০১৭-" never match).
_LEFT = rf"(?<![a-z0-9{BN}])"
# A word-form Rh must end the token ("positive" yes, "positively" no).
_RIGHT_WORD = rf"(?![a-z{BN}])"

# A / এ  [space, comma, underscore, "rh", "(d)", "("]  positive / পজিটিভ / +ve ...
_BG_WORD = re.compile(
    rf"{_LEFT}(?P<abo>{_ABO_ALT})[\s,_(]*(?:rh\s*(?:\(d\))?\s*)?\(?\s*(?P<rh>{_RH_WORD_ALT}){_RIGHT_WORD}"
)
# A+ / A (+) / এবি- : a bare symbol only directly after the letter, optionally in brackets.
_BG_SYMBOL = re.compile(
    rf"{_LEFT}(?P<abo>{_ABO_ALT})\s?\(?\s?(?P<rh>[+-])(?!\d)"
)

_ABO_LOOKUP = {}
for canonical, words in _ABO.items():
    for w in words:
        _ABO_LOOKUP[w] = canonical
_RH_LOOKUP = {}
for sign, words in _RH_WORDS.items():
    for w in words:
        _RH_LOOKUP[w] = sign

# "B group" / "বি গ্রুপ" with no Rh: worth a warning, never a guess.
_BG_NO_RH = re.compile(rf"{_LEFT}(?:{_ABO_ALT})\s*(?:group|গ্রুপ|গ্রুপের)")


def _canonical_rh(token: str) -> str:
    token = token.strip()
    if token in ("+", "-"):
        return token
    return _RH_LOOKUP.get(token, "")


def extract_blood_group(text: str) -> tuple[str | None, str | None, list[str]]:
    found: list[tuple[int, str, str]] = []
    for pattern in (_BG_WORD, _BG_SYMBOL):
        for m in pattern.finditer(text):
            abo = _ABO_LOOKUP.get(m.group("abo"))
            rh = _canonical_rh(m.group("rh"))
            if abo and rh:
                found.append((m.start(), abo + rh, m.group(0).strip()))

    groups = {g for _, g, _ in found}
    if len(groups) == 1:
        found.sort()
        return found[0][1], found[0][2], []
    if len(groups) > 1:
        return None, None, [f"multiple blood groups mentioned: {', '.join(sorted(groups))}"]
    if _BG_NO_RH.search(text):
        return None, None, ["blood group given without Rh (+/-)"]
    return None, None, []


# ── Units (bags) ────────────────────────────────────────────────────────────

_NUMBER_WORDS = {
    1: ["one", "এক"], 2: ["two", "দুই", "দু"], 3: ["three", "তিন"], 4: ["four", "চার"],
    5: ["five", "পাঁচ"], 6: ["six", "ছয়"], 7: ["seven", "সাত"], 8: ["eight", "আট"],
    9: ["nine", "নয়"], 10: ["ten", "দশ"],
}
_WORD_TO_INT = {}
for n, words in _NUMBER_WORDS.items():
    for w in words:
        _WORD_TO_INT[w] = n
_NUM_WORD_ALT = alternation(list(_WORD_TO_INT))

# A number: digits, or a number word that is not the start of a longer word
# ("এক ব্যাগ" yes, "এক্সিডেন্ট" no). Bangla counters টি/টা/জন may follow directly.
_NUM = rf"(?P<num>(?<![\d.])\d{{1,2}}(?!\d|\.\d)|{_LEFT}(?:{_NUM_WORD_ALT})(?=\s|টি|টা|জন|$|[,.।]))"
_UNIT_NOUN = alternation(["bags", "bag", "ব্যাগ", "units", "unit", "ইউনিট", "pints", "pint", "পিন্ট", "donors", "donor"])
_COUNTER = r"(?:\s*(?:টি|টা|ti|ta))?"

_UNITS_BEFORE = re.compile(rf"{_NUM}{_COUNTER}\s*(?:{_UNIT_NOUN}){_RIGHT_WORD}")
# "২ জন ... ডোনার": people counted as donors, one bag each.
_UNITS_PEOPLE = re.compile(rf"{_NUM}\s*জন(?=.{{0,30}}(?:ডোনার|রক্তদাতা|দাতা|donor))")
# "ব্যাগ: ৩টি", "Bag 2", "পরিমাণ: দুই"
_UNITS_AFTER = re.compile(
    rf"(?:{alternation(['bag', 'bags', 'ব্যাগ', 'quantity', 'পরিমাণ', 'পরিমান'])})\s*[:ঃ=\-]?\s*{_NUM}"
)


def _to_int(token: str) -> int | None:
    token = token.strip()
    if token.isdigit():
        return int(token)
    return _WORD_TO_INT.get(token)


def extract_units(text: str) -> tuple[int | None, str | None, list[str]]:
    found: list[tuple[int, int, str]] = []
    for pattern in (_UNITS_BEFORE, _UNITS_PEOPLE, _UNITS_AFTER):
        for m in pattern.finditer(text):
            value = _to_int(m.group("num"))
            if value is not None:
                found.append((m.start(), value, m.group(0).strip()))

    if not found:
        return None, None, []

    values = {v for _, v, _ in found}
    if len(values) > 1:
        return None, None, [f"different quantities mentioned: {', '.join(map(str, sorted(values)))}"]

    value = values.pop()
    if not 1 <= value <= 20:
        return None, None, [f"implausible quantity: {value}"]

    found.sort()
    return value, found[0][2], []


# ── Component ───────────────────────────────────────────────────────────────

_COMPONENTS = {
    "platelets": ["platelets", "platelet", "প্লাটিলেট", "প্লেটলেট", "প্লাটিলেটস", "sdp", "apheresis"],
    "plasma": ["plasma", "প্লাজমা", "ffp"],
    "packed_rbc": ["packed cell", "packed cells", "packed rbc", "prbc", "red cell", "red cells", "রেড সেল", "প্যাকড সেল"],
    "whole_blood": ["whole blood", "হোল ব্লাড"],
}
_COMPONENT_PATTERNS = {
    name: re.compile(rf"{_LEFT}(?:{alternation(words)}){_RIGHT_WORD}") for name, words in _COMPONENTS.items()
}


def extract_component(text: str) -> tuple[str | None, str | None, list[str]]:
    hits = [(name, m) for name, p in _COMPONENT_PATTERNS.items() if (m := p.search(text))]
    if len(hits) == 1:
        return hits[0][0], hits[0][1].group(0), []
    if len(hits) > 1:
        return None, None, [f"several components mentioned: {', '.join(sorted(n for n, _ in hits))}"]
    return None, None, []


# ── Urgency ─────────────────────────────────────────────────────────────────

_EMERGENCY = re.compile(
    rf"{_LEFT}(?:{alternation(['emergency', 'ইমার্জেন্সি', 'ইমারজেন্সি', 'ইমারজেন্সী', 'ইমার্জেন্সী', 'immediately', 'asap', 'right now', 'এখনই', 'এক্ষুনি', 'এখুনি'])})"
    rf"|(?:{alternation(['অতি', 'খুব', 'খুবই', 'অত্যন্ত'])})\s*জরুর[িী]"
)
_URGENT = re.compile(
    rf"{_LEFT}(?:{alternation(['urgently', 'urgent', 'জরুরি', 'জরুরী', 'আর্জেন্ট', 'today', 'tonight', 'ajke'])}){_RIGHT_WORD}"
    rf"|{_LEFT}আজ(?:কে|ই)?(?![{BN}])"
)


def extract_urgency(text: str) -> tuple[str | None, str | None, list[str]]:
    if m := _EMERGENCY.search(text):
        return "emergency", m.group(0), []
    if m := _URGENT.search(text):
        return "urgent", m.group(0), []
    return None, None, []


# ── Phone ───────────────────────────────────────────────────────────────────

_PHONE_CANDIDATE = re.compile(r"\+?\d[\d\s\-]{8,20}\d")
_BD_MOBILE = re.compile(r"(?:880)?(01[3-9]\d{8})")


def extract_phones(text: str) -> list[str]:
    phones: list[str] = []
    for m in _PHONE_CANDIDATE.finditer(text):
        digits = re.sub(r"\D", "", m.group(0))
        for hit in _BD_MOBILE.finditer(digits):
            if hit.group(1) not in phones:
                phones.append(hit.group(1))
    return phones


# ── Location hint (informational only; Laravel resolves hospital and district) ──

_PLACE_WORDS = re.compile(
    rf"(?:{alternation(['হাসপাতাল', 'হসপিটাল', 'মেডিকেল', 'ক্লিনিক', 'ইনস্টিটিউট', 'hospital', 'medical', 'clinic', 'institute'])})"
)
_SEGMENT_SPLIT = re.compile(r"[,\n।|;]")
_LABEL = re.compile(rf"^\s*(?:{alternation(['হাসপাতাল', 'স্থান', 'hospital', 'location', 'place'])})\s*[:ঃ]\s*")


def extract_location_text(original: str, normalized: str) -> str | None:
    """The first comma/line segment that names a place, cut from the ORIGINAL text."""
    start = 0
    for part in _SEGMENT_SPLIT.split(normalized):
        end = start + len(part)
        if _PLACE_WORDS.search(part):
            segment = original[start:end] if len(original) == len(normalized) else part
            segment = _LABEL.sub("", segment).strip(" .!:ঃ-")
            return segment[:120] or None
        start = end + 1
    return None


@dataclass
class RuleResult:
    blood_group: str | None = None
    units: int | None = None
    component_type: str | None = None
    urgency: str | None = None
    contact_numbers: list[str] = field(default_factory=list)
    location_text: str | None = None
    evidence: dict[str, str] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
