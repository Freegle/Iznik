#!/usr/bin/env python3
"""
Investigate whether embeddings help deduplicate item names in the desirability model.

Questions:
  1. How many trivial duplicates exist (case/punctuation only)?
  2. How many semantic near-duplicates would embeddings find?
  3. Do merged clusters look sensible, or do they create bad merges?
  4. What's the coverage improvement in validation from deduplication?
"""

import re
import pymysql
import pandas as pd
import numpy as np
from collections import defaultdict
from sklearn.metrics.pairwise import cosine_similarity
from sentence_transformers import SentenceTransformer

DB = dict(host='127.0.0.1', port=11234, user='root',
          password='F5432f12azfvds', db='iznik', charset='utf8mb4',
          autocommit=True)

TRAIN_START = '2023-01-01'
TRAIN_END   = '2024-06-30'
VAL_START   = '2021-01-01'
VAL_END     = '2022-12-31'
MIN_POSTS   = 5

def get_conn():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

def read_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return pd.DataFrame(cur.fetchall())

def normalise(name):
    """Simple string normalisation: lowercase, collapse whitespace/punctuation."""
    return re.sub(r'[\s\-_/]+', ' ', name.lower().strip().rstrip('s'))

# ── Step 1: Pull item names from training period ──────────────────────────────
print("=" * 70)
print("Item Embedding Investigation")
print("=" * 70)

conn = get_conn()

print("\n[1] Pulling item names from training period...", flush=True)
train_items = read_sql(conn, """
    SELECT i.name AS item_name, COUNT(DISTINCT m.id) AS num_posts
    FROM messages m
    JOIN messages_items mi ON mi.msgid = m.id
    JOIN items i ON i.id = mi.itemid
    WHERE m.type = 'Offer'
      AND m.arrival BETWEEN %s AND %s
      AND i.name IS NOT NULL
    GROUP BY i.name
    HAVING COUNT(DISTINCT m.id) >= %s
""", (TRAIN_START, TRAIN_END, MIN_POSTS))
train_items['num_posts'] = pd.to_numeric(train_items['num_posts'], errors='coerce')

print(f"  → {len(train_items):,} training item types")

print("\n[2] Pulling item names from validation period...", flush=True)
val_items = read_sql(conn, """
    SELECT i.name AS item_name, COUNT(DISTINCT m.id) AS num_posts
    FROM messages m
    JOIN messages_items mi ON mi.msgid = m.id
    JOIN items i ON i.id = mi.itemid
    WHERE m.type = 'Offer'
      AND m.arrival BETWEEN %s AND %s
      AND i.name IS NOT NULL
    GROUP BY i.name
    HAVING COUNT(DISTINCT m.id) >= %s
""", (VAL_START, VAL_END, MIN_POSTS))
val_items['num_posts'] = pd.to_numeric(val_items['num_posts'], errors='coerce')

conn.close()
print(f"  → {len(val_items):,} validation item types")

train_names = set(train_items['item_name'].astype(str))
val_names   = set(val_items['item_name'].astype(str))
baseline_overlap = len(train_names & val_names)
print(f"\n  Baseline overlap (exact match): {baseline_overlap:,} / {len(val_names):,} "
      f"({100*baseline_overlap/len(val_names):.1f}%)")

# ── Step 2: Trivial normalisation duplicates ──────────────────────────────────
print("\n[3] String-normalisation duplicates (lowercase + strip plurals)...", flush=True)

norm_to_names = defaultdict(list)
for name in train_names:
    norm_to_names[normalise(name)].append(name)

trivial_clusters = {k: v for k, v in norm_to_names.items() if len(v) > 1}
n_trivial_dupes = sum(len(v) - 1 for v in trivial_clusters.values())

print(f"  Trivial duplicate clusters: {len(trivial_clusters):,}")
print(f"  Items that would be merged: {n_trivial_dupes:,}")
print(f"\n  Examples of trivial clusters:")
shown = 0
for norm, names in sorted(trivial_clusters.items(), key=lambda x: -len(x[1])):
    if shown >= 15:
        break
    print(f"    '{norm}' → {names}")
    shown += 1

# How many validation items would normalise-match a training item?
train_norms = set(norm_to_names.keys())
val_norm_matches = sum(1 for n in val_names if normalise(n) in train_norms)
print(f"\n  Validation items matched via normalisation: {val_norm_matches:,} "
      f"(+{val_norm_matches - baseline_overlap:,} vs exact match)")

# ── Step 3: Embedding-based deduplication ────────────────────────────────────
print("\n[4] Embedding item names with sentence-transformers...", flush=True)
print("  Loading model (all-MiniLM-L6-v2)...", flush=True)
model = SentenceTransformer('all-MiniLM-L6-v2')

all_train = list(train_names)
print(f"  Encoding {len(all_train):,} names...", flush=True)
embeddings = model.encode(all_train, batch_size=256, show_progress_bar=True,
                          convert_to_numpy=True)
print(f"  → embeddings shape: {embeddings.shape}", flush=True)

# ── Step 4: Find near-duplicate pairs at different thresholds ─────────────────
print("\n[5] Finding near-duplicate pairs at cosine similarity thresholds...", flush=True)
print("    (computing pairwise similarities in batches, numpy vectorised)", flush=True)

CHOSEN_THRESHOLD = 0.90
thresholds = [0.95, 0.90, 0.85, 0.80]

batch_size = 500
n = len(all_train)
pair_counts = {t: 0 for t in thresholds}
example_pairs = {t: [] for t in thresholds}

parent = list(range(n))

def find(x):
    while parent[x] != x:
        parent[x] = parent[parent[x]]
        x = parent[x]
    return x

