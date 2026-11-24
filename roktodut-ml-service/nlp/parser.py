"""Combine the rules and the optional LLM into one result the form can use."""

from __future__ import annotations

import logging
from typing import Literal

from pydantic import BaseModel, Field

from nlp import llm, rules
from nlp.normalize import normalize

logger = logging.getLogger("roktodut.nlp")

BloodGroup = Literal["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"]
Component = Literal["whole_blood", "packed_rbc", "platelets", "plasma"]
Urgency = Literal["urgent", "emergency"]
Source = Literal["rules", "llm"]

FIELDS = ("blood_group", "units", "component_type", "urgency")


class ParseResult(BaseModel):
    """Every field is optional: null means "not stated", never a default."""

    blood_group: BloodGroup | None = None
    units: int | None = Field(default=None, ge=1, le=20)
    component_type: Component | None = None
    urgency: Urgency | None = None
    contact_number: str | None = None
    other_contact_numbers: list[str] = Field(default_factory=list)
    location_text: str | None = None
    source: dict[str, Source] = Field(default_factory=dict, description="Which method filled each field")
    evidence: dict[str, str] = Field(default_factory=dict, description="The words each field was read from")
    warnings: list[str] = Field(default_factory=list)
    missing: list[str] = Field(default_factory=list)
    needs_review: bool = True
    llm_used: bool = False
    parser_version: str = "2.0"


def parse_request_text(text: str, llm_client: llm.ChatClient | None = None) -> ParseResult:
    norm = normalize(text)
    result = ParseResult()

    for name, extractor in (
        ("blood_group", rules.extract_blood_group),
        ("units", rules.extract_units),
        ("component_type", rules.extract_component),
        ("urgency", rules.extract_urgency),
    ):
        value, evidence, warnings = extractor(norm)
        result.warnings.extend(warnings)
        if value is not None:
            setattr(result, name, value)
            result.source[name] = "rules"
            if evidence:
                result.evidence[name] = evidence

    phones = rules.extract_phones(norm)
    if phones:
        result.contact_number = phones[0]
        result.other_contact_numbers = phones[1:]
        result.source["contact_number"] = "rules"

    result.location_text = rules.extract_location_text(text, norm)

    # A rule that saw two different blood groups (or no Rh) has made a decision: the
    # LLM must not override it with a guess. Ask the LLM only about fields that are
    # missing without any conflict.
    conflicted_bg = any(w.startswith(("multiple blood groups", "blood group given without")) for w in result.warnings)
    wanted = [f for f in FIELDS if getattr(result, f) is None and not (f == "blood_group" and conflicted_bg)]

    if llm_client is not None and wanted:
        try:
            answer = llm.ask(llm_client, text)
            result.llm_used = True
        except Exception:  # network, quota, bad JSON: the rule result still stands
            logger.exception("LLM fallback failed")
            answer = {}
            result.warnings.append("LLM fallback unavailable")

        for f in wanted:
            if f in answer:
                setattr(result, f, answer[f])
                result.source[f] = "llm"
                if f == "blood_group":
                    result.evidence[f] = answer.get("blood_group_quote", "")
                    result.warnings.append("blood group read by the LLM: please confirm")

    result.missing = [f for f in (*FIELDS, "contact_number") if getattr(result, f) is None]
    # A human always confirms before saving; this flag says when they must look harder.
    result.needs_review = (
        result.blood_group is None
        or result.source.get("blood_group") == "llm"
        or bool(result.warnings)
    )
    return result
