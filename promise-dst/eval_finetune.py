#!/usr/bin/env python3
"""
C) Evaluate the fine-tuned promise model on the HELD-OUT gold rooms.

Run on the GPU VM after train_finetune.py:
    python eval_finetune.py

Inputs : ./promise_model/, ./threshold.json, ./gold.jsonl
         gold.jsonl: {"id","text","label","weight"} -- 500 rooms NOT seen in training,
         weights re-balance the stratified sample back to the real ~14% prevalence.
Prints : weighted ROC-AUC, max recall @ precision>=0.95 (the headline metric),
         and precision/recall/F1 at the deployed threshold.
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

roc = roc_auc_score(y, p, sample_weight=w)
r95, t95 = recall_at_precision(p, y, w)
print(f"gold rooms: {len(rows)}  (positives {int(y.sum())})")
print(f"weighted ROC-AUC: {roc:.3f}")
print(f">>> max recall @ precision>=0.95 (weighted, real prevalence): {r95:.3f}"
      + (f"  (threshold {t95:.3f})" if t95 is not None else "  (UNREACHABLE)"))
try:
    thr = json.load(open('threshold.json'))['threshold']
    pred = (p >= thr).astype(int)
    pr, rc, f1, _ = precision_recall_fscore_support(y, pred, average='binary',
                                                    sample_weight=w, zero_division=0)
    print(f"at deployed threshold {thr:.3f}: precision {pr:.3f}  recall {rc:.3f}  F1 {f1:.3f}")
except FileNotFoundError:
    print("(no threshold.json — run train_finetune.py first for the deployed operating point)")
