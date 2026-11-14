#!/usr/bin/env python3
"""
C) Evaluate the fine-tuned promise model on the HELD-OUT gold rooms.

Run on the GPU VM after train_finetune.py:
    python eval_finetune.py

Inputs : ./promise_model/, ./threshold.json, ./gold.jsonl
         gold.jsonl: {"id","text","label","weight"} -- 500 rooms NOT seen in training,
         weights re-balance the stratified sample back to the real ~14% prevalence.
Prints : weighted ROC-AUC, then precision/recall/F1 at the deployed threshold (the headline: it is
         the operating point that ships, with 95% bootstrap intervals), then the max recall at
         precision>=0.95. That last figure picks its threshold on this same gold set, so it is an
         upper bound and not a result.
"""
import json, numpy as np, torch
import torch.nn.functional as F
from transformers import AutoTokenizer, AutoModelForSequenceClassification
from sklearn.metrics import roc_auc_score, precision_recall_fscore_support

MODEL_DIR, GOLD, MAX_LEN = './promise_model', 'gold.jsonl', 512
rows = [json.loads(l) for l in open(GOLD, encoding='utf-8') if l.strip()]
tok = AutoTokenizer.from_pretrained(MODEL_DIR)
model = AutoModelForSequenceClassification.from_pretrained(MODEL_DIR).eval()
if torch.cuda.is_available(): model.cuda()

def predict(rows):
    out = []
    with torch.no_grad():
        for i in range(0, len(rows), 32):
            b = rows[i:i + 32]
            enc = tok([r['text'] for r in b], truncation=True, max_length=MAX_LEN,
                      padding=True, return_tensors='pt').to(model.device)
            out.extend(F.softmax(model(**enc).logits, dim=1)[:, 1].cpu().numpy().tolist())
    return np.array(out)

p = predict(rows)
y = np.array([int(r['label']) for r in rows])
w = np.array([float(r.get('weight', 1.0)) for r in rows])

def recall_at_precision(score, y, w, target=0.95):
    o = np.argsort(-score); s, yy, ww = score[o], y[o], w[o]
    P = (ww * yy).sum(); best, bt, tp, fp, i = 0.0, None, 0.0, 0.0, 0
    while i < len(s):
        k = i
        while k < len(s) and s[k] == s[i]:
            tp += ww[k] * (yy[k] == 1); fp += ww[k] * (yy[k] == 0); k += 1
        prec = tp / (tp + fp) if (tp + fp) else 1.0
        rec = tp / P if P else 0.0
        if prec >= target and rec > best: best, bt = rec, s[i]
        i = k
    return best, bt

# At 20:1 (a false positive costs 20 times a missed promise) a detector only helps above this.
BREAK_EVEN_PRECISION = 20 / 21

def bootstrap_precision_recall(y, pred, w, n=1000, seed=0):
    """Weighted precision and recall on n resamples of the gold rooms: lists of values."""
    rng = np.random.default_rng(seed)
    ps, rs = [], []
    for _ in range(n):
        i = rng.integers(0, len(y), len(y))
        yy, pp, ww = y[i], pred[i], w[i]
        tp = (ww * ((pp == 1) & (yy == 1))).sum()
        fp = (ww * ((pp == 1) & (yy == 0))).sum()
        fn = (ww * ((pp == 0) & (yy == 1))).sum()
        ps.append(tp / (tp + fp) if (tp + fp) else float('nan'))
        rs.append(tp / (tp + fn) if (tp + fn) else float('nan'))
    return np.array(ps), np.array(rs)

roc = roc_auc_score(y, p, sample_weight=w)
r95, t95 = recall_at_precision(p, y, w)
print(f"gold rooms: {len(rows)}  (positives {int(y.sum())})")
print(f"weighted ROC-AUC: {roc:.3f}")
try:
    thr = json.load(open('threshold.json'))['threshold']
    pred = (p >= thr).astype(int)
    pr, rc, f1, _ = precision_recall_fscore_support(y, pred, average='binary',
                                                    sample_weight=w, zero_division=0)
    print(f">>> at deployed threshold {thr:.3f}: precision {pr:.3f}  recall {rc:.3f}  F1 {f1:.3f}")
    ps, rs = bootstrap_precision_recall(y, pred, w)
    plo, phi = np.nanpercentile(ps, [2.5, 97.5]); rlo, rhi = np.nanpercentile(rs, [2.5, 97.5])
    print(f"    95% bootstrap interval: precision {plo:.3f} to {phi:.3f}, recall {rlo:.3f} to {rhi:.3f}")
    print(f"    resamples with precision below the {BREAK_EVEN_PRECISION:.3f} break-even: "
          f"{np.nanmean(ps < BREAK_EVEN_PRECISION):.1%}")
except FileNotFoundError:
    print("(no threshold.json — run train_finetune.py first for the deployed operating point)")
print("upper bound, not a result (threshold chosen on this gold set): max recall @ precision>=0.95 "
      f"(weighted, real prevalence): {r95:.3f}"
      + (f"  (threshold {t95:.3f})" if t95 is not None else "  (UNREACHABLE)"))
