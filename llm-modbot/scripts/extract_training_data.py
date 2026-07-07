#!/usr/bin/env python3
"""Extract moderation training data from Freegle production DB.

Produces three JSONL files:
  - rejections.jsonl: messages that were rejected, with reasons
  - approvals.jsonl: messages that were approved (balanced sample)
  - edits.jsonl: mod edits to subjects and text

Each line is a JSON object with instruction/output pairs for fine-tuning.
"""

import json
import os
import sys
import random
from datetime import datetime, timedelta

import pymysql

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 11234,
    "user": "root",
    "password": os.environ.get("LIVE_DB_PASSWORD", ""),
    "database": "iznik",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}

OUTPUT_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "data")

# Normalize rejection reasons into clean categories
REASON_MAP = {
    "duplicate": "duplicate",
    "duplicate message": "duplicate",
    "duplicate/edit original": "duplicate",
    "duplicate post": "duplicate",
    "reject - duplicate post": "duplicate",
    "reject possible duplicate": "duplicate",
    "duplicate posting": "duplicate",
    "reject excessive crossposting": "duplicate",
    "early repeat messages": "repeat_too_soon",
    "leave longer gap, offer's": "repeat_too_soon",
    "leave longer gap, wanted's": "repeat_too_soon",
    "too soon requested": "repeat_too_soon",
    "repeat offer post": "repeat_too_soon",
    "wanted repeated too soon": "repeat_too_soon",
    "reject - more than 1 wanted per day": "repeat_too_soon",
    "blank letter - reject": "blank",
    "blank email (reject)": "blank",
    "blank": "blank",
    "out of area": "out_of_area",
    "out of area - reject": "out_of_area",
    "not in our freegle area": "out_of_area",
    "out of area / crossposted": "out_of_area",
    "non landfill items-": "non_freegle_item",
    "non landfill items": "non_freegle_item",
    "medicines": "medicines",
    "selling -": "selling",
    "too vague, no reply to more info msg": "too_vague",
    "**non-standard message**": "non_standard",
    "offer - no area/post code (not wanted's)": "missing_location",
}


def normalize_reason(title):
    if title is None:
        return "other"
    key = title.strip().lower().rstrip()
    return REASON_MAP.get(key, "other")


def clean_text(text):
    """Remove excessive whitespace and normalize."""
    if not text:
        return ""
    # Replace multiple newlines/spaces
    import re
    text = re.sub(r'\n{3,}', '\n\n', text)
    text = re.sub(r' {3,}', ' ', text)
    return text.strip()


def _get_worry_words(conn):
    """Load all worry words for matching against message text."""
    with conn.cursor() as cur:
        cur.execute("SELECT keyword, type FROM worrywords")
        return cur.fetchall()


def _check_worry_words(text, worry_words):
    """Check if message text contains any worry words."""
    if not text:
        return []
    text_lower = text.lower()
    matches = []
    for ww in worry_words:
        if ww["keyword"].lower() in text_lower:
            matches.append(f"{ww['keyword']} ({ww['type']})")
    return matches[:5]  # cap at 5 to keep prompt manageable


def _format_mod_context(row):
    """Build the moderator context section from enriched row data."""
    lines = []

    # Group info
    lines.append(f"Group: {row['group_name']}")
    if row.get("group_rules"):
        rules = clean_text(row["group_rules"])
        if len(rules) > 300:
            rules = rules[:300] + "..."
        lines.append(f"Group rules: {rules}")

    # Message info
    lines.append(f"Post type: {row['type']}")
    lines.append(f"Subject: {row['subject']}")
    lines.append(f"Body: {clean_text(row.get('body') or '')}")

    # Source
    if row.get("source"):
        lines.append(f"Source: {row['source']}")

    # Member context
    member_days = row.get("member_days")
    if member_days is not None:
        if member_days < 31:
            lines.append(f"Member for: {member_days} days (NEW MEMBER)")
        else:
            lines.append(f"Member for: {member_days} days")

    posting_status = row.get("ourpostingstatus")
    if posting_status and posting_status != "DEFAULT":
        lines.append(f"Posting status: {posting_status}")

    posts_31d = row.get("posts_last_31d")
    if posts_31d is not None:
        lines.append(f"Posts in last 31 days: {posts_31d}")

    # Cross-posting
    crosspost_count = row.get("crosspost_count", 1)
    if crosspost_count and crosspost_count > 1:
        lines.append(f"Cross-posted to {crosspost_count} groups")

    # Worry words
    worry_matches = row.get("worry_matches")
    if worry_matches:
        lines.append(f"Flagged words: {', '.join(worry_matches)}")

    return "\n".join(lines)


