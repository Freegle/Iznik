#!/usr/bin/env python3
"""Baseline accuracy test using existing Ollama models with prompt engineering.

Tests moderation decisions (approve/reject) and subject corrections against
the held-out test set. Provides progress indicators and ETA.

Usage:
    python baseline_accuracy_test.py [--model llama3.2:3b] [--limit 200] [--task moderation]
"""

import json
import os
import sys
import time
import argparse
import re
from datetime import datetime, timedelta

try:
    import urllib.request
    import urllib.error
except ImportError:
    pass

OLLAMA_HOST = "http://172.30.224.1:11434"
DATA_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "data")
RESULTS_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "results")


def ollama_generate(model, prompt, system_prompt="", temperature=0.1):
    """Call Ollama generate API."""
    payload = json.dumps({
        "model": model,
        "prompt": prompt,
        "system": system_prompt,
        "stream": False,
        "options": {
            "temperature": temperature,
            "num_ctx": 2048,
        }
    }).encode("utf-8")

    req = urllib.request.Request(
        f"{OLLAMA_HOST}/api/generate",
        data=payload,
        headers={"Content-Type": "application/json"},
    )

    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            return result.get("response", ""), result.get("total_duration", 0)
    except (urllib.error.URLError, TimeoutError) as e:
        return f"ERROR: {e}", 0


