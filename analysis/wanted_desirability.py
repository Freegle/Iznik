#!/usr/bin/env python3
"""
Wanted-side desirability model.

Mirrors item_desirability.py but for WANTED posts.
Signal: whether the want was fulfilled — i.e. whether a chat_message of type
'Offer' was sent in reply (someone saw the want and offered the item).

Uses the same temp-table indexed-join strategy.
"""

import pymysql
import pandas as pd
import numpy as np
from scipy import stats

DB = dict(host='127.0.0.1', port=11234, user='root',
          password='F5432f12azfvds', db='iznik', charset='utf8mb4',
          autocommit=True)

TRAIN_START = '2023-01-01'
TRAIN_END   = '2024-06-30'
VAL_START   = '2021-01-01'
VAL_END     = '2022-12-31'
MIN_POSTS   = 5
K           = 20   # Bayesian shrinkage

def get_conn():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

def exec_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.rowcount

def read_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return pd.DataFrame(cur.fetchall())

def run_period(start, end, label):
    print(f"\n  Period: {start} → {end}", flush=True)
    conn = get_conn()
    try:
        # Load WANTED message IDs into temp table
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_wanted_msgs")
        exec_sql(conn, """
            CREATE TEMPORARY TABLE tmp_wanted_msgs (
                msgid BIGINT NOT NULL,
                item_name VARCHAR(255),
                PRIMARY KEY (msgid)
            )
        """)
        exec_sql(conn, "ALTER TABLE tmp_wanted_msgs DISABLE KEYS")
        n = exec_sql(conn, """
            INSERT INTO tmp_wanted_msgs (msgid, item_name)
            SELECT m.id, i.name
            FROM messages m
            JOIN messages_items mi ON mi.msgid = m.id
            JOIN items i ON i.id = mi.itemid
            WHERE m.type = 'Wanted'
              AND m.arrival BETWEEN %s AND %s
              AND i.name IS NOT NULL
        """, (start, end))
        exec_sql(conn, "ALTER TABLE tmp_wanted_msgs ENABLE KEYS")
        print(f"  → {n:,} WANTED messages loaded", flush=True)

        # Count 'Interested' replies to each WANTED (someone saying "I have this")
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_offer_replies")
        exec_sql(conn, """
            CREATE TEMPORARY TABLE tmp_offer_replies AS
            SELECT cm.refmsgid AS msgid, COUNT(*) AS offers
            FROM chat_messages cm
            JOIN tmp_wanted_msgs t ON cm.refmsgid = t.msgid
            WHERE cm.type = 'Interested'
              AND cm.reviewrequired = 0
              AND cm.reviewrejected = 0
            GROUP BY cm.refmsgid
        """)
        print(f"  → offer-reply counts computed", flush=True)

        # Aggregate by item
        df = read_sql(conn, """
            SELECT
                t.item_name,
                COUNT(DISTINCT t.msgid)                           AS num_posts,
                SUM(COALESCE(r.offers, 0))                        AS total_offers,
                AVG(COALESCE(r.offers, 0))                        AS avg_offers,
                SUM(COALESCE(r.offers, 0) > 0)                    AS posts_fulfilled,
                ROUND(100 * SUM(COALESCE(r.offers, 0) > 0)
                          / COUNT(DISTINCT t.msgid), 1)           AS fulfil_rate_pct
            FROM tmp_wanted_msgs t
            LEFT JOIN tmp_offer_replies r ON r.msgid = t.msgid
            GROUP BY t.item_name
            HAVING COUNT(DISTINCT t.msgid) >= %s
            ORDER BY avg_offers DESC
        """, (MIN_POSTS,))

        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_offer_replies")
        exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_wanted_msgs")
    finally:
        conn.close()

    for col in df.columns:
        if col != 'item_name':
            df[col] = pd.to_numeric(df[col], errors='coerce')
    print(f"  → {len(df):,} item types  ({df['num_posts'].sum():,.0f} WANTEDs covered)",
          flush=True)
    return df

def bayesian_score(row, global_mean, k=K):
    return (row['total_offers'] + k * global_mean) / (row['num_posts'] + k)

def print_table(df, n, label):
    print(f"\n── {label} {'─'*(60-len(label))}")
    print(f"{'Item':<32} {'Posts':>7} {'FulfilRate%':>12} {'Score':>7}")
    print("-" * 62)
    for _, r in df.head(n).iterrows():
        print(f"{str(r['item_name']):<32} {int(r['num_posts']):>7} "
              f"{float(r['fulfil_rate_pct']):>11.1f}% {float(r['score']):>7.3f}")