def union(x, y):
    parent[find(x)] = find(y)

for i in range(0, n, batch_size):
    if i % 5000 == 0:
        print(f"    batch {i}/{n}...", flush=True)
    batch_emb = embeddings[i:i+batch_size]
    sims = cosine_similarity(batch_emb, embeddings)  # shape: (batch, n)

    # Vectorised: for each row, find all j > gi above lowest threshold
    batch_len = sims.shape[0]
    for bi in range(batch_len):
        gi = i + bi
        row = sims[bi]
        row[:gi + 1] = 0.0           # zero out self + already-seen pairs

        for t in thresholds:
            idxs = np.where(row >= t)[0]
            pair_counts[t] += len(idxs)
            if len(example_pairs[t]) < 8:
                for j in idxs[:8]:
                    example_pairs[t].append((all_train[gi], all_train[int(j)],
                                             float(row[j])))

        # Union-Find at chosen threshold
        above = np.where(row >= CHOSEN_THRESHOLD)[0]
        for j in above:
            union(gi, int(j))

for t in thresholds:
    print(f"\n  Threshold {t:.2f}: {pair_counts[t]:,} near-duplicate pairs")
    print(f"    Examples:")
    for a, b, s in example_pairs[t][:5]:
        print(f"      {s:.3f}  '{a}'  ↔  '{b}'")

clusters = defaultdict(list)
for i, name in enumerate(all_train):
    clusters[find(i)].append(name)

multi_clusters = {k: v for k, v in clusters.items() if len(v) > 1}
print(f"  Clusters with >1 item: {len(multi_clusters):,}")
print(f"  Items merged: {sum(len(v)-1 for v in multi_clusters.values()):,}")

print(f"\n  Sample clusters (largest first):")
shown = 0
for root, members in sorted(multi_clusters.items(), key=lambda x: -len(x[1])):
    if shown >= 20:
        break
    print(f"    cluster: {members}")
    shown += 1

# ── Step 6: Coverage improvement estimate ─────────────────────────────────────
print(f"\n[7] Coverage improvement: how many val items now match a training cluster?",
      flush=True)

# Build mapping: item_name -> canonical name (lowest alphabetically in cluster)
canonical = {}
for root, members in clusters.items():
    canon = min(members)
    for m in members:
        canonical[m] = canon

# Encode validation names and find closest training cluster
print(f"  Encoding {len(val_names):,} validation names...", flush=True)
val_list = list(val_names)
val_emb  = model.encode(val_list, batch_size=256, show_progress_bar=True,
                         convert_to_numpy=True)

# For each val item not in training, find best training match above threshold
unmatched_val = [n for n in val_list if n not in train_names]
print(f"  Unmatched validation items: {len(unmatched_val):,}", flush=True)

unmatched_idx = [i for i, n in enumerate(val_list) if n not in train_names]
unmatched_emb = val_emb[unmatched_idx]

# Batched to avoid OOM on large unmatched sets
best_sim = np.zeros(len(unmatched_emb))
best_idx = np.zeros(len(unmatched_emb), dtype=int)
for i in range(0, len(unmatched_emb), batch_size):
    batch = unmatched_emb[i:i+batch_size]
    s = cosine_similarity(batch, embeddings)
    best_sim[i:i+batch_size] = s.max(axis=1)
    best_idx[i:i+batch_size] = s.argmax(axis=1)

newly_matched = int((best_sim >= CHOSEN_THRESHOLD).sum())
print(f"  Newly matched via embeddings (≥{CHOSEN_THRESHOLD}): {newly_matched:,}")
print(f"  Total coverage: {baseline_overlap + newly_matched:,} / {len(val_names):,} "
      f"({100*(baseline_overlap+newly_matched)/len(val_names):.1f}%)")

print(f"\n  Sample new matches (val item → best training item, similarity):")
shown = 0
for vi in range(len(unmatched_val)):
    si = float(best_sim[vi])
    if si < CHOSEN_THRESHOLD:
        continue
    val_name   = unmatched_val[vi]
    train_name = all_train[int(best_idx[vi])]
    if val_name != train_name:
        print(f"    {si:.3f}  '{val_name}'  →  '{train_name}'")
        shown += 1
        if shown >= 25:
            break

# Distribution of best similarities for unmatched items
print(f"\n  Similarity distribution for {len(unmatched_val):,} unmatched val items:")
for thresh, label in [(0.95,'≥0.95'),(0.90,'≥0.90'),(0.85,'≥0.85'),
                      (0.80,'≥0.80'),(0.70,'≥0.70'),(0.0,'total')]:
    cnt = sum(1 for s in best_sim if s >= thresh)
    bar = '█' * max(1, int(cnt / max(len(unmatched_val),1) * 30))
    print(f"    {label}: {cnt:5,}  {bar}")

print("\n" + "=" * 70)
print("Summary:")
print(f"  Training items:          {len(train_names):,}")
print(f"  Trivial deduplication:   removes {n_trivial_dupes:,} items "
      f"({100*n_trivial_dupes/len(train_names):.1f}%)")
print(f"  Embedding clusters @0.9: {len(multi_clusters):,} clusters merge "
      f"{sum(len(v)-1 for v in multi_clusters.values()):,} items")
print(f"  Val coverage baseline:   {baseline_overlap:,}/{len(val_names):,} "
      f"({100*baseline_overlap/len(val_names):.1f}%)")
print(f"  Val coverage +embedding: {baseline_overlap+newly_matched:,}/{len(val_names):,} "
      f"({100*(baseline_overlap+newly_matched)/len(val_names):.1f}%)")
print("=" * 70)