def test_moderation(model, limit=200):
    """Test moderation decisions against test set."""
    test_file = os.path.join(DATA_DIR, "moderation_test.jsonl")
    if not os.path.exists(test_file):
        print(f"ERROR: {test_file} not found")
        return

    with open(test_file) as f:
        examples = [json.loads(line) for line in f]

    if limit and limit < len(examples):
        # Sample evenly from approve/reject
        approvals = [e for e in examples if e["meta"]["action"] == "approve"]
        rejections = [e for e in examples if e["meta"]["action"] == "reject"]
        n_each = limit // 2
        import random
        random.seed(42)
        examples = random.sample(approvals, min(n_each, len(approvals))) + \
                   random.sample(rejections, min(n_each, len(rejections)))
        random.shuffle(examples)

    # Fine-tuned models have their own system prompt baked in via the Modelfile.
    # Sending an additional system prompt conflicts and causes wrong behaviour.
    # Only use a system prompt for base models (ones without "freegle" in the name).
    if "freegle" in model.lower():
        system_prompt = ""
    else:
        system_prompt = (
            "You are a Freegle community moderator. Freegle is a UK reuse network where people "
            "give and receive items for free. Review each post and respond with either APPROVE or REJECT "
            "on the first line, followed by a brief reason.\n\n"
            "Common rejection reasons: duplicate post, blank/empty message, out of area, "
            "non-landfill items (weapons, medicines, alcohol), selling language, too vague, "
            "repeat posted too soon.\n\n"
            "Most posts should be APPROVED - only reject for clear rule violations."
        )

    correct = 0
    total = 0
    true_pos = 0  # correctly rejected
    false_pos = 0  # rejected but should approve
    true_neg = 0  # correctly approved
    false_neg = 0  # approved but should reject
    times = []
    results = []

    print(f"\n{'='*60}")
    print(f"MODERATION BASELINE TEST")
    print(f"Model: {model}")
    print(f"Test examples: {len(examples)}")
    print(f"Started: {datetime.now().strftime('%H:%M:%S')}")
    print(f"{'='*60}\n")

    for i, example in enumerate(examples):
        expected_action = example["meta"]["action"]
        prompt = example["instruction"]

        start = time.time()
        response, duration_ns = ollama_generate(model, prompt, system_prompt)
        elapsed = time.time() - start
        times.append(elapsed)

        # Parse response
        first_line = response.strip().split("\n")[0].upper()
        if "REJECT" in first_line:
            predicted = "reject"
        elif "APPROVE" in first_line:
            predicted = "approve"
        else:
            predicted = "unknown"

        is_correct = predicted == expected_action
        if is_correct:
            correct += 1
        total += 1

        if expected_action == "reject" and predicted == "reject":
            true_pos += 1
        elif expected_action == "approve" and predicted == "reject":
            false_pos += 1
        elif expected_action == "approve" and predicted == "approve":
            true_neg += 1
        elif expected_action == "reject" and predicted == "approve":
            false_neg += 1

        results.append({
            "msg_id": example["meta"]["msg_id"],
            "expected": expected_action,
            "predicted": predicted,
            "correct": is_correct,
            "response_preview": response[:200],
            "elapsed_sec": round(elapsed, 1),
        })

        # Progress indicator
        avg_time = sum(times) / len(times)
        remaining = (len(examples) - total) * avg_time
        eta = datetime.now() + timedelta(seconds=remaining)
        accuracy = correct / total * 100

        print(
            f"[{total}/{len(examples)}] "
            f"{'✓' if is_correct else '✗'} "
            f"expected={expected_action:7s} predicted={predicted:7s} "
            f"acc={accuracy:.1f}% "
            f"ETA={eta.strftime('%H:%M:%S')} "
            f"({elapsed:.1f}s)"
        )
        sys.stdout.flush()

    # Summary
    accuracy = correct / total * 100 if total > 0 else 0
    precision = true_pos / (true_pos + false_pos) * 100 if (true_pos + false_pos) > 0 else 0
    recall = true_pos / (true_pos + false_neg) * 100 if (true_pos + false_neg) > 0 else 0
    f1 = 2 * precision * recall / (precision + recall) if (precision + recall) > 0 else 0

    print(f"\n{'='*60}")
    print(f"MODERATION RESULTS - {model}")
    print(f"{'='*60}")
    print(f"Accuracy:  {accuracy:.1f}% ({correct}/{total})")
    print(f"Precision: {precision:.1f}% (of predicted rejects, how many were correct)")
    print(f"Recall:    {recall:.1f}% (of actual rejects, how many were caught)")
    print(f"F1 Score:  {f1:.1f}%")
    print(f"")
    print(f"Confusion matrix:")
    print(f"                  Predicted")
    print(f"                  Approve  Reject")
    print(f"  Actual Approve  {true_neg:>7d}  {false_pos:>6d}")
    print(f"  Actual Reject   {false_neg:>7d}  {true_pos:>6d}")
    print(f"")
    print(f"Avg inference time: {sum(times)/len(times):.1f}s")
    print(f"Total time: {sum(times)/60:.1f} minutes")
    print(f"{'='*60}")

    # Save results
    os.makedirs(RESULTS_DIR, exist_ok=True)
    result_file = os.path.join(RESULTS_DIR, f"moderation_baseline_{model.replace(':', '_')}_{datetime.now().strftime('%Y%m%d_%H%M')}.json")
    with open(result_file, "w") as f:
        json.dump({
            "model": model,
            "task": "moderation",
            "timestamp": datetime.now().isoformat(),
            "metrics": {
                "accuracy": accuracy,
                "precision": precision,
                "recall": recall,
                "f1": f1,
                "total": total,
                "correct": correct,
                "true_pos": true_pos,
                "false_pos": false_pos,
                "true_neg": true_neg,
                "false_neg": false_neg,
            },
            "avg_inference_sec": sum(times) / len(times),
            "total_time_sec": sum(times),
            "results": results,
        }, f, indent=2)
    print(f"\nResults saved to {result_file}")
    return accuracy


