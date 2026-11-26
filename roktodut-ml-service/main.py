from __future__ import annotations

import asyncio
import hmac
import logging
import os
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

import sys

from dotenv import load_dotenv

# Make the local `nlp` package importable however uvicorn is started.
sys.path.insert(0, str(Path(__file__).resolve().parent))

# Load the parent Laravel .env file automatically
env_path = Path(__file__).resolve().parent.parent / '.env'
load_dotenv(dotenv_path=env_path)

import joblib
import pandas as pd
from fastapi import Depends, FastAPI, Header, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field

from nlp import llm as nlp_llm
from nlp.parser import ParseResult, parse_request_text
from ranking.features import model_matrix

logger = logging.getLogger("roktodut.ml")

DEFAULT_MODEL_PATH = Path(__file__).resolve().parent / "models" / "donor_ranker.joblib"


class CandidateDonor(BaseModel):
    donor_id: int = Field(..., gt=0)
    distance_km: float = Field(..., ge=0)
    days_since_last_donation: int = Field(..., ge=0)
    temporal_hour: int = Field(..., ge=0, le=23)
    is_weekend: bool
    historical_response_rate: float = Field(..., ge=0.0, le=1.0)


class RankingRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    request_details: dict[str, Any] | None = None
    candidate_donors: list[CandidateDonor] = Field(..., min_length=1)


class RankedDonor(BaseModel):
    donor_id: int
    probability_score: float
    rank: int


class ParseRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    text: str = Field(..., min_length=3, max_length=2000)
    use_llm: bool = Field(
        default=False,
        description="Ask the LLM about fields the rules could not fill. Off by default: it is slower and sends text to a third party.",
    )


def _resolve_expected_api_key() -> str:
    # ROKTODUT_ML_API_KEY is shared with Laravel (config/services.php). The legacy names
    # are accepted so an existing .env keeps working. There is deliberately no default:
    # this repository is public, so a built-in key would be public too.
    for name in ("ROKTODUT_ML_API_KEY", "ROKTODUT_AI_SECRET", "ROKTODUT_API_KEY"):
        value = os.getenv(name, "").strip()
        if value:
            return value
    raise RuntimeError(
        "ML API key is not configured. Set ROKTODUT_ML_API_KEY in the environment or the Laravel .env."
    )


def _env_flag(name: str) -> bool:
    return os.getenv(name, "").strip().lower() in ("1", "true", "yes")


def _load_ranker() -> dict[str, Any]:
    """Load the ranker artifact and decide whether it may be used.

    A model is served only if scripts/train_ranker.py promoted it (it beat the rule-based
    rankings on later, unseen requests) AND it was trained on real data. A model trained on
    simulated data is served only when ROKTODUT_ALLOW_SIMULATED_MODEL=true (demos). In every
    other case /api/v1/rank-donors answers 503 and Laravel keeps its rule-based order.
    """
    path = Path(os.getenv("ROKTODUT_RANKER_PATH", "") or DEFAULT_MODEL_PATH)
    state: dict[str, Any] = {"model": None, "servable": False, "reason": "", "info": {}}

    if not path.exists():
        state["reason"] = "no model file; run scripts/train_ranker.py"
        return state

    artifact = joblib.load(path)
    if not isinstance(artifact, dict) or "promoted" not in artifact or "feature_columns" not in artifact:
        state["reason"] = "old model format without an evaluation; retrain with scripts/train_ranker.py"
        return state

    state["model"] = artifact["model"]
    state["info"] = {
        "model_name": artifact.get("model_name"),
        "data_source": artifact.get("data_source"),
        "trained_at_utc": artifact.get("trained_at_utc"),
        "promoted": bool(artifact["promoted"]),
    }

    if not artifact["promoted"]:
        state["reason"] = "model did not beat the rule-based ranking; rules are used"
    elif artifact.get("data_source") != "real" and not _env_flag("ROKTODUT_ALLOW_SIMULATED_MODEL"):
        state["reason"] = "model was trained on simulated data; set ROKTODUT_ALLOW_SIMULATED_MODEL=true to use it in a demo"
    else:
        state["servable"] = True

    return state


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.ranker = _load_ranker()
    app.state.api_key = _resolve_expected_api_key()
    app.state.llm_client = nlp_llm.client_from_env()
    yield


app = FastAPI(
    title="RoktoDut ML Service",
    version="1.1.0",
    lifespan=lifespan,
)


def require_api_key(x_api_key: str = Header(..., alias="X-API-Key")) -> None:
    expected = app.state.api_key
    if not hmac.compare_digest(x_api_key.encode(), expected.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key",
        )


@app.get("/health")
async def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "ranker": {
            "servable": app.state.ranker["servable"],
            "reason": app.state.ranker["reason"],
            **app.state.ranker["info"],
        },
        "parser": "rules",
        "llm_enabled": app.state.llm_client is not None,
    }


@app.post("/api/v1/rank-donors", response_model=list[RankedDonor])
async def rank_donors(
    payload: RankingRequest,
    _: None = Depends(require_api_key),
) -> list[RankedDonor]:
    ranker = app.state.ranker
    if not ranker["servable"]:
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail=ranker["reason"])

    raw = pd.DataFrame([
        {
            "distance_km": d.distance_km,
            "days_since_last_donation": d.days_since_last_donation,
            "temporal_hour": d.temporal_hour,
            "is_weekend": int(d.is_weekend),
            "historical_response_rate": d.historical_response_rate,
        }
        for d in payload.candidate_donors
    ])
    probabilities = ranker["model"].predict_proba(model_matrix(raw))[:, 1]

    ranked = sorted(
        zip(payload.candidate_donors, probabilities, strict=True),
        key=lambda item: float(item[1]),
        reverse=True,
    )

    return [
        RankedDonor(
            donor_id=item[0].donor_id,
            probability_score=round(float(item[1]), 6),
            rank=idx + 1,
        )
        for idx, item in enumerate(ranked)
    ]


@app.post("/api/v2/parse-request", response_model=ParseResult)
async def parse_request(
    payload: ParseRequest,
    _: None = Depends(require_api_key),
) -> ParseResult:
    """Rules first; the LLM (if configured and asked for) only fills what the rules left empty."""
    client = app.state.llm_client if payload.use_llm else None
    result = await asyncio.to_thread(parse_request_text, payload.text, client)
    if payload.use_llm and app.state.llm_client is None:
        result.warnings.append("LLM requested but not configured; rules only")
    return result