# ── Main ──────────────────────────────────────────────────────────────────────
print("=" * 70)
print("Freegle Wanted-Side Desirability Analysis")
print("=" * 70)

# First: check what reply types exist on WANTED messages
print("\n[0] Checking chat_message types on WANTED posts...", flush=True)
conn = get_conn()
types_df = read_sql(conn, """
    SELECT cm.type, COUNT(*) AS cnt
    FROM chat_messages cm
    JOIN messages m ON m.id = cm.refmsgid
    WHERE m.type = 'Wanted'
      AND m.arrival BETWEEN %s AND %s
    GROUP BY cm.type
    ORDER BY cnt DESC
    LIMIT 10
""", (TRAIN_START, TRAIN_END))
conn.close()
print("  Reply types on WANTED posts:")
for _, r in types_df.iterrows():
    print(f"    {str(r['type']):<20} {int(r['cnt']):>10,}")

print("\n[1] TRAINING: 2023-01-01 → 2024-06-30")
train_df = run_period(TRAIN_START, TRAIN_END, 'training')

if len(train_df) == 0:
    print("\n  No data — WANTED reply signal may use a different type. See [0] above.")
else:
    global_mean = float(train_df['avg_offers'].mean())
    print(f"\n  Global mean offers/WANTED: {global_mean:.3f}")
    print(f"  Global fulfil rate: "
          f"{100*float((train_df['posts_fulfilled'] > 0).mean()):.1f}% of WANTEDs get ≥1 offer reply")

    train_df['score'] = train_df.apply(bayesian_score, axis=1, global_mean=global_mean)
    train_df = train_df.sort_values('score', ascending=False).reset_index(drop=True)

    print_table(train_df, 30, "Most fulfillable WANTEDs (training 2023–2024)")
    print_table(train_df[train_df['num_posts'] >= 10].tail(25), 25,
                "Least fulfillable WANTEDs (min 10 posts)")

    model = dict(zip(train_df['item_name'].astype(str),
                     train_df['score'].astype(float)))

    print("\n[2] VALIDATION: 2021-01-01 → 2022-12-31")
    val_df = run_period(VAL_START, VAL_END, 'validation')

    val_df['item_name'] = val_df['item_name'].astype(str)
    val_df['predicted'] = val_df['item_name'].map(model).fillna(global_mean)
    shared = val_df['item_name'].isin(model)
    n_known = int(shared.sum())

    print(f"\n  Validation item types: {len(val_df):,}")
    print(f"  In training model: {n_known:,} ({100*n_known/len(val_df):.1f}%)")

    rho, pval = stats.spearmanr(val_df['predicted'], val_df['avg_offers'])
    print(f"\n  Spearman ρ (all items): {rho:.4f}  (p={pval:.2e})")

    known_val = val_df[shared].copy()
    if len(known_val) >= 10:
        rho_k, pval_k = stats.spearmanr(known_val['predicted'], known_val['avg_offers'])
        print(f"  Spearman ρ (known items only, n={len(known_val):,}): {rho_k:.4f}")

    # Quintile check
    known_val['Q'] = pd.qcut(known_val['predicted'].rank(method='first'), q=5,
                              labels=['Q1','Q2','Q3','Q4','Q5'])
    q = known_val.groupby('Q', observed=True).agg(
        items=('avg_offers','count'),
        pred=('predicted','mean'),
        actual=('avg_offers','mean')
    ).reset_index()
    print(f"\n  Quintile ordering (known items):")
    print(f"  {'Q':<6} {'Items':>6} {'Pred':>8} {'Actual':>9}")
    print("  " + "-" * 34)
    for _, r in q.iterrows():
        print(f"  {str(r['Q']):<6} {int(r['items']):>6} {float(r['pred']):>8.3f} "
              f"{float(r['actual']):>9.3f}")

    print("\n" + "=" * 70)
    print("Summary:")
    print(f"  Global mean: {global_mean:.3f} offer-replies/WANTED")
    print(f"  Items in model: {len(train_df):,}")
    print(f"  Most fulfillable: {train_df.iloc[0]['item_name']} "
          f"(score {float(train_df.iloc[0]['score']):.3f})")
    bot = train_df[train_df['num_posts'] >= 10].iloc[-1]
    print(f"  Least fulfillable: {bot['item_name']} "
          f"(score {float(bot['score']):.3f})")
    if len(known_val) >= 10:
        print(f"  Temporal ρ (known items): {rho_k:.3f}")
    print("=" * 70)