def test_subject_correction(model, limit=100):
    """Test subject correction against test set."""
    test_file = os.path.join(DATA_DIR, "subject_correction_test.jsonl")
    if not os.path.exists(test_file):
        print(f"ERROR: {test_file} not found")
        return

    with open(test_file) as f:
        examples = [json.loads(line) for line in f]

    if limit and limit < len(examples):
        import random
        random.seed(42)
        examples = random.sample(examples, limit)

    if "freegle" in model.lower():
        system_prompt = ""
    else:
        system_prompt = (
            "You are a Freegle community moderator. Fix spelling, formatting, or content errors "
            "in Freegle post subjects. Subjects follow the format: TYPE: Item Description (Location POSTCODE).\n"
            "Common fixes: spelling corrections, proper capitalisation, removing extra spaces.\n"
            "Return ONLY the corrected subject on the first line. If no changes needed, return it unchanged."
        )

    exact_match = 0
    close_match = 0  # case-insensitive match
    total = 0
    times = []
    results = []

    print(f"\n{'='*60}")
    print(f"SUBJECT CORRECTION BASELINE TEST")
    print(f"Model: {model}")
    print(f"Test examples: {len(examples)}")
    print(f"Started: {datetime.now().strftime('%H:%M:%S')}")
    print(f"{'='*60}\n")

    for i, example in enumerate(examples):
        expected = example["output"].split("\n")[0].strip()
        prompt = example["instruction"]

        start = time.time()
        response, _ = ollama_generate(model, prompt, system_prompt)
        elapsed = time.time() - start
        times.append(elapsed)

        predicted = response.strip().split("\n")[0].strip()
        # Remove quotes if model wrapped it
        predicted = predicted.strip('"\'')

        is_exact = predicted == expected
        is_close = predicted.lower() == expected.lower()

        if is_exact:
            exact_match += 1
        if is_close:
            close_match += 1
        total += 1

        results.append({
            "msg_id": example["meta"]["msg_id"],
            "input": example["instruction"].split("Subject: ")[-1],
            "expected": expected,
            "predicted": predicted,
            "exact_match": is_exact,
            "close_match": is_close,
            "elapsed_sec": round(elapsed, 1),
        })

        avg_time = sum(times) / len(times)
        remaining = (len(examples) - total) * avg_time
        eta = datetime.now() + timedelta(seconds=remaining)

        print(
            f"[{total}/{len(examples)}] "
            f"{'✓' if is_exact else '~' if is_close else '✗'} "
            f"exact={exact_match/total*100:.0f}% close={close_match/total*100:.0f}% "
            f"ETA={eta.strftime('%H:%M:%S')} "
            f"({elapsed:.1f}s)"
        )
        sys.stdout.flush()

    print(f"\n{'='*60}")
    print(f"SUBJECT CORRECTION RESULTS - {model}")
    print(f"{'='*60}")
    print(f"Exact match:  {exact_match/total*100:.1f}% ({exact_match}/{total})")
    print(f"Close match:  {close_match/total*100:.1f}% ({close_match}/{total})")
    print(f"Avg inference: {sum(times)/len(times):.1f}s")
    print(f"Total time: {sum(times)/60:.1f} minutes")
    print(f"{'='*60}")

    os.makedirs(RESULTS_DIR, exist_ok=True)
    result_file = os.path.join(RESULTS_DIR, f"subject_baseline_{model.replace(':', '_')}_{datetime.now().strftime('%Y%m%d_%H%M')}.json")
    with open(result_file, "w") as f:
        json.dump({
            "model": model,
            "task": "subject_correction",
            "timestamp": datetime.now().isoformat(),
            "metrics": {
                "exact_match_pct": exact_match / total * 100,
                "close_match_pct": close_match / total * 100,
                "total": total,
            },
            "avg_inference_sec": sum(times) / len(times),
            "results": results,
        }, f, indent=2)
    print(f"\nResults saved to {result_file}")


def main():
    parser = argparse.ArgumentParser(description="Baseline accuracy test with Ollama")
    parser.add_argument("--model", default="llama3.2:3b", help="Ollama model name")
    parser.add_argument("--limit", type=int, default=200, help="Max examples to test")
    parser.add_argument("--task", default="all", choices=["all", "moderation", "subject"],
                        help="Which task to test")
    args = parser.parse_args()

    print(f"Baseline Accuracy Test")
    print(f"Model: {args.model}")
    print(f"Limit: {args.limit} examples per task")
    print(f"Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")

    # Quick connectivity check
    print(f"\nChecking Ollama at {OLLAMA_HOST}...")
    test_resp, _ = ollama_generate(args.model, "Say 'OK' and nothing else.")
    if test_resp.startswith("ERROR"):
        print(f"Cannot reach Ollama: {test_resp}")
        sys.exit(1)
    print(f"Ollama OK: {test_resp.strip()[:50]}")

    if args.task in ("all", "moderation"):
        test_moderation(args.model, args.limit)

    if args.task in ("all", "subject"):
        test_subject_correction(args.model, min(args.limit, 100))


if __name__ == "__main__":
    main()
