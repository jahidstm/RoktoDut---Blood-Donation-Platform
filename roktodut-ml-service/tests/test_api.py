import pytest
from fastapi.testclient import TestClient


def donor(donor_id, distance_km, days=200, rate=0.5):
    return {
        "donor_id": donor_id,
        "distance_km": distance_km,
        "days_since_last_donation": days,
        "temporal_hour": 12,
        "is_weekend": False,
        "historical_response_rate": rate,
    }


def test_health_needs_no_key(client):
    r = client.get("/health")
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok"
    assert body["model_loaded"] is True
    assert body["parser"] == "rules"
    assert body["llm_enabled"] is False  # no GROQ_API_KEY in tests


def test_missing_key_header_is_rejected(client):
    r = client.post("/api/v1/rank-donors", json={"candidate_donors": [donor(1, 2)]})
    assert r.status_code == 422  # required header absent


def test_wrong_key_is_401(client):
    r = client.post(
        "/api/v1/rank-donors",
        json={"candidate_donors": [donor(1, 2)]},
        headers={"X-API-Key": "wrong"},
    )
    assert r.status_code == 401


def test_old_public_default_key_no_longer_works(client):
    r = client.post(
        "/api/v1/rank-donors",
        json={"candidate_donors": [donor(1, 2)]},
        headers={"X-API-Key": "ROKTODUT_AI_SECRET"},
    )
    assert r.status_code == 401


def test_invalid_payload_is_422(client, auth):
    r = client.post("/api/v1/rank-donors", json={"candidate_donors": []}, headers=auth)
    assert r.status_code == 422
    bad = donor(1, -5)
    r = client.post("/api/v1/rank-donors", json={"candidate_donors": [bad]}, headers=auth)
    assert r.status_code == 422


def test_ranking_is_sorted_and_ranks_are_consecutive(client, auth):
    donors = [donor(1, 18.0, rate=0.05), donor(2, 0.5, rate=0.9), donor(3, 6.0, rate=0.4)]
    r = client.post("/api/v1/rank-donors", json={"candidate_donors": donors}, headers=auth)
    assert r.status_code == 200
    rows = r.json()
    assert [x["rank"] for x in rows] == [1, 2, 3]
    scores = [x["probability_score"] for x in rows]
    assert scores == sorted(scores, reverse=True)
    assert {x["donor_id"] for x in rows} == {1, 2, 3}


def test_parse_v2_works_without_any_llm(client, auth):
    r = client.post(
        "/api/v2/parse-request",
        json={"text": "জরুরি ভিত্তিতে A+ রক্ত, ২ ব্যাগ, ঢাকা মেডিকেল, ০১৭০০০০০০০১"},
        headers=auth,
    )
    assert r.status_code == 200
    body = r.json()
    assert (body["blood_group"], body["units"], body["urgency"], body["contact_number"]) == ("A+", 2, "urgent", "01700000001")
    assert body["source"]["blood_group"] == "rules"
    assert body["llm_used"] is False


def test_parse_v2_says_so_when_llm_is_requested_but_missing(client, auth):
    r = client.post("/api/v2/parse-request", json={"text": "রক্ত লাগবে", "use_llm": True}, headers=auth)
    assert r.status_code == 200
    assert "LLM requested but not configured; rules only" in r.json()["warnings"]


def test_parse_v2_needs_the_key_and_valid_input(client, auth):
    assert client.post("/api/v2/parse-request", json={"text": "A+ blood"}, headers={"X-API-Key": "wrong"}).status_code == 401
    assert client.post("/api/v2/parse-request", json={"text": "ab"}, headers=auth).status_code == 422
    assert client.post("/api/v2/parse-request", json={"text": "x" * 2001}, headers=auth).status_code == 422
    assert client.post("/api/v2/parse-request", json={"text": "A+ blood", "extra": 1}, headers=auth).status_code == 422


def test_old_v1_endpoint_is_gone(client, auth):
    assert client.post("/api/v1/parse-request", json={"text": "A+ blood"}, headers=auth).status_code == 404


def test_service_refuses_to_start_without_a_key(monkeypatch):
    for name in ("ROKTODUT_ML_API_KEY", "ROKTODUT_AI_SECRET", "ROKTODUT_API_KEY"):
        monkeypatch.setenv(name, "")
    import main

    with pytest.raises(RuntimeError, match="API key"):
        with TestClient(main.app):
            pass
