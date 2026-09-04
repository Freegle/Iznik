#!/usr/bin/env python3
"""
B) Fine-tune DeBERTa-v3-base to detect a 'promise' at CONVERSATION level.

Run on the GPU VM:
    pip install "transformers>=4.41" torch scikit-learn numpy sentencepiece
    python train_finetune.py                 # reads ./train.jsonl

Inputs : train.jsonl  (one JSON per line: {"id","text","label"})  -- from the laptop's gold_unc/
Outputs: ./promise_model/   (fine-tuned model + tokenizer)
         ./threshold.json    (decision threshold tuned on a val slice for precision >= 0.95)

Env overrides: BASE_MODEL, TRAIN_FILE, OUT_DIR, EPOCHS, MAX_LEN, BATCH
"""
import json, os, random
import numpy as np
import torch
import torch.nn.functional as F
from torch.utils.data import Dataset
from transformers import (AutoTokenizer, AutoModelForSequenceClassification,
                          TrainingArguments, Trainer, DataCollatorWithPadding)
from sklearn.metrics import roc_auc_score, precision_recall_curve

BASE_MODEL = os.environ.get('BASE_MODEL', 'microsoft/deberta-v3-base')
TRAIN_FILE = os.environ.get('TRAIN_FILE', 'train.jsonl')
OUT_DIR    = os.environ.get('OUT_DIR', './promise_model')
EPOCHS     = int(os.environ.get('EPOCHS', '4'))
MAX_LEN    = int(os.environ.get('MAX_LEN', '512'))
BATCH      = int(os.environ.get('BATCH', '16'))
VAL_FRAC, SEED = 0.15, 42
random.seed(SEED); np.random.seed(SEED); torch.manual_seed(SEED)

rows = [json.loads(l) for l in open(TRAIN_FILE, encoding='utf-8') if l.strip()]
random.Random(SEED).shuffle(rows)
n_val = max(1, int(len(rows) * VAL_FRAC))
val_rows, tr_rows = rows[:n_val], rows[n_val:]
posrate = np.mean([r['label'] for r in rows])
print(f"loaded {len(rows)} convs  | train {len(tr_rows)}  val {len(val_rows)}  pos-rate {posrate:.3f}")

tok = AutoTokenizer.from_pretrained(BASE_MODEL)

class ConvDS(Dataset):
    def __init__(self, rows): self.rows = rows
    def __len__(self): return len(self.rows)
    def __getitem__(self, i):
        r = self.rows[i]
        enc = tok(r['text'], truncation=True, max_length=MAX_LEN)
        return {'input_ids': enc['input_ids'],
                'attention_mask': enc['attention_mask'],
                'labels': int(r['label'])}

# class weights (data is ~14% positive)
pos = sum(r['label'] for r in tr_rows); neg = len(tr_rows) - pos
cw = torch.tensor([len(tr_rows) / (2 * max(neg, 1)), len(tr_rows) / (2 * max(pos, 1))], dtype=torch.float)
print(f"class weights (neg,pos): {cw.tolist()}")

model = AutoModelForSequenceClassification.from_pretrained(BASE_MODEL, num_labels=2)
model = model.float()  # transformers 5.9 loads in fp16 by default; force fp32 master weights

class WeightedTrainer(Trainer):
    def compute_loss(self, model, inputs, return_outputs=False, **kw):
        labels = inputs.pop('labels')
        out = model(**inputs)
        loss = F.cross_entropy(out.logits, labels, weight=cw.to(out.logits.device))
        return (loss, out) if return_outputs else loss

args = TrainingArguments(
    output_dir=OUT_DIR, num_train_epochs=EPOCHS,
    per_device_train_batch_size=BATCH, per_device_eval_batch_size=32,
    learning_rate=2e-5, warmup_ratio=0.1, weight_decay=0.01,
    eval_strategy='epoch', save_strategy='epoch', load_best_model_at_end=True,
    metric_for_best_model='eval_loss', greater_is_better=False,
    logging_steps=50, seed=SEED, fp16=False, report_to=[])

trainer = WeightedTrainer(model=model, args=args,
                          train_dataset=ConvDS(tr_rows), eval_dataset=ConvDS(val_rows),
                          data_collator=DataCollatorWithPadding(tok))
trainer.train()
trainer.save_model(OUT_DIR); tok.save_pretrained(OUT_DIR)

# ---- tune the deployment threshold for precision >= 0.95 on the val slice ----
def predict(rows):
    model.eval(); out = []
    with torch.no_grad():
        for i in range(0, len(rows), 32):
            b = rows[i:i + 32]
            enc = tok([r['text'] for r in b], truncation=True, max_length=MAX_LEN,
                      padding=True, return_tensors='pt').to(model.device)
            out.extend(F.softmax(model(**enc).logits, dim=1)[:, 1].cpu().numpy().tolist())
    return np.array(out)

vp = predict(val_rows); vy = np.array([r['label'] for r in val_rows])
prec, rec, thr = precision_recall_curve(vy, vp)
cands = [(rec[i], thr[i]) for i in range(len(thr)) if prec[i] >= 0.95]
best_rec, best_thr = max(cands) if cands else (0.0, 0.99)
json.dump({'threshold': float(best_thr), 'val_recall_at_p95': float(best_rec),
           'val_roc_auc': float(roc_auc_score(vy, vp))}, open('threshold.json', 'w'), indent=2)
print(f"\nval ROC-AUC {roc_auc_score(vy, vp):.3f} | threshold@P>=0.95 = {best_thr:.3f} "
      f"(val recall {best_rec:.3f})")
print(f"saved -> {OUT_DIR}/ and threshold.json")
