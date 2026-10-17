#!/usr/bin/env python3
"""
Fine-tune an encoder (DistilBERT/RoBERTa) as a binary promise classifier on the
same clean span->promised CSV. The real DST test vs the linear baseline: same
input, same labels, same conversation-level split — only the model changes.

Scored on what matters here: expected cost with FP 20x worse than FN
(=> precision-at-target), plus ROC/PR-AUC and a threshold sweep.

Usage:
  python3 finetune_encoder.py --csv <path> [--model distilbert-base-uncased]
          [--epochs 3] [--max-len 256] [--test-fraction 0.2] [--fp-cost 20]
"""
import argparse, csv, math, random
csv.field_size_limit(10_000_000)

def load(path):
    rows = []
    with open(path, newline="", encoding="utf-8") as f:
        for d in csv.DictReader(f):
            rows.append((int(d["room_id"]), int(d["label"]), d["span"]))
    return rows

def group_split(rows, test_fraction, seed=42):
    """Split by room_id so no conversation appears in both train and test."""
    rooms = sorted({r[0] for r in rows})
    rng = random.Random(seed); rng.shuffle(rooms)
    n_test = max(1, int(len(rooms) * test_fraction))
    test_rooms = set(rooms[:n_test])
    train = [r for r in rows if r[0] not in test_rooms]
    test = [r for r in rows if r[0] in test_rooms]
    return train, test

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
    best = None; sweep = []
    for thr in [i / 100 for i in range(1, 100)]:
        TP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 1)
        FP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 0)
        FN = pos - TP
        P = TP / (TP + FP) if (TP + FP) else 0.0
        R = TP / pos if pos else 0.0
        F1 = 2 * P * R / (P + R) if (P + R) else 0.0
        cost = fp_cost * FP + fn_cost * FN
        if round(thr, 2) in (0.3, 0.5, 0.7, 0.9):
            sweep.append((thr, P, R, F1))
        if best is None or cost < best["cost"]:
            best = dict(thr=thr, P=P, R=R, F1=F1, cost=cost, TP=TP, FP=FP, FN=FN)
    # highest precision reachable at recall >= 0.2 (is there a confident high-precision regime?)
    hp = (0.0, 0.0)
    for thr in [i / 100 for i in range(1, 100)]:
        TP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 1)
        FP = sum(1 for i in range(n) if scores[i] >= thr and labels[i] == 0)
        R = TP / pos if pos else 0.0
        P = TP / (TP + FP) if (TP + FP) else 0.0
        if R >= 0.2 and P > hp[0]:
            hp = (P, R)
    return dict(n=n, pos=pos, base_rate=pos / n, roc=roc, pr=pr, best=best,
                sweep=sweep, high_prec=hp, never_fire_cost=fn_cost * pos)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", required=True)
    ap.add_argument("--model", default="distilbert-base-uncased")
    ap.add_argument("--epochs", type=int, default=3)
    ap.add_argument("--max-len", type=int, default=256)
    ap.add_argument("--test-fraction", type=float, default=0.2)
    ap.add_argument("--fp-cost", type=float, default=20.0)
    ap.add_argument("--batch", type=int, default=16)
    args = ap.parse_args()

    import numpy as np, torch
    from torch.utils.data import Dataset, DataLoader
    from transformers import AutoTokenizer, AutoModelForSequenceClassification
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(f"device: {device}", flush=True)

    rows = load(args.csv)
    train_rows, test_rows = group_split(rows, args.test_fraction)
    pos = sum(r[1] for r in train_rows)
    print(f"{len(rows)} rows | train {len(train_rows)} ({pos} pos) "
          f"/ test {len(test_rows)} ({sum(r[1] for r in test_rows)} pos)", flush=True)

    tok = AutoTokenizer.from_pretrained(args.model)

    class DS(Dataset):
        def __init__(self, data): self.data = data
        def __len__(self): return len(self.data)
        def __getitem__(self, i):
            _rid, label, span = self.data[i]
            enc = tok(span, truncation=True, max_length=args.max_len,
                      padding="max_length", return_tensors="pt")
            return {"input_ids": enc.input_ids[0],
                    "attention_mask": enc.attention_mask[0],
                    "labels": torch.tensor(label)}

    model = AutoModelForSequenceClassification.from_pretrained(args.model, num_labels=2)
    model.to(device)
    # class weights for the ~15% positive imbalance
    neg = len(train_rows) - pos
    w = torch.tensor([1.0, neg / max(pos, 1)], dtype=torch.float).to(device)
    loss_fn = torch.nn.CrossEntropyLoss(weight=w)
    opt = torch.optim.AdamW(model.parameters(), lr=2e-5)

    train_dl = DataLoader(DS(train_rows), batch_size=args.batch, shuffle=True)
    model.train()
    for ep in range(args.epochs):
        tot = 0.0
        for step, b in enumerate(train_dl):
            b = {k: v.to(device) for k, v in b.items()}
            out = model(input_ids=b["input_ids"], attention_mask=b["attention_mask"])
            loss = loss_fn(out.logits, b["labels"])
            loss.backward(); opt.step(); opt.zero_grad()
            tot += loss.item()
            if step % 100 == 0:
                print(f"  epoch {ep+1} step {step}/{len(train_dl)} loss {loss.item():.3f}", flush=True)
        print(f"epoch {ep+1} mean loss {tot/len(train_dl):.3f}", flush=True)

    model.eval()
    labels, scores = [], []
    test_dl = DataLoader(DS(test_rows), batch_size=args.batch)
    with torch.no_grad():
        for b in test_dl:
            b = {k: v.to(device) for k, v in b.items()}
            out = model(input_ids=b["input_ids"], attention_mask=b["attention_mask"])
            p = torch.softmax(out.logits, dim=1)[:, 1]
            scores.extend(p.tolist()); labels.extend(b["labels"].tolist())

    M = metrics(labels, scores, fp_cost=args.fp_cost); b = M["best"]
    print("\n=== FINE-TUNED ENCODER result ===")
    print(f"model      : {args.model}  ({args.epochs} epochs)")
    print(f"test       : {M['n']} windows (base rate {M['base_rate']:.1%})")
    print(f"ROC-AUC    : {M['roc']:.3f}   PR-AUC: {M['pr']:.3f}")
    print(f"high-precision regime (recall>=0.2): precision {M['high_prec'][0]:.3f} "
          f"at recall {M['high_prec'][1]:.3f}")
    for thr, P, R, F1 in M["sweep"]:
        print(f"  thr {thr:.1f}: P {P:.3f}  R {R:.3f}  F1 {F1:.3f}")
    print(f"cost-optimal thr {b['thr']:.2f}: P {b['P']:.3f} R {b['R']:.3f} "
          f"(TP {b['TP']} FP {b['FP']} FN {b['FN']})")
    print(f"expected cost (FP={int(args.fp_cost)}xFN): {b['cost']:.0f} "
          f"vs never-fire {M['never_fire_cost']:.0f} -> "
          f"{'BEATS' if b['cost'] < M['never_fire_cost'] else 'NOT better than'} doing nothing")
    print(f"precision target >= ~95%  (best cost-point precision {b['P']:.1%})")

if __name__ == "__main__":
    main()
