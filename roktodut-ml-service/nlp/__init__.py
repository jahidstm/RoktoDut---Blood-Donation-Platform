"""Blood-request text parsing: rules first, an optional LLM only for what the rules miss."""

from nlp.parser import ParseResult, parse_request_text

__all__ = ["ParseResult", "parse_request_text"]
