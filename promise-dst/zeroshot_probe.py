#!/usr/bin/env python3
"""
Zero-shot promise detection probe — DST-style, NO fine-tuning.

Runs a pre-trained Flan-T5 over the same clean span->promised CSV the linear
baseline used, asking yes/no per windowed dialogue, and scores it on the metric
that actually matters here: expected cost with FP 20x worse than FN
(=> we need high precision), alongside P/R/F1 and ROC/PR-AUC.

Usage:
  python3 zeroshot_probe.py --csv <path> [--model google/flan-t5-base]
                            [--sample 800] [--fp-cost 20]
"""
import argparse, csv, math, random
csv.field_size_limit(10_000_000)

PROMPT = (
    "A Freegle member is giving away or receiving a second-hand item. "
    "Read this chat between the item's owner ([GIVER]) and the other person ([TAKER]):\n\n"
    "{span}\n\n"
    "Question: Has the owner committed to giving this specific item to this person "
    "(a promise has been made)? Answer yes or no."
)

def load(path):
    rows = []
    with open(path, newline="", encoding="utf-8") as f:
        r = csv.DictReader(f)  # RFC-4180; spans were written with escape=''
        for d in r:
            rows.append((int(d["room_id"]), int(d["label"]), d["span"]))
    return rows

def metrics(labels, scores, fp_cost=20.0, fn_cost=1.0):
    pos = sum(labels); n = len(labels); neg = n - pos
    order = sorted(range(n), key=lambda i: scores[i], reverse=True)
    tps = []; fps = []; tp = fp = 0
    for i in order:
        if labels[i] == 1: tp += 1
        else: fp += 1
        tps.append(tp); fps.append(fp)
    roc = 0.0; ptp = pfp = 0
    for t, fpc in zip(tps, fps):
        roc += (fpc - pfp) * (t + ptp) / 2.0
        ptp, pfp = t, fpc
    roc = roc / (pos * neg) if pos and neg else float("nan")
    pr = 0.0; prev_rec = 0.0
    for t, fpc in zip(tps, fps):
        rec = t / pos if pos else 0.0
        prec = t / (t + fpc) if (t + fpc) else 1.0
        pr += (rec - prev_rec) * prec
        prev_rec = rec
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
    return dict(n=n, pos=pos, base_rate=pos / n, roc=roc, pr=pr,
                best=best, never_fire_cost=fn_cost * pos)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", required=True)
    ap.add_argument("--model", default="google/flan-t5-base")
    ap.add_argument("--sample", type=int, default=800)
    ap.add_argument("--fp-cost", type=float, default=20.0)
    args = ap.parse_args()

    import torch
    from transformers import AutoTokenizer, AutoModelForSeq2SeqLM

    rows = load(args.csv)
    random.seed(42)
    pos = [r for r in rows if r[1] == 1]
    neg = [r for r in rows if r[1] == 0]
    random.shuffle(pos); random.shuffle(neg)
    k = args.sample
    take_pos = min(len(pos), k // 3)
    take_neg = min(len(neg), k - take_pos)
    sample = pos[:take_pos] + neg[:take_neg]
    random.shuffle(sample)
    print(f"loaded {len(rows)} rows; probing {len(sample)} "
          f"({take_pos} pos / {take_neg} neg) with {args.model}", flush=True)

    tok = AutoTokenizer.from_pretrained(args.model)
    model = AutoModelForSeq2SeqLM.from_pretrained(args.model)
    model.eval()
    yes_id = tok("yes", add_special_tokens=False).input_ids[0]
    no_id = tok("no", add_special_tokens=False).input_ids[0]
    dec_start = torch.tensor([[model.config.decoder_start_token_id]])

    labels, scores = [], []
    with torch.no_grad():
        for j, (_rid, label, span) in enumerate(sample):
            enc = tok(PROMPT.format(span=span), return_tensors="pt",
                      truncation=True, max_length=512)
            out = model(input_ids=enc.input_ids, attention_mask=enc.attention_mask,
                        decoder_input_ids=dec_start)
            logit = out.logits[0, -1]
            y, no = logit[yes_id].item(), logit[no_id].item()
            m = max(y, no)
            p_yes = math.exp(y - m) / (math.exp(y - m) + math.exp(no - m))
            labels.append(label); scores.append(p_yes)
            if (j + 1) % 100 == 0:
                print(f"  {j+1}/{len(sample)}", flush=True)

    M = metrics(labels, scores, fp_cost=args.fp_cost)
    b = M["best"]
    print("\n=== ZERO-SHOT probe result ===")
    print(f"model            : {args.model}")
    print(f"samples          : {M['n']}  (base rate {M['base_rate']:.1%})")
    print(f"ROC-AUC          : {M['roc']:.3f}   PR-AUC: {M['pr']:.3f}")
    print(f"cost-optimal thr : {b['thr']:.2f}")
    print(f"  precision      : {b['P']:.3f}   recall: {b['R']:.3f}   F1: {b['F1']:.3f}")
    print(f"  TP {b['TP']}  FP {b['FP']}  FN {b['FN']}")
    print(f"expected cost (FP={int(args.fp_cost)}xFN): {b['cost']:.0f}"
          f"   vs never-fire: {M['never_fire_cost']:.0f}"
          f"   -> {'BEATS' if b['cost'] < M['never_fire_cost'] else 'WORSE THAN'} doing nothing")
    print(f"deployment precision target: >= ~95%  (achieved here: {b['P']:.1%})")

if __name__ == "__main__":
    main()
