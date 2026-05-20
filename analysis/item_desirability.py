#!/usr/bin/env python3
"""
Item Desirability Analysis

Uses historical Freegle data to model how desirable different item types are,
measured by the average number of 'Interested' replies per OFFER post.

Performance strategy: create a MySQL temp table of message IDs for the period,
then JOIN chat_messages against it so MySQL can use the indexed refmsgid column
without scanning the whole 36M-row table.
"""

import pymysql
import pandas as pd
import numpy as np
from scipy import stats

DB = dict(host='127.0.0.1', port=11234, user='root',
          password='F5432f12azfvds', db='iznik', charset='utf8mb4',
          autocommit=True)

def get_conn():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

# ── SQL ───────────────────────────────────────────────────────────────────────

CREATE_TMP = """
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_offer_msgs (
    msgid BIGINT NOT NULL,
    item_name VARCHAR(255),
    PRIMARY KEY (msgid)
)
"""

POPULATE_TMP = """
INSERT INTO tmp_offer_msgs (msgid, item_name)
SELECT m.id, i.name
FROM messages m
JOIN messages_items mi ON mi.msgid = m.id
JOIN items i ON i.id = mi.itemid
WHERE m.type = 'Offer'
  AND m.arrival BETWEEN %s AND %s
  AND i.name IS NOT NULL
"""

CREATE_REPLY_COUNTS = """
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_reply_counts AS
SELECT cm.refmsgid AS msgid, COUNT(*) AS replies
FROM chat_messages cm
JOIN tmp_offer_msgs t ON cm.refmsgid = t.msgid
WHERE cm.type = 'Interested'
  AND cm.reviewrequired = 0
  AND cm.reviewrejected = 0
GROUP BY cm.refmsgid
"""

AGGREGATE = """
SELECT
    t.item_name,
    COUNT(DISTINCT t.msgid)              AS num_posts,
    SUM(COALESCE(rc.replies,0))          AS total_replies,
    AVG(COALESCE(rc.replies,0))          AS avg_replies,
    SUM(COALESCE(rc.replies,0) > 0)      AS posts_with_reply,
    ROUND(100 * SUM(COALESCE(rc.replies,0) > 0)
              / COUNT(DISTINCT t.msgid), 1) AS reply_rate_pct
FROM tmp_offer_msgs t
LEFT JOIN tmp_reply_counts rc ON rc.msgid = t.msgid
GROUP BY t.item_name
HAVING COUNT(DISTINCT t.msgid) >= %s
ORDER BY avg_replies DESC
"""

def exec_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.rowcount

def read_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()
    return pd.DataFrame(rows)

def run_period(start, end, min_posts, label):
    print(f"\n  Period: {start} → {end}  (min {min_posts} posts per item)", flush=True)
    conn = get_conn()
    try:
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_offer_msgs")
        exec_sql(conn, CREATE_TMP)
        exec_sql(conn, "ALTER TABLE tmp_offer_msgs DISABLE KEYS")
        print(f"  Loading messages into temp table...", flush=True)
        n = exec_sql(conn, POPULATE_TMP, (start, end))
        exec_sql(conn, "ALTER TABLE tmp_offer_msgs ENABLE KEYS")
        print(f"  → {n:,} messages loaded", flush=True)
        print(f"  Computing reply counts (indexed join)...", flush=True)
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_reply_counts")
        exec_sql(conn, CREATE_REPLY_COUNTS)
        print(f"  Aggregating by item...", flush=True)
        df = read_sql(conn, AGGREGATE, (min_posts,))
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_reply_counts")
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_offer_msgs")
    finally:
        conn.close()
    for col in df.columns:
        if col != 'item_name':
            df[col] = pd.to_numeric(df[col], errors='coerce')
    print(f"  → {len(df):,} item types  ({df['num_posts'].sum():,.0f} posts covered)", flush=True)
    return df

def bayesian_score(row, global_mean, k=20):
    return (row['total_replies'] + k * global_mean) / (row['num_posts'] + k)

def print_table(df, n, label, has_score=True):
    width = 70
    print(f"\n── {label} {'─'*(width-4-len(label))}")
    if has_score:
        print(f"{'Item':<32} {'Posts':>7} {'AvgReply':>9} {'Score':>7} {'ReplyRate%':>11}")
        print("-" * width)
    else:
        print(f"{'Item':<32} {'Posts':>7} {'AvgReply':>9} {'ReplyRate%':>11}")
        print("-" * 62)
    for _, r in df.head(n).iterrows():
        pct = (f"{float(r['reply_rate_pct']):>10.1f}%"
               if pd.notna(r.get('reply_rate_pct')) else "       nan%")
        if has_score:
            print(f"{str(r['item_name']):<32} {int(r['num_posts']):>7} "
                  f"{float(r['avg_replies']):>9.2f} {float(r['desirability']):>7.3f} {pct}")
        else:
            print(f"{str(r['item_name']):<32} {int(r['num_posts']):>7} "
                  f"{float(r['avg_replies']):>9.2f} {pct}")

