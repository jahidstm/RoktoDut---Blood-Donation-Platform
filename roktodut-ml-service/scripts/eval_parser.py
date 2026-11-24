"""Score the request parser against the hand-labelled sets in data/nlp/.

    python scripts/eval_parser.py --split dev            # while developing (optimistic)
    python scripts/eval_parser.py --split test           # held-out, report this one
    python scripts/eval_parser.py --split test --use-llm # needs GROQ_API_KEY

Blood group is scored separately because its errors are not equal: a WRONG group is
dangerous, an abstention (null) only costs the user a click.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from nlp import llm  # noqa: E402
from nlp.parser import parse_request_text  # noqa: E402

FIELDS = ["blood_group", "units", "component_type", "urgency", "contact_number"]


def load(split: str) -> list[dict]:
    path = ROOT / "data" / "nlp" / f"{split}.jsonl"
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def evaluate(rows: list[dict], client=None) -> dict:
    per_field = {f: {"correct": 0, "total": 0} for f in FIELDS}
    bg = {"correct": 0, "wrong": 0, "abstained": 0, "false_positive": 0, "true_null": 0}
    errors = []

    for row in rows:
        result = parse_request_text(row["text"], llm_client=client)
        gold = row["labels"]

        for f in FIELDS:
            pred = getattr(result, f)
            per_field[f]["total"] += 1
            if pred == gold[f]:
                per_field[f]["correct"] += 1
            else:
                errors.append({"id": row["id"], "field": f, "gold": gold[f], "pred": pred, "text": row["text"]})

        g, p = gold["blood_group"], result.blood_group
        if g is None and p is None:
            bg["true_null"] += 1
        elif g is None:
            bg["false_positive"] += 1
        elif p is None:
            bg["abstained"] += 1
        elif p == g:
            bg["correct"] += 1
        else:
            bg["wrong"] += 1

    predicted = bg["correct"] + bg["wrong"] + bg["false_positive"]
    stated = bg["correct"] + bg["wrong"] + bg["abstained"]

    return {
        "rows": len(rows),
        "accuracy": {f: round(v["correct"] / v["total"], 4) for f, v in per_field.items()},
        "blood_group": {
            **bg,
            "precision": round(bg["correct"] / predicted, 4) if predicted else None,
            "recall": round(bg["correct"] / stated, 4) if stated else None,
        },
        "errors": errors,
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--split", choices=["dev", "test"], default="dev")
    ap.add_argument("--use-llm", action="store_true")
    ap.add_argument("--show-errors", action="store_true")
    ap.add_argument("--save", type=str, help="write the report (without per-row errors) to this JSON file")
    args = ap.parse_args()

    client = None
    if args.use_llm:
        client = llm.client_from_env()
        if client is None:
            sys.exit("GROQ_API_KEY is not set; cannot run with --use-llm.")

    report = evaluate(load(args.split), client)
    report["split"] = args.split
    report["llm"] = bool(client)

    print(f"split={args.split} rows={report['rows']} llm={report['llm']}")
    for f, acc in report["accuracy"].items():
        print(f"  {f:16s} accuracy {acc:.3f}")
    b = report["blood_group"]
    print(
        f"  blood_group      precision {b['precision']}  recall {b['recall']}  "
        f"wrong={b['wrong']} false_positive={b['false_positive']} abstained={b['abstained']}"
    )

    if args.show_errors:
        for e in report["errors"]:
            print(f"  [{e['id']}] {e['field']}: gold={e['gold']!r} pred={e['pred']!r} | {e['text'][:90]!r}")

    if args.save:
        out = {k: v for k, v in report.items() if k != "errors"}
        Path(args.save).write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")


if __name__ == "__main__":
    main()
