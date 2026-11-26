# Donor response model report (simulated-nonlinear data)

Trained 2026-11-26T04:40:59+00:00. Labelled rows: 34583 (train 20720, validation 5219, test 8644). Acceptance rate 8.7%.

> **Simulated data.** These numbers describe the simulator's assumptions, not real donors. See `ranking/simulate.py`.

Chosen on validation: **random_forest** (validation NDCG@10: logistic_regression 0.5659, random_forest 0.669, lightgbm 0.6598).

## Test set (466 later requests with at least one accepter)

| ranking | hit@3 | hit@5 | NDCG@10 | MRR |
|---|---|---|---|---|
| model | 0.6545 | 0.8412 | 0.6088 | 0.513 |
| random | 0.3906 | 0.573 | 0.4314 | 0.3456 |
| nearest_first | 0.515 | 0.7403 | 0.5341 | 0.4122 |
| response_rate_first | 0.3498 | 0.5944 | 0.4417 | 0.3089 |
| current_rules | 0.4764 | 0.7039 | 0.5102 | 0.3844 |

Classification view: ROC-AUC 0.7311, average precision 0.172 (base rate 0.0838), Brier 0.073.

## Promotion gate

Model minus nearest_first on NDCG@10: 0.0746 (95% bootstrap interval 0.0466 to 0.1037). Needed: at least 0.01 with the interval above zero, on at least 50 test requests.

**Promoted: yes.**

Even when promoted, the service only uses a model trained on simulated data if `ROKTODUT_ALLOW_SIMULATED_MODEL=true` is set (demos only).
