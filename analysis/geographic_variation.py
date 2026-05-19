#!/usr/bin/env python3
"""
Geographic variation in Freegle item desirability.

Measures how much reply rates vary by group (local area), and whether
the variation is driven by item mix or by genuine local demand differences.

Uses the same temp-table indexed-join strategy as item_desirability.py.
"""

import pymysql
import pandas as pd
import numpy as np
from scipy import stats

DB = dict(host='127.0.0.1', port=11234, user='root',
          password='F5432f12azfvds', db='iznik', charset='utf8mb4',
          autocommit=True)

PERIOD_START = '2023-01-01'
PERIOD_END   = '2024-06-30'

def get_conn():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

def exec_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.rowcount

def read_sql(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()
    return pd.DataFrame(rows)

def build_reply_counts(conn):
    """Populate tmp_offer_msgs and tmp_reply_counts for the analysis period."""
    exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_offer_msgs")
    exec_sql(conn, """
        CREATE TEMPORARY TABLE tmp_offer_msgs (
            msgid BIGINT NOT NULL,
            PRIMARY KEY (msgid)
        )
    """)
    exec_sql(conn, "ALTER TABLE tmp_offer_msgs DISABLE KEYS")
    n = exec_sql(conn, """
        INSERT INTO tmp_offer_msgs (msgid)
        SELECT m.id FROM messages m
        WHERE m.type = 'Offer'
          AND m.arrival BETWEEN %s AND %s
    """, (PERIOD_START, PERIOD_END))
    exec_sql(conn, "ALTER TABLE tmp_offer_msgs ENABLE KEYS")
    print(f"  → {n:,} offer messages loaded", flush=True)

    exec_sql(conn, "DROP TEMPORARY TABLE IF EXISTS tmp_reply_counts")
    exec_sql(conn, """
        CREATE TEMPORARY TABLE tmp_reply_counts AS
        SELECT cm.refmsgid AS msgid, COUNT(*) AS replies
        FROM chat_messages cm
        JOIN tmp_offer_msgs t ON cm.refmsgid = t.msgid
        WHERE cm.type = 'Interested'
          AND cm.reviewrequired = 0
          AND cm.reviewrejected = 0
        GROUP BY cm.refmsgid
    """)
    print("  → reply counts computed", flush=True)

def main():
    print("=" * 70)
    print("Geographic Variation in Freegle Item Desirability")
    print(f"Period: {PERIOD_START} → {PERIOD_END}")
    print("=" * 70)

    conn = get_conn()

    # ── Build shared reply counts ─────────────────────────────────────────────
    print("\n[0] Loading offer messages and reply counts...", flush=True)
    build_reply_counts(conn)

    # ── Group-level reply rates ───────────────────────────────────────────────
    print("\n[1] Reply rates by group (≥50 offers)...", flush=True)
    groups = read_sql(conn, """
        SELECT
            g.id                                    AS group_id,
            g.nameshort                             AS group_name,
            g.membercount,
            COUNT(DISTINCT m.id)                    AS num_offers,
            SUM(COALESCE(rc.replies, 0))            AS total_replies,
            AVG(COALESCE(rc.replies, 0))            AS avg_replies,
            ROUND(100 * SUM(COALESCE(rc.replies,0) > 0)
                      / COUNT(DISTINCT m.id), 1)    AS reply_rate_pct
        FROM messages m
        JOIN messages_groups mg ON mg.msgid = m.id
        JOIN `groups` g ON g.id = mg.groupid
        LEFT JOIN tmp_reply_counts rc ON rc.msgid = m.id
        WHERE m.type = 'Offer'
          AND m.arrival BETWEEN %s AND %s
          AND g.onhere = 1
        GROUP BY g.id, g.nameshort, g.membercount
        HAVING COUNT(DISTINCT m.id) >= 50
        ORDER BY avg_replies DESC
    """, (PERIOD_START, PERIOD_END))

    for col in groups.columns:
        if col != 'group_name':
            groups[col] = pd.to_numeric(groups[col], errors='coerce')

    print(f"  → {len(groups):,} qualifying groups")
    overall_mean = float(groups['avg_replies'].mean())
    overall_std  = float(groups['avg_replies'].std())
    cv = 100 * overall_std / overall_mean

    print(f"  Mean: {overall_mean:.3f}  Std: {overall_std:.3f}  CV: {cv:.1f}%")
    print(f"  Range: {groups['avg_replies'].min():.3f} – {groups['avg_replies'].max():.3f}")

    print(f"\n── Top 20 groups ──────────────────────────────────────────────────")
    print(f"{'Group':<30} {'Offers':>7} {'Members':>8} {'AvgReply':>10} {'ReplyRate%':>11}")
    print("-" * 68)
    for _, r in groups.head(20).iterrows():
        print(f"{str(r['group_name']):<30} {int(r['num_offers']):>7} "
              f"{int(r['membercount']):>8} {float(r['avg_replies']):>10.3f} "
              f"{float(r['reply_rate_pct']):>10.1f}%")

    print(f"\n── Bottom 20 groups ───────────────────────────────────────────────")
    print(f"{'Group':<30} {'Offers':>7} {'Members':>8} {'AvgReply':>10} {'ReplyRate%':>11}")
    print("-" * 68)
    for _, r in groups.tail(20).iterrows():
        print(f"{str(r['group_name']):<30} {int(r['num_offers']):>7} "
              f"{int(r['membercount']):>8} {float(r['avg_replies']):>10.3f} "
              f"{float(r['reply_rate_pct']):>10.1f}%")

    # ── Member count correlation ──────────────────────────────────────────────
    print("\n[2] Is reply rate correlated with group size?")
    rho_mem, pval_mem = stats.spearmanr(groups['membercount'], groups['avg_replies'])
    print(f"  Spearman ρ (membercount vs avg_replies): {rho_mem:.4f}  (p={pval_mem:.4f})")

    # ── Distribution ─────────────────────────────────────────────────────────
    print("\n[3] Distribution of group reply rates (percentiles)")
    for p in [10, 25, 50, 75, 90]:
        v = float(np.percentile(groups['avg_replies'], p))
        bar = "█" * max(1, int(v * 15))
        print(f"  p{p:<3} {v:.3f}  {bar}")

    # ── High vs low thirds ────────────────────────────────────────────────────
    n3 = len(groups) // 3
    high_groups = groups.nlargest(n3, 'avg_replies')
    low_groups  = groups.nsmallest(n3, 'avg_replies')
    high_mean   = float(high_groups['avg_replies'].mean())
    low_mean    = float(low_groups['avg_replies'].mean())
    ratio       = high_mean / low_mean

    print(f"\n[4] High vs low location effect")
    print(f"  Top-third groups avg:    {high_mean:.3f} replies/offer")
    print(f"  Bottom-third groups avg: {low_mean:.3f} replies/offer")
    print(f"  Ratio:                   {ratio:.2f}×")
    print(f"  (item-type ratio best/worst: ~50×)")

    # ── Item mix in high vs low groups ───────────────────────────────────────
    print("\n[5] Item mix comparison — high vs low reply groups...")

    high_ids = high_groups['group_id'].astype(int).tolist()
    low_ids  = low_groups['group_id'].astype(int).tolist()

    def item_mix(group_ids, label, n_sample=80):
        ids = group_ids[:n_sample]
        ph  = ','.join(['%s'] * len(ids))
        df  = read_sql(conn, f"""
            SELECT i.name AS item_name,
                   COUNT(DISTINCT m.id) AS num_posts,
                   AVG(COALESCE(rc.replies,0)) AS avg_replies
            FROM messages m
            JOIN messages_groups mg ON mg.msgid = m.id
            JOIN messages_items mi ON mi.msgid = m.id
            JOIN `items` i ON i.id = mi.itemid
            LEFT JOIN tmp_reply_counts rc ON rc.msgid = m.id
            WHERE m.type = 'Offer'
              AND m.arrival BETWEEN %s AND %s
              AND mg.groupid IN ({ph})
              AND i.name IS NOT NULL
            GROUP BY i.id, i.name
            HAVING COUNT(DISTINCT m.id) >= 5
            ORDER BY avg_replies DESC
        """, (PERIOD_START, PERIOD_END) + tuple(ids))
        for col in df.columns:
            if col != 'item_name':
                df[col] = pd.to_numeric(df[col], errors='coerce')
        print(f"\n  {label} (top 15 items by avg replies):")
        print(f"  {'Item':<32} {'Posts':>6} {'AvgReply':>10}")
        print("  " + "-" * 52)
        for _, r in df.head(15).iterrows():
            print(f"  {str(r['item_name']):<32} {int(r['num_posts']):>6} "
                  f"{float(r['avg_replies']):>10.2f}")
        return df

    high_items = item_mix(high_ids, f"HIGH reply groups (top {n3})")
    low_items  = item_mix(low_ids,  f"LOW reply groups (bottom {n3})")

    # Overlap: same top items in both?
    top_high = set(high_items.head(20)['item_name'].astype(str))
    top_low  = set(low_items.head(20)['item_name'].astype(str))
    overlap  = top_high & top_low
    print(f"\n  Top-20 item overlap between high and low groups: "
          f"{len(overlap)}/20 items in common")
    if overlap:
        print(f"  Common items: {', '.join(sorted(overlap))}")

    conn.close()

    print("\n" + "=" * 70)
    print("Key findings:")
    print(f"  Groups: {len(groups):,}  |  Reply rate CV: {cv:.0f}%  "
          f"|  High/low ratio: {ratio:.2f}×")
    print(f"  Member count correlation: ρ={rho_mem:.3f}")
    print(f"  Location effect ({ratio:.1f}×) is real but much smaller than "
          f"item-type effect (~50×)")
    print("=" * 70)

if __name__ == '__main__':
    main()