def extract_rejections(conn, days=365):
    """Extract rejected messages with full moderator context."""
    print(f"Extracting rejections from last {days} days...")
    worry_words = _get_worry_words(conn)

    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                m.id,
                m.subject,
                LEFT(m.textbody, 1500) as body,
                m.type,
                m.source,
                g.nameshort as group_name,
                LEFT(g.rules, 500) as group_rules,
                sm.title as rejection_reason,
                l.timestamp,
                mb.ourpostingstatus,
                DATEDIFF(l.timestamp, mb.added) as member_days,
                (SELECT COUNT(*) FROM messages_groups mg2
                 JOIN messages m2 ON mg2.msgid = m2.id
                 WHERE m2.fromuser = m.fromuser
                 AND mg2.arrival >= DATE_SUB(m.arrival, INTERVAL 31 DAY)
                 AND mg2.arrival < m.arrival) as posts_last_31d,
                (SELECT COUNT(*) FROM messages_groups mg3
                 WHERE mg3.msgid = m.id) as crosspost_count,
                (SELECT LEFT(cm.message, 500) FROM chat_messages cm
                 JOIN chat_rooms cr ON cm.chatid = cr.id
                 WHERE cr.chattype = 'User2Mod' AND cm.refmsgid = m.id
                 AND cm.type = 'ModMail' LIMIT 1) as mod_message
            FROM logs l
            JOIN messages m ON l.msgid = m.id
            JOIN messages_groups mg ON m.id = mg.msgid
            JOIN `groups` g ON mg.groupid = g.id
            LEFT JOIN mod_stdmsgs sm ON l.stdmsgid = sm.id
            LEFT JOIN memberships mb ON m.fromuser = mb.userid AND mg.groupid = mb.groupid
            WHERE l.type = 'Message' AND l.subtype = 'Rejected'
            AND l.timestamp >= DATE_SUB(NOW(), INTERVAL %s DAY)
            ORDER BY l.timestamp DESC
        """, (days,))
        rows = cur.fetchall()

    print(f"  Found {len(rows)} rejections")
    results = []
    for row in rows:
        category = normalize_reason(row["rejection_reason"])
        reason_text = row["rejection_reason"] or "No standard reason given"

        # Check worry words
        full_text = f"{row['subject'] or ''} {row['body'] or ''}"
        row["worry_matches"] = _check_worry_words(full_text, worry_words)

        instruction = (
            "You are a Freegle community moderator. Review this post and decide whether to approve or reject it.\n\n"
            + _format_mod_context(row)
        )

        mod_msg = ""
        if row["mod_message"]:
            mod_msg = f"\nModerator message to user: {clean_text(row['mod_message'])}"

        output = (
            f"REJECT\n"
            f"Reason: {reason_text}\n"
            f"Category: {category}"
            f"{mod_msg}"
        )

        results.append({
            "instruction": instruction,
            "output": output,
            "meta": {
                "msg_id": row["id"],
                "timestamp": row["timestamp"].isoformat() if row["timestamp"] else None,
                "task": "moderation",
                "action": "reject",
                "category": category,
            }
        })

    return results


def _make_approval_reason(msg_type, subject, body):
    """Generate a varied, post-specific approval reason.

    The key insight: if all approval outputs are identical, the model learns
    nothing about WHY a post is approvable — and collapses to always rejecting.
    """
    reasons = []
    body_text = (body or "").lower()
    subject_text = (subject or "").lower()

    # Post type
    if msg_type == "Offer":
        reasons.append("Valid offer")
    elif msg_type == "Wanted":
        reasons.append("Valid wanted post")
    else:
        reasons.append("Valid post")

    # Specific item mentioned
    if subject and ":" in subject:
        item_part = subject.split(":", 1)[1].split("(")[0].strip()
        if item_part and len(item_part) > 2:
            reasons.append(f"specific item listed ({item_part[:40]})")

    # Location present
    if "(" in subject_text and ")" in subject_text:
        reasons.append("location included")

    # Body content quality
    if body and len(body) > 50:
        reasons.append("adequate description")
    elif body and len(body) > 10:
        reasons.append("brief but sufficient description")

    # Collection info
    if any(w in body_text for w in ["collect", "pick up", "pickup", "collection"]):
        reasons.append("collection details provided")

    # Condition described
    if any(w in body_text for w in ["good condition", "working", "used", "new", "clean"]):
        reasons.append("condition described")

    # No red flags
    no_red_flags = []
    if not any(w in body_text for w in ["sell", "price", "£", "pay", "buy", "cost"]):
        no_red_flags.append("no selling language")
    if not any(w in body_text for w in ["borrow", "lend", "loan"]):
        no_red_flags.append("no borrowing language")
    if no_red_flags:
        reasons.append(no_red_flags[0])

    reason_text = "; ".join(reasons[:3])  # Keep it concise
    return f"APPROVE\nReason: {reason_text}"


def extract_approvals(conn, days=90, limit=20000):
    """Extract approved messages with full moderator context."""
    print(f"Extracting approved messages from last {days} days (limit {limit})...")
    worry_words = _get_worry_words(conn)

    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                m.id,
                m.subject,
                LEFT(m.textbody, 1500) as body,
                m.type,
                m.source,
                g.nameshort as group_name,
                LEFT(g.rules, 500) as group_rules,
                mg.arrival as timestamp,
                mb.ourpostingstatus,
                DATEDIFF(mg.arrival, mb.added) as member_days,
                (SELECT COUNT(*) FROM messages_groups mg2
                 JOIN messages m2 ON mg2.msgid = m2.id
                 WHERE m2.fromuser = m.fromuser
                 AND mg2.arrival >= DATE_SUB(m.arrival, INTERVAL 31 DAY)
                 AND mg2.arrival < m.arrival) as posts_last_31d,
                (SELECT COUNT(*) FROM messages_groups mg3
                 WHERE mg3.msgid = m.id) as crosspost_count
            FROM messages m
            JOIN messages_groups mg ON m.id = mg.msgid
            JOIN `groups` g ON mg.groupid = g.id
            LEFT JOIN memberships mb ON m.fromuser = mb.userid AND mg.groupid = mb.groupid
            WHERE mg.collection = 'Approved'
            AND mg.arrival >= DATE_SUB(NOW(), INTERVAL %s DAY)
            AND m.textbody IS NOT NULL
            AND LENGTH(m.textbody) > 10
            ORDER BY RAND()
            LIMIT %s
        """, (days, limit))
        rows = cur.fetchall()

    print(f"  Found {len(rows)} approved messages")
    results = []
    for row in rows:
        # Check worry words
        full_text = f"{row['subject'] or ''} {row['body'] or ''}"
        row["worry_matches"] = _check_worry_words(full_text, worry_words)

        instruction = (
            "You are a Freegle community moderator. Review this post and decide whether to approve or reject it.\n\n"
            + _format_mod_context(row)
        )

        # Generate varied approval reasons based on the post content
        output = _make_approval_reason(row['type'], row['subject'], row['body'])

        results.append({
            "instruction": instruction,
            "output": output,
            "meta": {
                "msg_id": row["id"],
                "timestamp": row["timestamp"].isoformat() if row["timestamp"] else None,
                "task": "moderation",
                "action": "approve",
                "category": "approved",
            }
        })

    return results


