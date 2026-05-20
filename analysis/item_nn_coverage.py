#!/usr/bin/env python3
"""
Measure validation coverage from nearest-neighbour embedding lookup
at various similarity thresholds, without global clustering.

For each validation item not in the training vocabulary, we find its
single best matching training item. If similarity >= threshold, we use
that training item's desirability score rather than falling back to the mean.
"""

import pymysql
import pandas as pd
import numpy as np
from sentence_transformers import SentenceTransformer
from sklearn.metrics.pairwise import cosine_similarity

DB = dict(host='127.0.0.1', port=11234, user='root',
          password='F5432f12azfvds', db='iznik', charset='utf8mb4',
          autocommit=True)

TRAIN_START = '2023-01-01'; TRAIN_END = '2024-06-30'
VAL_START   = '2021-01-01'; VAL_END   = '2022-12-31'
MIN_POSTS   = 5
BATCH       = 500

def get_conn():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

def read_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return pd.DataFrame(cur.fetchall())

print("=" * 60)
print("NN Coverage vs Threshold")
print("=" * 60)

conn = get_conn()
print("\nPulling item names...", flush=True)
for label, start, end in [("train", TRAIN_START, TRAIN_END),
                           ("val",   VAL_START,   VAL_END)]:
    df = read_sql(conn, """
        SELECT i.name AS item_name, COUNT(DISTINCT m.id) AS num_posts
        FROM messages m
        JOIN messages_items mi ON mi.msgid = m.id
        JOIN items i ON i.id = mi.itemid
        WHERE m.type='Offer' AND m.arrival BETWEEN %s AND %s AND i.name IS NOT NULL
        GROUP BY i.name HAVING COUNT(DISTINCT m.id) >= %s
    """, (start, end, MIN_POSTS))
    if label == "train":
        train_names = set(df['item_name'].astype(str))
    else:
        val_names = set(df['item_name'].astype(str))
conn.close()

print(f"  Training: {len(train_names):,}  Validation: {len(val_names):,}")
exact_overlap = len(train_names & val_names)
print(f"  Exact match: {exact_overlap:,} / {len(val_names):,} ({100*exact_overlap/len(val_names):.1f}%)")

print("\nEmbedding...", flush=True)
model = SentenceTransformer('all-MiniLM-L6-v2')
train_list = list(train_names)
val_list   = list(val_names)
train_emb  = model.encode(train_list, batch_size=256, show_progress_bar=False,
                           convert_to_numpy=True)
val_emb    = model.encode(val_list,   batch_size=256, show_progress_bar=False,
                           convert_to_numpy=True)
print(f"  Done. train={train_emb.shape}  val={val_emb.shape}", flush=True)

# Only process val items not already in training
unmatched_idx = [i for i, n in enumerate(val_list) if n not in train_names]
unmatched_emb = val_emb[unmatched_idx]
print(f"  Unmatched val items to look up: {len(unmatched_idx):,}", flush=True)

print("\nComputing best training match per unmatched val item...", flush=True)
best_sim = np.zeros(len(unmatched_idx))
best_match = [''] * len(unmatched_idx)

for i in range(0, len(unmatched_idx), BATCH):
    batch = unmatched_emb[i:i+BATCH]
    sims  = cosine_similarity(batch, train_emb)
    idxs  = sims.argmax(axis=1)
    best_sim[i:i+BATCH]   = sims[np.arange(len(batch)), idxs]
    for k, j in enumerate(idxs):
        best_match[i + k] = train_list[int(j)]

print("\nCoverage at each threshold:")
print(f"{'Threshold':>10} {'Matched':>8} {'Total cov':>10} {'Pct':>7}")
print("-" * 42)
for t in [1.00, 0.98, 0.95, 0.93, 0.90, 0.85, 0.80, 0.00]:
    newly = int((best_sim >= t).sum())
    total = exact_overlap + newly
    pct   = 100 * total / len(val_names)
    print(f"  {t:>6.2f}    {newly:>8,}   {total:>8,}   {pct:>6.1f}%")

# Show sample matches at 0.93
T = 0.93
print(f"\nSample matches at threshold {T} "
      f"(unmatched val item → best training item):")
print(f"  {'Sim':>5}  {'Val item':<35}  {'Training match'}")
print("  " + "-" * 75)
shown = 0
for i, (s, m) in enumerate(zip(best_sim, best_match)):
    if s < T:
        continue
    vn = val_list[unmatched_idx[i]]
    if vn != m:
        print(f"  {s:.3f}  {vn:<35}  {m}")
        shown += 1
        if shown >= 30:
            break

# Show the BAD matches at 0.93 — cases where sim >= 0.93 but items look wrong
print(f"\nSpot-check: random sample of matches near threshold (0.93–0.95):")
print(f"  {'Sim':>5}  {'Val item':<35}  {'Training match'}")
print("  " + "-" * 75)
marginal_idx = [i for i, s in enumerate(best_sim) if 0.93 <= s <= 0.95]
rng = np.random.default_rng(42)
sample = rng.choice(marginal_idx, size=min(20, len(marginal_idx)), replace=False)
for i in sorted(sample):
    vn = val_list[unmatched_idx[i]]
    print(f"  {best_sim[i]:.3f}  {vn:<35}  {best_match[i]}")

print("\n" + "=" * 60)
print(f"Baseline (exact):   {exact_overlap:,} / {len(val_names):,} = "
      f"{100*exact_overlap/len(val_names):.1f}%")
at93 = int((best_sim >= 0.93).sum())
print(f"With NN @ 0.93:     {exact_overlap+at93:,} / {len(val_names):,} = "
      f"{100*(exact_overlap+at93)/len(val_names):.1f}%")
at90 = int((best_sim >= 0.90).sum())
print(f"With NN @ 0.90:     {exact_overlap+at90:,} / {len(val_names):,} = "
      f"{100*(exact_overlap+at90)/len(val_names):.1f}%")
print("=" * 60)