def main():
    print("=" * 70)
    print("Freegle Item Desirability Analysis")
    print("=" * 70)

    # ── Step 1: Training ─────────────────────────────────────────────────────
    print("\n[1] TRAINING: 2023-01-01 → 2024-06-30")
    train_df = run_period('2023-01-01', '2024-06-30', min_posts=5, label='training')

    global_mean = float(train_df['avg_replies'].mean())
    print(f"  Global mean replies/offer: {global_mean:.3f}")

    train_df['desirability'] = train_df.apply(
        bayesian_score, axis=1, global_mean=global_mean, k=20
    )
    train_df = train_df.sort_values('desirability', ascending=False).reset_index(drop=True)

    print_table(train_df, 35, "Top 35 most desirable items (training 2023–2024)")
    print_table(train_df[train_df['num_posts'] >= 20].tail(25), 25,
                "Bottom 25 least desirable (min 20 posts)")

    model = dict(zip(train_df['item_name'].astype(str), train_df['desirability'].astype(float)))

    # ── Step 2: Validation ───────────────────────────────────────────────────
    print("\n[2] VALIDATION: 2021-01-01 → 2022-12-31")
    val_df = run_period('2021-01-01', '2022-12-31', min_posts=5, label='validation')

    val_df['item_name'] = val_df['item_name'].astype(str)
    val_df['predicted'] = val_df['item_name'].map(model).fillna(global_mean)
    shared = val_df['item_name'].isin(model)
    n_known = int(shared.sum())

    print(f"  Validation item types: {len(val_df):,}")
    print(f"  In training model: {n_known:,} ({100*n_known/len(val_df):.1f}%)")

    rho, pval = stats.spearmanr(val_df['predicted'], val_df['avg_replies'])
    mae  = float(np.mean(np.abs(val_df['predicted'] - val_df['avg_replies'])))
    base = float(np.mean(np.abs(global_mean - val_df['avg_replies'])))

    print("\n── Validation metrics ─────────────────────────────────────────────────")
    print(f"  [All items, n={len(val_df):,}, unknowns → global mean]")
    print(f"  Spearman ρ: {rho:.4f}  (p={pval:.2e})")
    print(f"  MAE: {mae:.3f}  Baseline: {base:.3f}  Improvement: {100*(base-mae)/base:.1f}%")

    known_val = val_df[shared].copy()
    if len(known_val) >= 10:
        rho_k, pval_k = stats.spearmanr(known_val['predicted'], known_val['avg_replies'])
        mae_k  = float(np.mean(np.abs(known_val['predicted'] - known_val['avg_replies'])))
        base_k = float(np.mean(np.abs(global_mean - known_val['avg_replies'])))
        print(f"\n  [Known items only, n={len(known_val):,}]")
        print(f"  Spearman ρ: {rho_k:.4f}  (p={pval_k:.2e})")
        print(f"  MAE: {mae_k:.3f}  Baseline: {base_k:.3f}  Improvement: {100*(base_k-mae_k)/base_k:.1f}%")

    # ── Step 3: Quintile stability ───────────────────────────────────────────
    print("\n[3] Quintile stability (known items only)")
    known_val['Q'] = pd.qcut(known_val['predicted'].rank(method='first'), q=5,
                              labels=['Q1 (low)','Q2','Q3','Q4','Q5 (high)'])
    q = known_val.groupby('Q', observed=True).agg(
        items=('avg_replies','count'), pred=('predicted','mean'), actual=('avg_replies','mean')
    ).reset_index()
    print(f"\n{'Q':<12} {'Items':>6} {'Pred':>8} {'Actual':>9} {'Δ':>8}")
    print("-" * 46)
    for _, r in q.iterrows():
        d = float(r['actual']) - float(r['pred'])
        print(f"{str(r['Q']):<12} {int(r['items']):>6} {float(r['pred']):>8.3f} "
              f"{float(r['actual']):>9.3f} {d:>+8.3f}")

    # ── Step 4: High-value items in validation absent from training ──────────
    print("\n[4] High-reply items in 2021-2022 NOT in training (temporal churn)")
    new_items = val_df[~shared].sort_values('avg_replies', ascending=False)
    print_table(new_items.head(20), 20,
                "Top absent items in 2021-2022", has_score=False)

    print("\n" + "=" * 70)
    print("Summary:")
    print(f"  Mean: {global_mean:.3f} replies/offer  |  Items in model: {len(train_df):,}")
    print(f"  Most desirable:  {str(train_df.iloc[0]['item_name'])} "
          f"(score {float(train_df.iloc[0]['desirability']):.2f})")
    bot = train_df[train_df['num_posts'] >= 20].iloc[-1]
    print(f"  Least desirable: {str(bot['item_name'])} "
          f"(score {float(bot['desirability']):.2f})")
    if len(known_val) >= 10:
        print(f"  Temporal ρ (known items, 2yr gap): {rho_k:.3f}")
    print("=" * 70)

if __name__ == '__main__':
    main()
