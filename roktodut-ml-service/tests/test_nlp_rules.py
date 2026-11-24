import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from nlp.normalize import normalize  # noqa: E402
from nlp.parser import parse_request_text  # noqa: E402
from nlp.rules import extract_blood_group, extract_phones, extract_units  # noqa: E402


def bg(text):
    return extract_blood_group(normalize(text))[0]


def units(text):
    return extract_units(normalize(text))[0]


@pytest.mark.parametrize(
    "text, expected",
    [
        ("A+ রক্ত লাগবে", "A+"),
        ("ও পজিটিভ রক্ত", "O+"),
        ("এবি নেগেটিভ", "AB-"),
        ("বি পজেটিভ", "B+"),                # common misspelling
        ("B+ve", "B+"),
        ("o-ve", "O-"),
        ("A (+)", "A+"),
        ("B(-)", "B-"),
        ("০+ রক্ত", "O+"),                  # zero typed for O, in Bangla digits
        ("Bpos blood", "B+"),
        ("#A_positive", "A+"),
        ("O Rh positive", "O+"),
        ("O Rh(D) negative", "O-"),
        ("রক্তের গ্রুপ A, পজিটিভ", "A+"),
        ("AB negative platelet", "AB-"),
        ("B+ and B+ again", "B+"),          # the same group repeated is not a conflict
        ("\U0001F170\uFE0F+ রক্ত লাগবে", "A+"),   # emoji letters, as on Facebook
        ("\U0001F171\uFE0F- blood", "B-"),
        ("\U0001F18E+", "AB+"),
        ("রক্ত\U0001F17E\uFE0F-ve", "O-"),
    ],
)
def test_blood_group_forms(text, expected):
    assert bg(text) == expected


@pytest.mark.parametrize(
    "text",
    [
        "B group er blood lagbe",           # no Rh: never guess
        "A+ অথবা O+",                       # two groups: never pick one
        "ও পজিটিভ/এ পজিটিভ",
        "Hb: 6, age 45",                    # 'b' inside a word
        "call 0170-0000-036",               # zero followed by a dash inside a phone number
        "রাজশাহী মেডিকেল",
        "range 0-5 years",
        "for a burn patient",               # English article 'a'
        "স্যার সলিমুল্লাহ মেডিকেল কলেজ ও মিটফোর্ড",  # Bangla 'ও' meaning "and"
        "এক্সিডেন্ট",
    ],
)
def test_blood_group_abstains_instead_of_guessing(text):
    assert bg(text) is None


def test_warnings_explain_abstentions():
    assert "without Rh" in extract_blood_group(normalize("B group er blood"))[2][0]
    assert "multiple" in extract_blood_group(normalize("A+ or B+"))[2][0]


@pytest.mark.parametrize(
    "text, expected",
    [
        ("২ ব্যাগ", 2), ("2 bags", 2), ("দুই ব্যাগ", 2), ("two bags", 2), ("একটি ব্যাগ", 1),
        ("২টি ব্যাগ", 2), ("2 ta bag", 2), ("ব্যাগ: ৩টি", 3), ("Bag 2.", 2), ("ব্যাগঃ এক\n", 1),
        ("৩ জন রক্তদাতা", 3), ("দুইজন ডোনার", 2), ("1 pint", 1), ("10 bags", 10),
    ],
)
def test_units_forms(text, expected):
    assert units(text) == expected


@pytest.mark.parametrize(
    "text",
    ["৫০০ মিলি", "১০ তারিখে", "রাত ৮টার মধ্যে", "বয়স ১২, ওজন ৩০ কেজি", "এক্সিডেন্ট", "Hb: 6", "two different patients"],
)
def test_units_ignore_numbers_that_are_not_quantities(text):
    assert units(text) is None


def test_units_conflict_and_implausible_values():
    assert units("২ ব্যাগ ... না, ৩ ব্যাগ") is None
    assert units("25 bags") is None


@pytest.mark.parametrize(
    "text, expected",
    [
        ("01700000001", ["01700000001"]),
        ("+8801800000003", ["01800000003"]),
        ("০১৫০০-০০০০০৫", ["01500000005"]),
        ("017 1100 0109", ["01711000109"]),
        ("+880 1700-000024", ["01700000024"]),
        ("01711000146 / 01711000147", ["01711000146", "01711000147"]),
        ("২ ০১৭১১০০০১৬২", ["01711000162"]),    # a stray digit glued in front
        ("12345678901", []),                    # not a mobile number
    ],
)
def test_phones(text, expected):
    assert extract_phones(normalize(text)) == expected


def test_full_parse_and_review_flag():
    r = parse_request_text("জরুরি ভিত্তিতে A+ রক্ত, ২ ব্যাগ, ঢাকা মেডিকেল কলেজ হাসপাতাল, ০১৭০০০০০০০১")
    assert (r.blood_group, r.units, r.urgency, r.contact_number) == ("A+", 2, "urgent", "01700000001")
    assert r.source["blood_group"] == "rules"
    assert r.missing == ["component_type"]
    assert r.needs_review is False
    assert "ঢাকা মেডিকেল কলেজ হাসপাতাল" in (r.location_text or "")

    unclear = parse_request_text("রক্ত লাগবে, A+ বা O+")
    assert unclear.blood_group is None and unclear.needs_review is True


def test_nfc_variants_of_bangla_letters_match():
    # য় as one code point (U+09DF) and as য + nukta (U+09AF U+09BC) must parse the same.
    composed = "য়"
    decomposed = "য়"
    for ya in (composed, decomposed):
        assert units(f"ছ{ya} ব্যাগ") == 6