def extract_subject_edits(conn, days=365):
    """Extract moderator subject corrections."""
    print(f"Extracting subject edits from last {days} days...")
    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                me.msgid,
                me.oldsubject,
                me.newsubject,
                me.timestamp,
                m.type
            FROM messages_edits me
            JOIN messages m ON me.msgid = m.id
            JOIN messages_groups mg ON m.id = mg.msgid
            WHERE me.timestamp >= DATE_SUB(NOW(), INTERVAL %s DAY)
            AND me.byuser != m.fromuser
            AND me.oldsubject IS NOT NULL
            AND me.newsubject IS NOT NULL
            AND me.oldsubject != me.newsubject
            GROUP BY me.msgid, me.oldsubject, me.newsubject
            ORDER BY me.timestamp DESC
        """, (days,))
        rows = cur.fetchall()

    print(f"  Found {len(rows)} subject edits")
    results = []
    for row in rows:
        instruction = (
            f"Fix any spelling, formatting, or content errors in this Freegle post subject. "
            f"Only fix clear errors, don't change meaning. If the subject is correct, return it unchanged.\n\n"
            f"Subject: {row['oldsubject']}"
        )

        # Determine what changed
        changes = []
        if row["oldsubject"].lower() != row["newsubject"].lower():
            changes.append("spelling/content corrected")
        elif row["oldsubject"] != row["newsubject"]:
            changes.append("capitalisation fixed")

        output = f"{row['newsubject']}"
        if changes:
            output += f"\nChanges: {', '.join(changes)}"

        results.append({
            "instruction": instruction,
            "output": output,
            "meta": {
                "msg_id": row["msgid"],
                "timestamp": row["timestamp"].isoformat() if row["timestamp"] else None,
                "task": "subject_correction",
            }
        })

    return results


def extract_text_edits(conn, days=365, limit=15000):
    """Extract moderator text cleanup edits."""
    print(f"Extracting text edits from last {days} days (limit {limit})...")
    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                me.msgid,
                LEFT(me.oldtext, 1500) as old_text,
                LEFT(me.newtext, 1500) as new_text,
                me.oldsubject,
                me.timestamp,
                m.type,
                g.nameshort as group_name
            FROM messages_edits me
            JOIN messages m ON me.msgid = m.id
            JOIN messages_groups mg ON m.id = mg.msgid
            JOIN `groups` g ON mg.groupid = g.id
            WHERE me.timestamp >= DATE_SUB(NOW(), INTERVAL %s DAY)
            AND me.byuser != m.fromuser
            AND me.oldtext IS NOT NULL
            AND me.newtext IS NOT NULL
            AND me.oldtext != me.newtext
            AND LENGTH(me.oldtext) > 10
            GROUP BY me.msgid, me.oldtext, me.newtext
            ORDER BY me.timestamp DESC
            LIMIT %s
        """, (days, limit))
        rows = cur.fetchall()

    print(f"  Found {len(rows)} text edits")
    results = []
    for row in rows:
        instruction = (
            f"Clean up this Freegle post text. Remove: personal info (phone numbers, full postcodes, addresses), "
            f"selling/pricing language, borrowing requests, excessive sob stories. "
            f"Keep the item description intact. If no changes needed, return the text unchanged.\n\n"
            f"Post type: {row['type']}\n"
            f"Subject: {row['oldsubject']}\n"
            f"Text: {clean_text(row['old_text'] or '')}"
        )

        output = clean_text(row["new_text"] or "")

        results.append({
            "instruction": instruction,
            "output": output,
            "meta": {
                "msg_id": row["msgid"],
                "timestamp": row["timestamp"].isoformat() if row["timestamp"] else None,
                "task": "text_cleanup",
            }
        })

    return results


