"""The LLM fallback, tested with a fake client (no network, no key)."""

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from nlp import llm  # noqa: E402
from nlp.parser import parse_request_text  # noqa: E402


class FakeClient:
    def __init__(self, answer: dict | str):
        self.answer = answer
        self.calls: list[str] = []

    def complete_json(self, system: str, user: str) -> str:
        self.calls.append(user)
        return self.answer if isinstance(self.answer, str) else json.dumps(self.answer)


class BrokenClient:
    def complete_json(self, system: str, user: str) -> str:
        raise TimeoutError("network down")


def test_llm_fills_only_missing_fields_and_forces_review():
    text = "রক্ত দরকার, গ্রুপ বি নেগেটিভি, ২ ব্যাগ"  # odd spelling the rules do not know
    client = FakeClient({"blood_group": "B-", "blood_group_quote": "বি নেগেটিভি", "units": 9, "urgency": "urgent"})

    r = parse_request_text(text, llm_client=client)

    assert r.blood_group == "B-" and r.source["blood_group"] == "llm"
    assert r.units == 2 and r.source["units"] == "rules", "rules win; the LLM's 9 is ignored"
    assert r.urgency == "urgent" and r.source["urgency"] == "llm"
    assert r.needs_review is True and r.llm_used is True


def test_llm_blood_group_without_a_real_quote_is_discarded():
    client = FakeClient({"blood_group": "O-", "blood_group_quote": "O negative"})  # not in the text

    r = parse_request_text("রক্ত দরকার, ২ ব্যাগ", llm_client=client)

    assert r.blood_group is None


def test_llm_is_not_asked_to_override_a_conflict():
    client = FakeClient({"blood_group": "A+", "blood_group_quote": "A+"})

    r = parse_request_text("A+ অথবা O+ লাগবে", llm_client=client)

    assert r.blood_group is None, "the rules saw two groups; the LLM must not pick one"


def test_phone_numbers_never_reach_the_llm():
    client = FakeClient({})
    parse_request_text("রক্ত লাগবে, যোগাযোগ ০১৭১১-০০০১৪৪ বা +8801800000003", llm_client=client)

    sent = client.calls[0]
    assert "০১৭১১" not in sent and "01800000003" not in sent
    assert sent.count("[PHONE]") == 2


def test_llm_failure_keeps_the_rule_result():
    r = parse_request_text("A+ ২ ব্যাগ", llm_client=BrokenClient())

    assert r.blood_group == "A+" and r.units == 2
    assert "LLM fallback unavailable" in r.warnings


def test_invalid_llm_values_are_dropped_not_repaired():
    client = FakeClient({"blood_group": "A positive", "units": "2", "component_type": "blood", "urgency": "high"})

    r = parse_request_text("রক্ত লাগবে", llm_client=client)

    assert (r.blood_group, r.units, r.component_type, r.urgency) == (None, None, None, None)


def test_garbage_json_is_handled():
    r = parse_request_text("রক্ত লাগবে", llm_client=FakeClient("not json at all"))
    assert r.blood_group is None and "LLM fallback unavailable" in r.warnings


def test_no_llm_call_when_rules_found_everything():
    client = FakeClient({})
    parse_request_text("A+ platelet ২ ব্যাগ জরুরি", llm_client=client)
    assert client.calls == []


def test_mask_phones_keeps_other_numbers():
    assert llm.mask_phones("২ ব্যাগ, ০১৭০০০০০০০১") == "২ ব্যাগ, [PHONE]"
