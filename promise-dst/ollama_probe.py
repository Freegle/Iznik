#!/usr/bin/env python3
"""
Zero-shot promise detection with a local instruct LLM via Ollama (e.g. qwen2.5:3b).
NO fine-tuning — the strongest "can a pre-trained model just do it?" test.

Asks the model for a 0-100 promise probability per windowed dialogue, so we get a
rankable score (ROC/PR-AUC) plus the expected-cost metric (FP 20x worse than FN).

Usage:
  python3 ollama_probe.py --csv <path> [--model qwen2.5:3b] [--sample 200]
                          [--fp-cost 20] [--host http://localhost:11434]
"""
import argparse, csv, json, random, re, urllib.request
csv.field_size_limit(10_000_000)

PROMPT = (
    "You are analysing a chat between two Freegle members about a free second-hand item.\n"
    "[GIVER] is the item's current owner; [TAKER] is the other person who wants it.\n\n"
    "Conversation:\n{span}\n\n"
    "Question: In THIS conversation, has the owner [GIVER] committed to giving this specific "
    "item to [TAKER] — i.e. a clear promise/agreement to hand it over has been made (not just "
    "interest, questions, or arranging that fizzles)?\n"
    "Reply with ONLY an integer from 0 to 100 = the probability a promise was made "
    "(0 = definitely not, 100 = definitely yes)."
)

def load(path):
    rows = []
    with open(path, newline="", encoding="utf-8") as f:
        for d in csv.DictReader(f):
            rows.append((int(d["room_id"]), int(d["label"]), d["span"]))
    return rows

def ask(host, model, span, timeout=60):
    body = json.dumps({
        "model": model,
        "prompt": PROMPT.format(span=span),
        "stream": False,
        "options": {"temperature": 0, "num_predict": 8},
    }).encode()
    req = urllib.request.Request(host + "/api/generate", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        resp = json.load(r).get("response", "")
    m = re.search(r"\d{1,3}", resp)
    if not m:
        return None
    return max(0, min(100, int(m.group()))) / 100.0

def metrics(labels, scores, fp_cost=20.0, fn_cost=1.0):
    n = len(labels); pos = sum(labels); neg = n - pos
    order = sorted(range(n), key=lambda i: scores[i], reverse=True)
    tps = []; fps = []; tp = fp = 0
    for i in order:
        tp += labels[i]; fp += (1 - labels[i]); tps.append(tp); fps.append(fp)
    roc = 0.0; ptp = pfp = 0
    for t, fpc in zip(tps, fps):
        roc += (fpc - pfp) * (t + ptp) / 2.0; ptp, pfp = t, fpc
    roc = roc / (pos * neg) if pos and neg else float("nan")
    pr = 0.0; prev = 0.0
    for t, fpc in zip(tps, fps):
        rec = t / pos if pos else 0.0; prec = t / (t + fpc) if (t + fpc) else 1.0
        pr += (rec - prev) * prec; prev = rec
    best = None
    for thr in [i / 100 for i in range(1, 100)]:
        TP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 1)
        FP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 0)
        FN = pos - TP
        P = TP / (TP + FP) if (TP + FP) else 0.0
        R = TP / pos if pos else 0.0
        F1 = 2 * P * R / (P + R) if (P + R) else 0.0
        cost = fp_cost * FP + fn_cost * FN
        if best is None or cost < best["cost"]:
            best = dict(thr=thr, P=P, R=R, F1=F1, cost=cost, TP=TP, FP=FP, FN=FN)
    # highest precision reachable at recall >= 0.2 (is there a confident high-precision regime?)
    hp = (0.0, 0.0)
    for thr in [i / 100 for i in range(1, 100)]:
        TP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 1)
        FP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 0)
        R = TP / pos if pos else 0.0; P = TP / (TP + FP) if (TP + FP) else 0.0
        if R >= 0.2 and P > hp[0]:
            hp = (P, R)
    return dict(n=n, pos=pos, base_rate=pos / n, roc=roc, pr=pr, best=best,
                high_prec=hp, never_fire_cost=fn_cost * pos)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", required=True)
    ap.add_argument("--model", default="qwen2.5:3b")
    ap.add_argument("--sample", type=int, default=200)
    ap.add_argument("--fp-cost", type=float, default=20.0)
    ap.add_argument("--host", default="http://localhost:11434")
    args = ap.parse_args()

    rows = load(args.csv)
    random.seed(42)
    pos = [r for r in rows if r[1] == 1]; neg = [r for r in rows if r[1] == 0]
    random.shuffle(pos); random.shuffle(neg)
    take_pos = min(len(pos), args.sample // 3)
    take_neg = min(len(neg), args.sample - take_pos)
    sample = pos[:take_pos] + neg[:take_neg]; random.shuffle(sample)
    print(f"loaded {len(rows)} rows; probing {len(sample)} "
          f"({take_pos} pos / {take_neg} neg) with {args.model} via ollama", flush=True)

    labels, scores, fails = [], [], 0
    for j, (_rid, label, span) in enumerate(sample):
        try:
            p = ask(args.host, args.model, span)
        except Exception as e:
            p = None
            if fails < 3:
                print(f"  request error: {e}", flush=True)
        if p is None:
            p = 0.0; fails += 1
        labels.append(label); scores.append(p)
        if (j + 1) % 25 == 0:
            print(f"  {j+1}/{len(sample)}", flush=True)

    M = metrics(labels, scores, fp_cost=args.fp_cost); b = M["best"]
    print("\n=== OLLAMA zero-shot result ===")
    print(f"model       : {args.model}   (unparseable replies: {fails})")
    print(f"samples     : {M['n']}  (base rate {M['base_rate']:.1%})")
    print(f"ROC-AUC     : {M['roc']:.3f}   PR-AUC: {M['pr']:.3f}")
    print(f"high-precision regime (recall>=0.2): precision {M['high_prec'][0]:.3f} "
          f"at recall {M['high_prec'][1]:.3f}")
    print(f"cost-optimal thr {b['thr']:.2f}: P {b['P']:.3f} R {b['R']:.3f} "
          f"(TP {b['TP']} FP {b['FP']} FN {b['FN']})")
    print(f"expected cost (FP={int(args.fp_cost)}xFN): {b['cost']:.0f} "
          f"vs never-fire {M['never_fire_cost']:.0f} -> "
          f"{'BEATS' if b['cost'] < M['never_fire_cost'] else 'NOT better than'} doing nothing")
    print(f"precision target >= ~95%  (best cost-point precision {b['P']:.1%})")

if __name__ == "__main__":
    main()