def write_jsonl(data, filename):
    """Write data to JSONL file, excluding meta from training."""
    filepath = os.path.join(OUTPUT_DIR, filename)
    with open(filepath, "w", encoding="utf-8") as f:
        for item in data:
            # Write full record (meta included for analysis, stripped during training)
            f.write(json.dumps(item, ensure_ascii=False) + "\n")
    print(f"  Wrote {len(data)} records to {filepath}")
    return filepath


def split_data(data, train_ratio=0.8, val_ratio=0.1):
    """Split into train/val/test sets."""
    random.shuffle(data)
    n = len(data)
    train_end = int(n * train_ratio)
    val_end = int(n * (train_ratio + val_ratio))
    return data[:train_end], data[train_end:val_end], data[val_end:]


def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    print("Connecting to production database...")
    conn = pymysql.connect(**DB_CONFIG)

    try:
        # Extract all data
        rejections = extract_rejections(conn, days=365)
        approvals = extract_approvals(conn, days=365, limit=15000)
        subject_edits = extract_subject_edits(conn, days=365)
        text_edits = extract_text_edits(conn, days=365, limit=15000)

        # Write raw extracts
        write_jsonl(rejections, "rejections_raw.jsonl")
        write_jsonl(approvals, "approvals_raw.jsonl")
        write_jsonl(subject_edits, "subject_edits_raw.jsonl")
        write_jsonl(text_edits, "text_edits_raw.jsonl")

        # Combine moderation data (approve/reject) with balanced sampling
        # Use all rejections + equal number of approvals
        n_reject = len(rejections)
        balanced_approvals = random.sample(approvals, min(n_reject * 2, len(approvals)))
        moderation_data = rejections + balanced_approvals
        print(f"\nModeration dataset: {len(rejections)} rejections + {len(balanced_approvals)} approvals = {len(moderation_data)}")

        # Split each task
        for name, data in [
            ("moderation", moderation_data),
            ("subject_correction", subject_edits),
            ("text_cleanup", text_edits),
        ]:
            if not data:
                print(f"  Skipping {name} - no data")
                continue
            train, val, test = split_data(data)
            write_jsonl(train, f"{name}_train.jsonl")
            write_jsonl(val, f"{name}_val.jsonl")
            write_jsonl(test, f"{name}_test.jsonl")
            print(f"  {name}: train={len(train)}, val={len(val)}, test={len(test)}")

        # Also create a combined training file for multi-task fine-tuning
        all_train = []
        for name in ["moderation", "subject_correction", "text_cleanup"]:
            train_file = os.path.join(OUTPUT_DIR, f"{name}_train.jsonl")
            if os.path.exists(train_file):
                with open(train_file) as f:
                    all_train.extend(json.loads(line) for line in f)
        random.shuffle(all_train)
        write_jsonl(all_train, "combined_train.jsonl")
        print(f"\nCombined training set: {len(all_train)} examples")

        # Summary
        print("\n=== EXTRACTION SUMMARY ===")
        print(f"Rejections:      {len(rejections)}")
        print(f"Approvals:       {len(approvals)} (sampled)")
        print(f"Subject edits:   {len(subject_edits)}")
        print(f"Text edits:      {len(text_edits)}")
        print(f"Combined train:  {len(all_train)}")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
