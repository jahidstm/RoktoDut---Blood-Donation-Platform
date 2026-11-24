"""Text normalisation shared by the rules and the patterns they are compiled from.

Bangla has more than one valid Unicode spelling for some letters (য় can be one code
point or য + nukta), so both the incoming text and every pattern go through the same
`normalize()` before matching.

Special characters are written as chr(0x...) on purpose: several of them (dashes,
variation selectors) are invisible or look identical in an editor.
"""

from __future__ import annotations

import re
import unicodedata


def _chars(*codepoints: int) -> str:
    return "".join(chr(c) for c in codepoints)


def _char_range(first: int, last: int) -> str:
    return f"{re.escape(chr(first))}-{re.escape(chr(last))}"


_BANGLA_DIGITS = str.maketrans(_chars(*range(0x09E6, 0x09F0)), "0123456789")

# Hyphens U+2010..U+2015, minus U+2212, small/fullwidth hyphen-minus.
_DASHES = re.compile("[" + _char_range(0x2010, 0x2015) + re.escape(_chars(0x2212, 0xFE58, 0xFE63, 0xFF0D)) + "]")
# Small and fullwidth plus.
_PLUS = re.compile("[" + re.escape(_chars(0xFE62, 0xFF0B)) + "]")
# Emoji presentation selectors that follow 🅰 🅱 🅾.
_VARIATION_SELECTORS = re.compile("[" + re.escape(_chars(0xFE0E, 0xFE0F)) + "]")

# Facebook posts often write the group with emoji letters: 🅰️+ 🅱️- 🆎+ 🅾️-.
# A leading space keeps the letter from gluing onto the previous word.
_EMOJI_ABO = {
    chr(0x1F18E): " ab",  # 🆎
    chr(0x1F170): " a",   # 🅰
    chr(0x1F171): " b",   # 🅱
    chr(0x1F17E): " o",   # 🅾
}

# The Bangla block, used as "word character" in boundary checks.
BN = _char_range(0x0980, 0x09FF)


def normalize(text: str) -> str:
    """NFC, emoji blood-group letters to text, Bangla digits to ASCII, one dash and plus, lowercase."""
    text = unicodedata.normalize("NFC", text)
    text = _VARIATION_SELECTORS.sub("", text)
    for emoji, letters in _EMOJI_ABO.items():
        text = text.replace(emoji, letters)
    text = text.translate(_BANGLA_DIGITS)
    text = _DASHES.sub("-", text)
    text = _PLUS.sub("+", text)
    return text.lower()


def alternation(words: list[str]) -> str:
    """Regex alternation of normalised words, longest first so 'ab' wins over 'a'."""
    forms = {re.escape(normalize(w)) for w in words}
    return "|".join(sorted(forms, key=len, reverse=True))
