import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

# Make `main` importable when pytest is run from the repo root or this folder.
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

TEST_KEY = "test-key-not-a-real-secret"


@pytest.fixture(scope="session")
def demo_ranker_path(tmp_path_factory):
    """A small model trained on simulated data, saved as promoted, for API tests only."""
    import joblib
    from sklearn.linear_model import LogisticRegression

    from ranking.features import MODEL_FEATURES, model_matrix, training_frame
    from ranking.simulate import simulate

    df = training_frame(simulate(n_requests=300, n_donors=300, signal="realistic", seed=7))
    model = LogisticRegression(max_iter=1000).fit(model_matrix(df), df["label"])
    path = tmp_path_factory.mktemp("ranker") / "ranker.joblib"
    joblib.dump({"model": model, "model_name": "logistic_regression", "feature_columns": MODEL_FEATURES,
                 "promoted": True, "data_source": "simulated-test", "trained_at_utc": "test"}, path)
    return path


def _client(monkeypatch, ranker_path=None, allow_simulated=False):
    monkeypatch.setenv("ROKTODUT_ML_API_KEY", TEST_KEY)
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    monkeypatch.setenv("ROKTODUT_RANKER_PATH", str(ranker_path or ""))
    monkeypatch.setenv("ROKTODUT_ALLOW_SIMULATED_MODEL", "true" if allow_simulated else "")
    import main

    return TestClient(main.app)


@pytest.fixture()
def client(monkeypatch, demo_ranker_path):
    """Service with a usable (simulated, demo-allowed) ranker."""
    with _client(monkeypatch, demo_ranker_path, allow_simulated=True) as c:
        yield c


@pytest.fixture()
def make_client(monkeypatch):
    """Build a client with a chosen ranker file and demo flag."""
    return lambda path=None, allow=False: _client(monkeypatch, path, allow)


@pytest.fixture()
def auth():
    return {"X-API-Key": TEST_KEY}
