import os
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

# Make `main` importable when pytest is run from the repo root or this folder.
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

TEST_KEY = "test-key-not-a-real-secret"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv("ROKTODUT_ML_API_KEY", TEST_KEY)
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    import main

    with TestClient(main.app) as c:
        yield c


@pytest.fixture()
def auth():
    return {"X-API-Key": TEST_KEY}
