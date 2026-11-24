"""Optional LLM fallback. Only asked about fields the rules could not fill.

Safety rules:
- Phone numbers are masked before the text leaves this service.
- The model must quote the words it relied on for the blood group; if that quote is not
  literally in the message, the answer is discarded (no invented blood groups).
- Anything the LLM supplies is marked source="llm" and forces human review.
"""

from __future__ import annotations

import json
import os
import re
from typing import Any, Protocol

from nlp.normalize import normalize

VALID_GROUPS = {"A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"}
VALID_COMPONENTS = {"whole_blood", "packed_rbc", "platelets", "plasma"}
VALID_URGENCY = {"urgent", "emergency"}

SYSTEM_PROMPT = """You extract fields from Bangladeshi blood-request messages (Bangla, English or Banglish).
Return ONLY a JSON object with these keys (use null when the message does not say it):
{
  "blood_group": "A+|A-|B+|B-|AB+|AB-|O+|O-" or null,
  "blood_group_quote": the exact words from the message that state the blood group, or null,
  "units": integer number of bags/units/donors or null,
  "component_type": "whole_blood|packed_rbc|platelets|plasma" or null,
  "urgency": "emergency" (emergency / immediately / very urgent) | "urgent" (urgent / today / tonight) | null
}
Rules: never guess a blood group. If only ABO is given without Rh, or two different groups are given, use null.
No markdown, no explanation, no other keys."""

_PHONE_LIKE = re.compile(r"(?:\+?৮৮০|\+?880)?[০-৯0-9][০-৯0-9\s\-]{8,16}[০-৯0-9]")


class ChatClient(Protocol):
    def complete_json(self, system: str, user: str) -> str: ...


class GroqChatClient:
    """Thin wrapper so tests can pass a fake client instead."""

    def __init__(self, api_key: str, model: str):
        from groq import Groq

        self._client = Groq(api_key=api_key)
        self._model = model

    def complete_json(self, system: str, user: str) -> str:
        completion = self._client.chat.completions.create(
            model=self._model,
            temperature=0,
            response_format={"type": "json_object"},
            messages=[{"role": "system", "content": system}, {"role": "user", "content": user}],
        )
        return (completion.choices[0].message.content or "") if completion.choices else ""


def client_from_env() -> ChatClient | None:
    key = os.getenv("GROQ_API_KEY", "").strip()
    if not key:
        return None
    return GroqChatClient(key, os.getenv("GROQ_MODEL", "openai/gpt-oss-20b").strip() or "openai/gpt-oss-20b")


def mask_phones(text: str) -> str:
    return _PHONE_LIKE.sub("[PHONE]", text)


def ask(client: ChatClient, text: str) -> dict[str, Any]:
    """Return only validated fields. Invalid or unsupported answers are dropped, never repaired."""
    raw = client.complete_json(SYSTEM_PROMPT, mask_phones(text))
    data = json.loads(raw or "{}")
    if not isinstance(data, dict):
        return {}

    out: dict[str, Any] = {}

    group = data.get("blood_group")
    quote = data.get("blood_group_quote")
    if group in VALID_GROUPS and isinstance(quote, str) and quote.strip():
        # The quote must really be in the message (after the same normalisation).
        if normalize(quote.strip()) in normalize(text):
            out["blood_group"] = group
            out["blood_group_quote"] = quote.strip()

    units = data.get("units")
    if isinstance(units, int) and not isinstance(units, bool) and 1 <= units <= 20:
        out["units"] = units

    if data.get("component_type") in VALID_COMPONENTS:
        out["component_type"] = data["component_type"]

    if data.get("urgency") in VALID_URGENCY:
        out["urgency"] = data["urgency"]

    return out
