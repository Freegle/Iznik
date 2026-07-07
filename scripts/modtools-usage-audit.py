#!/usr/bin/env python3
"""
Mod Tools Usage Audit
=====================
Queries Loki logs to analyse how moderators use mod tools.
Produces an HTML report with charts.

Data sources:
  - source=client, event_type=page_view     (7-8 day retention)
  - source=client, event_type=interaction   (7-8 day retention)
  - source=client, event_type=session_start (7-8 day retention)
  - source=logs_table                       (31 day retention)

Usage:
  python3 scripts/modtools-usage-audit.py [--days N] [--action-days N] [--output DIR]
"""

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.parse
import urllib.request
from collections import defaultdict, Counter
from datetime import datetime, timedelta, timezone


LOKI_URL = os.environ.get("LOKI_URL", "http://localhost:3100")


def _set_loki_url(url):
    global LOKI_URL
    LOKI_URL = url


# ── Loki query helpers ─────────────────────────────────────────────────

def query_loki(query, start_ns, end_ns, limit=5000, direction="backward"):
    params = urllib.parse.urlencode({
        "query": query, "start": str(start_ns), "end": str(end_ns),
        "limit": str(limit), "direction": direction,
    })
    url = f"{LOKI_URL}/loki/api/v1/query_range?{params}"
    try:
        with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as resp:
            data = json.loads(resp.read())
    except Exception as e:
        print(f"  Warning: query failed: {e}", file=sys.stderr)
        return []
    if data.get("status") != "success":
        return []
    entries = []
    for stream in data["data"]["result"]:
        labels = stream["stream"]
        for ts_ns, log_line in stream["values"]:
            try:
                parsed = json.loads(log_line)
            except json.JSONDecodeError:
                parsed = {"raw": log_line}
            parsed["_labels"] = labels
            parsed["_ts_ns"] = int(ts_ns)
            entries.append(parsed)
    return entries


def query_loki_complete(query, start_ns, end_ns, chunk_hours=6):
    """Query in time chunks to avoid the 5000-per-query limit."""
    all_entries = []
    chunk_ns = chunk_hours * 3600 * 1_000_000_000
    current = start_ns
    while current < end_ns:
        chunk_end = min(current + chunk_ns, end_ns)
        entries = query_loki(query, current, chunk_end, limit=5000)
        if len(entries) == 5000:
            all_entries = all_entries  # Keep what we have
            half = current + (chunk_end - current) // 2
            entries = query_loki(query, current, half, limit=5000)
            all_entries.extend(entries)
            entries = query_loki(query, half, chunk_end, limit=5000)
            all_entries.extend(entries)
        else:
            all_entries.extend(entries)
        current = chunk_end
    return all_entries


def get_time_range(days):
    now = datetime.now(timezone.utc)
    start = now - timedelta(days=days)
    return int(start.timestamp() * 1e9), int(now.timestamp() * 1e9)


def _fetch_rejection_bodies(days):
    """Fetch rejection message bodies from chat_messages via the batch container."""
    php = f"""
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=iznik', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$stmt = $pdo->query("
  SELECT cm.message as body
  FROM logs l
  INNER JOIN chat_messages cm ON cm.refmsgid = l.msgid
    AND cm.type = 'ModMail'
    AND cm.userid = l.byuser
    AND cm.date BETWEEN DATE_SUB(l.timestamp, INTERVAL 5 MINUTE) AND DATE_ADD(l.timestamp, INTERVAL 5 MINUTE)
  WHERE l.subtype = 'Rejected'
    AND l.timestamp > DATE_SUB(NOW(), INTERVAL {int(days)} DAY)
");
$bodies = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {{
    $bodies[] = $row['body'];
}}
echo json_encode($bodies);
"""
    try:
        result = subprocess.run(
            ["docker", "exec", "freegle-batch-prod", "php", "-r", php],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode == 0 and result.stdout.strip():
            return json.loads(result.stdout)
    except Exception as e:
        print(f"    Warning: Could not fetch rejection bodies: {e}", file=sys.stderr)
    return []


# ── Page classification ────────────────────────────────────────────────

PAGE_NAMES = {
    "/": "Dashboard", "/login": "Login",
    "/messages/pending": "Pending Messages", "/messages/approved": "Approved Messages",
    "/messages/edits": "Message Edits",
    "/members/review": "Member Review", "/members/approved": "Approved Members",
    "/members/feedback": "Feedback", "/members/notes": "Member Notes",
    "/members/stories": "Stories", "/members/newsletter": "Newsletter",
    "/members/microvolunteering": "Microvolunteering", "/members/related": "Related Members",
    "/chats/review": "Chat Review", "/chats": "Chats",
    "/communityevents": "Community Events", "/volunteering": "Volunteering",
    "/logs": "Mod Logs", "/map": "Group Map",
    "/settings": "Community Settings", "/admins": "Admins List",
    "/spammers": "Spammers", "/teams": "Teams",
    "/support": "Support Tools", "/publicity": "Publicity",
    "/giftaid": "Gift Aid", "/discourse": "Discourse",
    "/simulation": "Simulation", "/alert/viewed": "Alert Viewed",
}


def classify_page(page_name):
    if not page_name:
        return "Unknown"
    page = page_name.rstrip("/") or "/"
    if page in PAGE_NAMES:
        return PAGE_NAMES[page]
    for pattern, name in PAGE_NAMES.items():
        if page.startswith(pattern) and pattern != "/":
            return name
    return "Other"


# ── Analysis functions ─────────────────────────────────────────────────

def analyse_all(client_start, action_start, end_ns, days, action_days):
    """Run all analyses and return combined results."""

    # ── 1. Page views: time on page, reach, workflows, time-of-day ──
    print("\n[1/6] Page views...", file=sys.stderr)
    query = '{app="freegle", source="client", event_type="page_view"} |~ "modtools\\\\.org"'
    pv_entries = query_loki_complete(query, client_start, end_ns, chunk_hours=12)
    print(f"  {len(pv_entries)} page views", file=sys.stderr)

    by_session = defaultdict(list)
    for e in pv_entries:
        sid = e.get("session_id", "")
        if not sid:
            continue
        by_session[sid].append({
            "page": classify_page(e.get("page_name", "")),
            "ts_ns": e["_ts_ns"],
            "uid": str(e.get("user_id") or "anon"),
            "hour": int(e.get("timestamp", "T00:")[11:13]) if e.get("timestamp", "") and len(e.get("timestamp", "")) >= 14 else -1,
        })

    page_times = defaultdict(list)
    page_reach = defaultdict(set)
    page_visits = defaultdict(int)
    total_time_s = 0
    daily_time = defaultdict(float)
    daily_active = defaultdict(set)
    unique_mods = set()
    per_user_time = defaultdict(float)
    per_user_page_time = defaultdict(lambda: defaultdict(float))  # uid -> page -> seconds
    per_user_sessions = defaultdict(set)
    hour_counts = Counter()
    session_page_counts = []

    for sid, views in by_session.items():
        views.sort(key=lambda x: x["ts_ns"])
        session_page_counts.append(len(views))
        uid = views[0]["uid"] if views else "anon"
        if uid != "anon":
            per_user_sessions[uid].add(sid)

        for i, v in enumerate(views):
            page = v["page"]
            uid = v["uid"]
            page_visits[page] += 1
            if uid != "anon":
                page_reach[page].add(uid)
                unique_mods.add(uid)
            if v["hour"] >= 0:
                hour_counts[v["hour"]] += 1
            day = datetime.fromtimestamp(v["ts_ns"] / 1e9, tz=timezone.utc).strftime("%Y-%m-%d")
            if uid != "anon":
                daily_active[day].add(uid)
            if i < len(views) - 1:
                gap_s = (views[i + 1]["ts_ns"] - v["ts_ns"]) / 1e9
                if 0 < gap_s <= 600:
                    total_time_s += gap_s
                    daily_time[day] += gap_s
                    if uid != "anon":
                        per_user_time[uid] += gap_s
                        per_user_page_time[uid][page] += gap_s
                    if gap_s >= 2:
                        page_times[page].append(gap_s)

    # Page time stats sorted by total hours
    page_stats = {}
    for page in page_visits:
        times = page_times.get(page, [])
        reach = len(page_reach.get(page, set()))
        total_h = sum(times) / 3600 if times else 0
        median_s = sorted(times)[len(times) // 2] if times else 0
        page_stats[page] = {
            "visits": page_visits[page], "reach": reach,
            "reach_pct": round(reach / len(unique_mods) * 100, 1) if unique_mods else 0,
            "total_hours": round(total_h, 2), "median_seconds": round(median_s, 1),
        }
    page_stats = dict(sorted(page_stats.items(), key=lambda x: -x[1]["total_hours"]))

    # Mod commitment distribution
    user_hours = sorted(per_user_time.values(), reverse=True)
    total_h = sum(user_hours)
    commitment = {
        "power_gt2h": {"count": 0, "hours": 0},
        "regular_30m_2h": {"count": 0, "hours": 0},
        "light_10_30m": {"count": 0, "hours": 0},
        "minimal_lt10m": {"count": 0, "hours": 0},
    }
    for h in user_hours:
        hrs = h / 3600
        if hrs > 2:
            commitment["power_gt2h"]["count"] += 1
            commitment["power_gt2h"]["hours"] += hrs
        elif hrs >= 0.5:
            commitment["regular_30m_2h"]["count"] += 1
            commitment["regular_30m_2h"]["hours"] += hrs
        elif hrs >= 10 / 60:
            commitment["light_10_30m"]["count"] += 1
            commitment["light_10_30m"]["hours"] += hrs
        else:
            commitment["minimal_lt10m"]["count"] += 1
            commitment["minimal_lt10m"]["hours"] += hrs

    # Session frequency per mod
    session_counts = sorted([len(s) for s in per_user_sessions.values()], reverse=True)
    freq_brackets = {
        "heavy_gt10_day": len([c for c in session_counts if c > 10 * days]),
        "frequent_3_10_day": len([c for c in session_counts if 3 * days <= c <= 10 * days]),
        "daily_1_3": len([c for c in session_counts if days <= c < 3 * days]),
        "few_times_week": len([c for c in session_counts if days // 2 <= c < days]),
        "occasional": len([c for c in session_counts if c < days // 2]),
    }

    # Workflows - page sequences
    transition_counts = defaultdict(int)
    for sid, views in by_session.items():
        views.sort(key=lambda x: x["ts_ns"])
        pages = [v["page"] for v in views]
        for i in range(len(pages) - 1):
            if pages[i] != pages[i + 1]:
                transition_counts[f"{pages[i]} -> {pages[i+1]}"] += 1

    # ── Mod behaviour clustering ──
    # Classify mods by where they spend their time (excluding Login/Dashboard/Auth)
    CLUSTER_PAGES = ["Pending Messages", "Chats", "Chat Review", "Message Edits",
                     "Approved Messages", "Approved Members", "Member Review",
                     "Feedback", "Support Tools", "Community Settings",
                     "Community Events", "Admins List", "Spammers"]

    mod_clusters = []
    for uid in unique_mods:
        if uid == "anon":
            continue
        times = per_user_page_time.get(uid, {})
        total = sum(times.get(p, 0) for p in CLUSTER_PAGES)
        if total < 30:  # Less than 30 seconds on clusterable pages
            continue

        # Calculate time share per area
        shares = {}
        for p in CLUSTER_PAGES:
            s = times.get(p, 0) / total if total > 0 else 0
            if s > 0.01:
                shares[p] = round(s * 100, 1)

        # Classify by dominant activity
        dominant = max(shares, key=shares.get) if shares else "Unknown"
        pending_pct = shares.get("Pending Messages", 0)
        chat_pct = shares.get("Chats", 0) + shares.get("Chat Review", 0)
        review_pct = shares.get("Member Review", 0) + shares.get("Approved Members", 0)
        edit_pct = shares.get("Message Edits", 0)
        support_pct = shares.get("Support Tools", 0)
        admin_pct = sum(shares.get(p, 0) for p in ["Community Settings", "Community Events", "Admins List"])

        if support_pct > 30:
            cluster = "Support specialist"
        elif admin_pct > 25:
            cluster = "Community admin"
        elif pending_pct > 60:
            cluster = "Message approver"
        elif chat_pct > 30:
            cluster = "Chat-focused"
        elif review_pct > 25:
            cluster = "Member reviewer"
        elif edit_pct > 20:
            cluster = "Editor/quality"
        elif pending_pct > 30 and chat_pct > 15:
            cluster = "All-rounder"
        else:
            cluster = "All-rounder"

        mod_clusters.append({
            "cluster": cluster,
            "total_s": total,
            "shares": shares,
        })

    cluster_summary = Counter(m["cluster"] for m in mod_clusters)
    cluster_time = defaultdict(float)
    for m in mod_clusters:
        cluster_time[m["cluster"]] += m["total_s"]

    # ── 2. Interactions ──
    print("\n[2/6] Interactions...", file=sys.stderr)
    query = '{app="freegle", source="client", event_type="interaction"} |~ "modtools\\\\.org"'
    int_entries = query_loki_complete(query, client_start, end_ns, chunk_hours=4)
    print(f"  {len(int_entries)} interactions", file=sys.stderr)

    click_counts = defaultdict(int)
    for e in int_entries:
        an = e.get("action_name", "")
        if an and an.startswith("click: ") and len(an) > 7 and len(an) < 50:
            click_counts[an[7:]] += 1

    # ── 3. Sessions / devices ──
    print("\n[3/6] Sessions...", file=sys.stderr)
    query = '{app="freegle", source="client", event_type="session_start"} |~ "modtools\\\\.org"'
    sess_entries = query_loki_complete(query, client_start, end_ns, chunk_hours=12)
    print(f"  {len(sess_entries)} sessions", file=sys.stderr)

    devices = Counter()
    for e in sess_entries:
        is_touch = e.get("is_touch")
        vw = e.get("viewport_width")
        if is_touch:
            devices["Mobile" if vw and int(vw) < 768 else "Tablet"] += 1
        else:
            devices["Desktop"] += 1

    # ── 4. Server-side actions ──
    print("\n[4/6] Server actions...", file=sys.stderr)
    known_mod_ids = set(str(uid) for uid in unique_mods if uid != "anon")
    print(f"  Known mod IDs: {len(known_mod_ids)}", file=sys.stderr)

    action_data = {}
    for subtype in ["Approved", "Rejected", "Hold", "Release", "Deleted",
                     "Mailed", "Edit", "Suspect", "Left", "Repost",
                     "WorryWords", "RoleChange", "NoteAdded"]:
        print(f"    {subtype}...", file=sys.stderr, end="", flush=True)
        q = f'{{app="freegle", source="logs_table", subtype="{subtype}"}}'
        entries = query_loki_complete(q, action_start, end_ns, chunk_hours=12)
        print(f" {len(entries)}", file=sys.stderr)
        action_data[subtype] = entries

    # Process actions
    per_mod_actions = defaultdict(lambda: defaultdict(int))
    subtype_totals = defaultdict(int)
    stdmsg_texts = defaultdict(list)  # stdmsgid -> list of texts for sentiment
    daily_actions = defaultdict(lambda: defaultdict(int))
    edit_by_mod = Counter()
    edit_types = Counter()
    total_edit_entries = 0
    edit_unique_msgs = set()
    member_self_edits = 0
    active_posting_members = set()
    rejection_texts = []

    for subtype, entries in action_data.items():
        for entry in entries:
            byuser = entry.get("byuser")
            user = entry.get("user")
            ts = entry.get("timestamp", "")
            text = entry.get("text", "") or ""
            stdmsgid = entry.get("stdmsgid")
            msgid = entry.get("msgid")

            # Track active posting members
            if subtype == "Approved" and user:
                active_posting_members.add(user)

            if not byuser or byuser == 0:
                # Left without byuser = voluntary departure
                if subtype == "Left":
                    continue
                # Approved without byuser = auto-approved
                if subtype == "Approved":
                    subtype_totals["Auto-approved"] += 1
                    continue
                continue

            byuser_s = str(byuser)

            # Skip self-actions (member acting on own content)
            if byuser == user and subtype in ("Left", "Deleted", "Edit"):
                if subtype == "Edit":
                    member_self_edits += 1
                continue

            # Edit: also skip non-mod editors
            if subtype == "Edit":
                total_edit_entries += 1
                if byuser_s not in known_mod_ids:
                    member_self_edits += 1
                    continue
                edit_by_mod[byuser_s] += 1
                if msgid:
                    edit_unique_msgs.add(msgid)
                if "New subject" in text:
                    edit_types["Subject"] += 1
                if "New item" in text:
                    edit_types["Item name"] += 1
                if "Text body" in text:
                    edit_types["Body text"] += 1
                if "New location" in text:
                    edit_types["Location"] += 1
                if "New type" in text:
                    edit_types["Type (Offer/Wanted)"] += 1

            # Left with byuser != user = mod removal
            if subtype == "Left":
                subtype_totals["Remove from group"] += 1
                per_mod_actions[byuser_s]["Remove from group"] += 1
                if ts:
                    daily_actions[ts[:10]]["Remove from group"] += 1
                continue

            # Deleted: type=Message is message deletion, type=User is account
            if subtype == "Deleted":
                log_type = entry.get("_labels", {}).get("type", "")
                label = "Message deleted" if log_type == "Message" else "Account deleted"
                subtype_totals[label] += 1
                per_mod_actions[byuser_s][label] += 1
                if ts:
                    daily_actions[ts[:10]][label] += 1
                continue

            per_mod_actions[byuser_s][subtype] += 1
            subtype_totals[subtype] += 1

            if stdmsgid and str(stdmsgid) != "null" and stdmsgid != 0:
                stdmsg_texts[str(stdmsgid)].append(text[:200])

            if subtype == "Rejected" and text:
                rejection_texts.append(text[:200])

            if ts:
                daily_actions[ts[:10]][subtype] += 1

    # Classify rejection reasons from chat_messages body (actual mod message)
    rejection_reasons = Counter()
    rejection_bodies = _fetch_rejection_bodies(action_days)

    if rejection_bodies:
        print(f"    Classifying {len(rejection_bodies)} rejection bodies from chat_messages", file=sys.stderr)
        for body in rejection_bodies:
            b = (body or "").lower()
            if "duplicate" in b or "two (or more) copies" in b:
                rejection_reasons["Duplicate post"] += 1
            elif any(w in b for w in ("too vague", "not clear what", "more detail",
                     "further details", "full details", "more specific",
                     "not enough information", "makes no sense", "doesn't make sense",
                     "please state", "doesn't contain much", "be specific",
                     "specific item", "what exactly", "include some information",
                     "better to ask for specific", "unclear which")):
                rejection_reasons["Too vague / needs detail"] += 1
            elif "borrow" in b or "lending" in b or "swap" in b:
                rejection_reasons["Borrowing/swapping"] += 1
            elif any(w in b for w in ("medicine", "medication", "prescription", "medicinal")):
                rejection_reasons["Medicines/legal items"] += 1
            elif ("animal" in b or ("fish" in b and "pet" not in b)) and "freegle" not in b.split("animal")[0][-20:]:
                rejection_reasons["Live animals"] += 1
            elif any(w in b for w in ("out of area", "nearer to you", "do not cover your postcode",
                     "out of our area", "isn't within the", "from out of",
                     "posting from india", "posting from the netherlands",
                     "too far from our", "your nearest group", "your local freegle",
                     "your local east", "your local ", "does not accept posts from",
                     "location the item is wanted for")):
                rejection_reasons["Out of area"] += 1
            elif any(w in b for w in ("service", "tangible item", "not a tangible",
                     "not keeping anything out of landfill", "not a physical",
                     "rehoming unwanted items", "not accept this type of product",
                     "classify an air rifle as a weapon", "don't allow messages for those",
                     "contact lenses")):
                rejection_reasons["Not a physical item"] += 1
            elif any(w in b for w in ("didn't hear", "haven't heard", "failure to reply",
                     "no reply", "not replied", "not heard from", "not approved your message",
                     "therefore decline", "therefore declined", "not able to approve",
                     "as per our message")):
                rejection_reasons["No reply from member"] += 1
            elif "repeat" in b or "too soon" in b or "early repeat" in b or "24 hours" in b or \
                 "less than a week ago" in b or "ask again on" in b:
                rejection_reasons["Repeat too soon"] += 1
            elif "cross-posted" in b or "multiple cop" in b or "cross posting" in b or \
                 "doesn't allow cross" in b or "same offer" in b and "2 years" in b:
                rejection_reasons["Cross-posting"] += 1
            elif "selling" in b or "not allow selling" in b:
                rejection_reasons["Selling (not free)"] += 1
            elif "spam" in b or "ip address" in b:
                rejection_reasons["Spam / suspicious"] += 1
            elif "marked this item as taken" in b or "deleted your membership" in b or \
                 "mark as taken" in b or "chats on the offer" in b:
                rejection_reasons["Already taken"] += 1
            elif "english" in b:
                rejection_reasons["Not in English"] += 1
            else:
                rejection_reasons["Other"] += 1
    else:
        # Fallback: classify from log text (subject line only)
        print("    No DB access; classifying from log subject lines only", file=sys.stderr)
        for text in rejection_texts:
            t = text.lower()
            if "duplicate" in t:
                rejection_reasons["Duplicate post"] += 1
            elif "too vague" in t or "too soon" in t:
                rejection_reasons["Too vague / repeat"] += 1
            elif "out of area" in t:
                rejection_reasons["Out of area"] += 1
            else:
                rejection_reasons["Unclassified (subject only)"] += 1

    # ── 5. Per-mod action profiles ──
    print("\n[5/6] Building mod profiles...", file=sys.stderr)

    mod_profiles = []
    for uid in sorted(per_mod_actions.keys()):
        actions = per_mod_actions[uid]
        total = sum(actions.values())
        if total < 5:
            continue
        edits = edit_by_mod.get(uid, 0)
        mod_profiles.append({
            "total": total,
            "edits": edits,
            "actions": dict(actions),
        })

    # Sort by total actions descending, anonymise
    mod_profiles.sort(key=lambda x: -x["total"])
    for i, p in enumerate(mod_profiles):
        p["label"] = f"Mod-{i+1:03d}"

    # Per-action population statistics
    ANALYSED_ACTIONS = ["Approved", "Rejected", "Edit", "Hold", "Release",
                        "Message deleted", "Remove from group", "Mailed", "Suspect"]
    action_population = {}
    for action in ANALYSED_ACTIONS:
        rates = []
        for p in mod_profiles:
            rate = p["actions"].get(action, 0) / p["total"] * 100
            rates.append(rate)
        rates.sort()
        n = len(rates)
        non_zero = [r for r in rates if r > 0]
        mean = sum(rates) / n if n else 0
        median = rates[n // 2] if n else 0
        p90 = rates[int(n * 0.9)] if n >= 10 else (rates[-1] if rates else 0)
        doing_it = len(non_zero)
        never = n - doing_it
        # Outlier = >3x mean AND at least 3 actions of this type
        high_outliers = sum(1 for p in mod_profiles
                           if p["actions"].get(action, 0) >= 3
                           and p["actions"].get(action, 0) / p["total"] * 100 > mean * 3)
        action_population[action] = {
            "mean_pct": round(mean, 1), "median_pct": round(median, 1),
            "p90_pct": round(p90, 1),
            "mods_who_do_it": doing_it, "mods_who_never": never,
            "high_outliers": high_outliers,
        }

    # Key behavioural flags
    rubber_stampers = sum(1 for p in mod_profiles
                         if p["total"] >= 10
                         and p["actions"].get("Approved", 0) / p["total"] > 0.98
                         and p["actions"].get("Rejected", 0) == 0)
    mods_with_10_plus = sum(1 for p in mod_profiles if p["total"] >= 10)
    rubber_stamp_pct = round(rubber_stampers / max(mods_with_10_plus, 1) * 100)

    # ── 6. Approval distribution ──
    print("\n[6/6] Computing distributions...", file=sys.stderr)
    approval_by_mod = Counter()
    for entry in action_data.get("Approved", []):
        bu = entry.get("byuser")
        if bu and bu != 0:
            approval_by_mod[str(bu)] += 1
    approval_vals = sorted(approval_by_mod.values(), reverse=True)
    total_manual_approvals = sum(approval_vals)

    return {
        "days": days, "action_days": action_days,
        "unique_mods": len(unique_mods),
        "total_page_views": len(pv_entries),
        "total_interactions": len(int_entries),
        "total_sessions": len(sess_entries),
        "total_mod_hours": round(total_time_s / 3600, 1),
        "avg_mins_per_mod_per_day": round(total_time_s / max(len(unique_mods), 1) / max(len(daily_active), 1) / 60, 1),
        "page_stats": page_stats,
        "daily_active": {d: len(u) for d, u in sorted(daily_active.items())},
        "daily_hours": {d: round(h / 3600, 1) for d, h in sorted(daily_time.items())},
        "hour_of_day": {str(h): hour_counts.get(h, 0) for h in range(24)},
        "devices": dict(devices),
        "commitment": commitment,
        "session_frequency": freq_brackets,
        "top_clicks": dict(sorted(click_counts.items(), key=lambda x: -x[1])[:25]),
        "top_transitions": dict(sorted(transition_counts.items(), key=lambda x: -x[1])[:20]),
        "mod_clusters": dict(sorted(cluster_summary.items(), key=lambda x: -x[1])),
        "mod_cluster_hours": {k: round(v / 3600, 1) for k, v in sorted(cluster_time.items(), key=lambda x: -x[1])},
        "subtype_totals": dict(sorted(subtype_totals.items(), key=lambda x: -x[1])),
        "active_posting_members": len(active_posting_members),
        "total_manual_approvals": total_manual_approvals,
        "approving_mods": len(approval_by_mod),
        "approval_concentration": {
            "top5_pct": round(sum(approval_vals[:5]) / max(total_manual_approvals, 1) * 100, 1),
            "top10_pct": round(sum(approval_vals[:10]) / max(total_manual_approvals, 1) * 100, 1),
            "top20_pct": round(sum(approval_vals[:20]) / max(total_manual_approvals, 1) * 100, 1),
        },
        "rejection_reasons": dict(rejection_reasons.most_common()),
        "edit_stats": {
            "total_edit_entries": total_edit_entries,
            "member_self_edits": member_self_edits,
            "mod_edit_entries": sum(edit_by_mod.values()),
            "mod_unique_messages": len(edit_unique_msgs),
            "mods_who_edit": len(edit_by_mod),
            "edit_types": dict(edit_types.most_common()),
            "top_editors_entry_counts": sorted(edit_by_mod.values(), reverse=True)[:20],
        },
        "mod_profiles": mod_profiles,
        "action_population": action_population,
        "rubber_stampers": rubber_stampers,
        "rubber_stamp_pct": rubber_stamp_pct,
        "mods_with_10_plus": mods_with_10_plus,
    }


# ── HTML Report ────────────────────────────────────────────────────────

def generate_html(r, output_dir):
    days = r["days"]
    action_days = r["action_days"]

    # Chart data prep
    ps = r["page_stats"]
    page_names = list(ps.keys())
    page_hours = [ps[p]["total_hours"] for p in page_names]

    hours_labels = [f"{h:02d}:00" for h in range(24)]
    hours_values = [r["hour_of_day"].get(str(h), 0) for h in range(24)]

    dev_labels = list(r["devices"].keys())
    dev_values = list(r["devices"].values())


    commit = r["commitment"]
    commit_labels = ["Power (>2h/wk)", "Regular (30m-2h)", "Light (10-30m)", "Minimal (<10m)"]
    commit_counts = [commit["power_gt2h"]["count"], commit["regular_30m_2h"]["count"],
                     commit["light_10_30m"]["count"], commit["minimal_lt10m"]["count"]]
    commit_hours = [round(commit["power_gt2h"]["hours"], 1), round(commit["regular_30m_2h"]["hours"], 1),
                    round(commit["light_10_30m"]["hours"], 1), round(commit["minimal_lt10m"]["hours"], 1)]

    sf = r["session_frequency"]
    sf_labels = [">10/day", "3-10/day", "1-3/day", "Few times/week", "Occasional"]
    sf_values = [sf["heavy_gt10_day"], sf["frequent_3_10_day"], sf["daily_1_3"],
                 sf["few_times_week"], sf["occasional"]]


    # Rejection reasons
    rej_labels = list(r["rejection_reasons"].keys())
    rej_values = list(r["rejection_reasons"].values())

    # Edit types
    et = r["edit_stats"]["edit_types"]
    edit_labels = list(et.keys())
    edit_values = list(et.values())

    # Action profile deviation chart data
    ap = r.get("action_population", {})
    ap_labels = list(ap.keys())
    ap_means = [ap[a]["mean_pct"] for a in ap_labels]
    ap_p90s = [ap[a]["p90_pct"] for a in ap_labels]
    ap_outliers = [ap[a]["high_outliers"] for a in ap_labels]
    ap_never = [ap[a]["mods_who_never"] for a in ap_labels]

    # Key proportions
    st = r["subtype_totals"]
    approved = st.get("Approved", 0) + st.get("Auto-approved", 0)
    rejected = st.get("Rejected", 0)
    total_messages = approved + rejected
    removed = st.get("Remove from group", 0)
    deleted = st.get("Deleted", 0)
    members = r["active_posting_members"]
    es = r["edit_stats"]
    top_ed = es["top_editors_entry_counts"]

    approval_rate = round(approved / max(total_messages, 1) * 100, 1)
    rejection_rate = round(rejected / max(total_messages, 1) * 100, 1)
    edit_rate = round(es["mod_unique_messages"] / max(approved, 1) * 100, 1)
    removed_rate = round(removed / max(members, 1) * 100, 1)
    deleted_rate = round(deleted / max(members, 1) * 100, 1)

    html = f"""<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mod Tools Usage Audit — {datetime.now().strftime('%Y-%m-%d')}</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; color: #333; line-height: 1.5; }}
  .container {{ max-width: 1100px; margin: 0 auto; }}
  h1 {{ color: #2c5f2d; border-bottom: 3px solid #2c5f2d; padding-bottom: 10px; }}
  h2 {{ color: #2c5f2d; margin-top: 40px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }}
  h3 {{ color: #555; margin-top: 25px; }}
  .grid {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 15px 0; }}
  .card {{ background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }}
  .card .num {{ font-size: 1.8em; font-weight: bold; color: #2c5f2d; }}
  .card .lbl {{ color: #666; font-size: 0.85em; }}
  .chart-box {{ background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 15px 0; }}
  .row {{ display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }}
  @media (max-width: 800px) {{ .row {{ grid-template-columns: 1fr; }} }}
  .note {{ background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin: 12px 0; border-radius: 0 4px 4px 0; font-size: 0.95em; }}
  .note-good {{ background: #d4edda; border-left-color: #28a745; }}
  .note-warn {{ background: #f8d7da; border-left-color: #dc3545; }}
  canvas {{ max-height: 350px; }}
  #timeChart {{ max-height: none !important; }}
  .tag-pos {{ background: #d4edda; color: #155724; padding: 1px 6px; border-radius: 3px; font-size: 0.85em; }}
  .tag-neg {{ background: #f8d7da; color: #721c24; padding: 1px 6px; border-radius: 3px; font-size: 0.85em; }}
</style></head><body><div class="container">

<h1>Mod Tools Usage Audit</h1>
<p>Data period: <strong>{days} days</strong>. Generated {datetime.now().strftime('%Y-%m-%d %H:%M')}.</p>

<h2>1. The Big Picture</h2>
<div class="grid">
  <div class="card"><div class="num">{r['unique_mods']}</div><div class="lbl">Active Moderators</div></div>
  <div class="card"><div class="num">{r['total_mod_hours']}</div><div class="lbl">Total Mod Hours</div></div>
  <div class="card"><div class="num">{r['avg_mins_per_mod_per_day']}</div><div class="lbl">Avg Mins/Mod/Day</div></div>
  <div class="card"><div class="num">{approval_rate}%</div><div class="lbl">Approval Rate</div></div>
  <div class="card"><div class="num">{round(sum(v for k,v in r['devices'].items() if k != 'Desktop') / max(sum(dev_values),1) * 100)}%</div><div class="lbl">Mobile/Tablet</div></div>
  <div class="card"><div class="num">{rejection_rate}%</div><div class="lbl">Rejection Rate</div></div>
</div>

<h2>2. Who Are the Mods?</h2>
<p>Engagement follows a steep power law. A small core does most of the work.</p>
<div class="row">
  <div class="chart-box"><canvas id="commitChart"></canvas></div>
  <div class="chart-box"><canvas id="freqChart"></canvas></div>
</div>
<div class="note">
  <strong>{commit['power_gt2h']['count']} power mods</strong> ({round(commit['power_gt2h']['count']/max(r['unique_mods'],1)*100)}%)
  contribute {round(commit['power_gt2h']['hours']/max(r['total_mod_hours'],1)*100)}% of total time.
  {commit['minimal_lt10m']['count']} mods ({round(commit['minimal_lt10m']['count']/max(r['unique_mods'],1)*100)}%)
  spend less than 10 minutes per week.
</div>
<div class="row">
  <div class="chart-box"><canvas id="deviceChart"></canvas></div>
  <div class="chart-box"><canvas id="hourChart"></canvas></div>
</div>

<h2>3. Where Do Mods Spend Time?</h2>
<p>Sorted by total hours. Time estimated from gaps between page views (capped at 10 min).</p>
<div class="chart-box" style="height:{max(len(page_names) * 32, 400)}px"><canvas id="timeChart"></canvas></div>


<h2>4. Message Outcomes</h2>
<p>Of {total_messages} messages submitted over {action_days} days:</p>
<div class="grid">
  <div class="card"><div class="num">{approval_rate}%</div><div class="lbl">Approved</div></div>
  <div class="card"><div class="num">{rejection_rate}%</div><div class="lbl">Rejected</div></div>
  <div class="card"><div class="num">{edit_rate}%</div><div class="lbl">Edited by Mods</div></div>
</div>
<div class="note{'  note-good' if approval_rate > 95 else ''}">
  <strong>{approval_rate}% of messages are approved.</strong>
  {'With this approval rate, the case for post-moderation (publish first, review after) is strong — pre-moderation delays the overwhelming majority of messages that would be approved anyway.' if approval_rate > 95 else ''}
  Only {rejection_rate}% are rejected, and most rejections are procedural (duplicates, out of area).
</div>

<h3>Rejection Reasons</h3>
<div class="chart-box"><canvas id="rejChart"></canvas></div>

<h3>Member Actions</h3>
<div class="grid">
  <div class="card"><div class="num">{st.get('Remove from group', 0)}</div><div class="lbl">Removed from Group by Mods<br>(excludes self-removals)</div></div>
  <div class="card"><div class="num">{st.get('Message deleted', 0)}</div><div class="lbl">Messages Deleted by Mods<br>(excludes self-deletes)</div></div>
</div>
<div class="note">
  "Remove" takes a member off a group. "Ban" also prevents rejoining — the logs don't distinguish them.
  Self-removals and self-deletes are excluded from these counts.
</div>

<h2>5. Message Editing by Mods</h2>
<p>{es['mods_who_edit']} mods edited {edit_rate}% of approved messages ({es['mod_unique_messages']} messages).</p>
<div class="chart-box"><canvas id="editChart"></canvas></div>
<div class="note{'  note-warn' if top_ed and top_ed[0] > 100 else ''}">
  <strong>Edit concentration:</strong> The most prolific editor modified
  {round(top_ed[0] / max(es['mod_unique_messages'],1) * 100) if top_ed else 0}% of all mod-edited messages.
  Top 5 editors account for {round(sum(top_ed[:5]) / max(es['mod_unique_messages'],1) * 100) if top_ed else 0}%.
  {'This level of editing suggests some mods routinely tidy posts — either quality standards or micromanagement.' if top_ed and top_ed[0] > 50 else ''}
  Edits most commonly change the body text, item name, or Offer/Wanted categorisation.
  The subject line (which combines type, item name and location) is changed less often.
</div>

<h2>6. Mod Action Profiles</h2>
<p>This section identifies mods whose behaviour differs significantly from the norm.
   Based on {len(r['mod_profiles'])} mods with 5+ actions.</p>

<div class="grid">
  <div class="card"><div class="num">{r['rubber_stamp_pct']}%</div><div class="lbl">Rubber-Stampers</div></div>
  <div class="card"><div class="num">{round(ap.get('Rejected',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%</div><div class="lbl">Heavy Rejectors</div></div>
  <div class="card"><div class="num">{round(ap.get('Remove from group',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%</div><div class="lbl">Heavy Removers</div></div>
  <div class="card"><div class="num">{round(ap.get('Edit',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%</div><div class="lbl">Heavy Editors</div></div>
</div>

<div class="note">
  <strong>Rubber-stampers ({r['rubber_stamp_pct']}%):</strong>
  {r['rubber_stampers']} mods approve over 98% of messages and never reject anything.
  They may not be exercising judgement — or their groups may genuinely have no problem posts.
</div>
<div class="note">
  <strong>Heavy rejectors ({round(ap.get('Rejected',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%):</strong>
  {ap.get('Rejected',{}).get('high_outliers',0)} mods reject at more than 3x the average rate.
  The typical mod rejects {ap.get('Rejected',{}).get('mean_pct',0)}% of the time
  (p90: {ap.get('Rejected',{}).get('p90_pct',0)}%).
  These mods reject {round(ap.get('Rejected',{}).get('mean_pct',0) * 3, 1)}%+ of the time
  — they may be stricter than necessary, or dealing with problem-prone groups.
</div>
<div class="note">
  <strong>Heavy removers ({round(ap.get('Remove from group',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%):</strong>
  {ap.get('Remove from group',{}).get('high_outliers',0)} mods remove/ban members at more than 3x the average rate.
  The typical mod removes {ap.get('Remove from group',{}).get('mean_pct',0)}% of the time
  (p90: {ap.get('Remove from group',{}).get('p90_pct',0)}%).
  {ap.get('Remove from group',{}).get('mods_who_never',0)} mods ({round(ap.get('Remove from group',{}).get('mods_who_never',0) / max(len(r['mod_profiles']),1) * 100)}%) never remove anyone.
</div>
<div class="note">
  <strong>Heavy editors ({round(ap.get('Edit',{}).get('high_outliers',0) / max(len(r['mod_profiles']),1) * 100)}%):</strong>
  {ap.get('Edit',{}).get('high_outliers',0)} mods edit at more than 3x the average rate.
  The typical mod edits {ap.get('Edit',{}).get('mean_pct',0)}% of the time
  (p90: {ap.get('Edit',{}).get('p90_pct',0)}%).
  These mods may be maintaining quality standards or micromanaging posts.
  {ap.get('Edit',{}).get('mods_who_never',0)} mods ({round(ap.get('Edit',{}).get('mods_who_never',0) / max(len(r['mod_profiles']),1) * 100)}%) never edit.
</div>

<h2>7. Conclusions</h2>
<h3>For the Platform</h3>
<ol>
  <li><strong>Post-moderation case is strong.</strong> {approval_rate}% of messages are approved.
      Pre-moderation delays the vast majority for no benefit. Moving to post-moderation would
      free significant mod time and improve member experience.</li>
  <li><strong>Mobile-first design is essential.</strong> {round(sum(v for k,v in r['devices'].items() if k != 'Desktop') / max(sum(dev_values),1) * 100)}%
      of mod sessions are on mobile/tablet. This is the primary platform, not secondary.</li>
  <li><strong>Low-usage features</strong> —
      Notes ({ps.get('Member Notes',{}).get('reach_pct',0)}%),
      Map ({ps.get('Group Map',{}).get('reach_pct',0)}%),
      Discourse ({ps.get('Discourse',{}).get('reach_pct',0)}%)
      — need investigation: are they undiscoverable or unnecessary?</li>
  <li><strong>Editing at {edit_rate}%</strong> of messages suggests the posting interface
      could better guide members to provide correct information upfront.</li>
</ol>
<h3>For Volunteer Management</h3>
<ol>
  <li><strong>Power law dependency.</strong> {commit['power_gt2h']['count']} mods
      ({round(commit['power_gt2h']['count']/max(r['unique_mods'],1)*100)}%) do
      {round(commit['power_gt2h']['hours']/max(r['total_mod_hours'],1)*100)}% of the work.
      Burnout risk is concentrated.</li>
  <li><strong>Feedback matters</strong> — {ps.get('Feedback',{}).get('reach_pct',0)}% of mods check it.
      This motivates volunteers; invest in making outcomes visible.</li>
  <li><strong>{r['rubber_stampers']} mods ({r['rubber_stamp_pct']}%) appear to rubber-stamp</strong>
      (approve >98%, never reject). This reinforces the post-moderation argument —
      if mods aren't exercising judgement, pre-moderation adds delay without value.</li>
  <li><strong>{commit['minimal_lt10m']['count']} mods ({round(commit['minimal_lt10m']['count']/max(r['unique_mods'],1)*100)}%)</strong>
      spend under 10 minutes/week. Are they disengaged, or is the barrier to entry too high?</li>
</ol>

</div>
<script>
const co = (t) => ({{ plugins: {{ title: {{ display: true, text: t }} }} }});

new Chart(document.getElementById('timeChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(page_names)}, datasets: [
    {{ label: 'Hours', data: {json.dumps(page_hours)}, backgroundColor: '#2c5f2d', barThickness: 18 }}
  ] }}, options: {{
    indexAxis: 'y', maintainAspectRatio: false,
    plugins: {{ title: {{ display: true, text: 'Time Spent per Feature (hours)' }} }},
    scales: {{ y: {{ ticks: {{ font: {{ size: 12 }} }} }} }}
  }}
}});
new Chart(document.getElementById('commitChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(commit_labels)}, datasets: [
    {{ label: 'Mods', data: {json.dumps(commit_counts)}, backgroundColor: '#2c5f2d' }},
    {{ label: 'Hours', data: {json.dumps(commit_hours)}, backgroundColor: '#17a2b8' }}
  ] }}, options: co('Mod Commitment: Count vs Hours')
}});
new Chart(document.getElementById('freqChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(sf_labels)}, datasets: [
    {{ label: 'Mods', data: {json.dumps(sf_values)}, backgroundColor: '#6c757d' }}
  ] }}, options: co('How Often Mods Check In')
}});
new Chart(document.getElementById('deviceChart'), {{
  type: 'doughnut', data: {{ labels: {json.dumps(dev_labels)},
    datasets: [{{ data: {json.dumps(dev_values)}, backgroundColor: ['#2c5f2d','#ffc107','#17a2b8'] }}]
  }}, options: co('Devices')
}});
new Chart(document.getElementById('hourChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(hours_labels)}, datasets: [
    {{ label: 'Activity', data: {json.dumps(hours_values)}, backgroundColor: '#6c757d' }}
  ] }}, options: co('Time of Day (UTC)')
}});
new Chart(document.getElementById('rejChart'), {{
  type: 'doughnut', data: {{ labels: {json.dumps(rej_labels)},
    datasets: [{{ data: {json.dumps(rej_values)}, backgroundColor: ['#dc3545','#fd7e14','#ffc107','#6c757d','#17a2b8','#28a745','#adb5bd'] }}]
  }}, options: co('Rejection Reasons')
}});
new Chart(document.getElementById('editChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(edit_labels)}, datasets: [
    {{ label: 'Edits', data: {json.dumps(edit_values)}, backgroundColor: '#17a2b8' }}
  ] }}, options: co('What Mods Edit')
}});
</script></body></html>"""

    path = os.path.join(output_dir, "modtools-audit-report.html")
    with open(path, "w") as f:
        f.write(html)
    return path


# ── Main ───────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Mod Tools Usage Audit")
    parser.add_argument("--days", type=int, default=7)
    parser.add_argument("--action-days", type=int, default=7)
    parser.add_argument("--loki-url", default=LOKI_URL)
    parser.add_argument("--output", default="scripts/modtools-audit-output")
    args = parser.parse_args()

    _set_loki_url(args.loki_url)
    os.makedirs(args.output, exist_ok=True)

    client_start, end_ns = get_time_range(args.days)
    action_start, _ = get_time_range(args.action_days)

    print(f"Mod Tools Usage Audit", file=sys.stderr)
    print(f"  Client: {args.days}d | Actions: {args.action_days}d | Loki: {LOKI_URL}", file=sys.stderr)

    results = analyse_all(client_start, action_start, end_ns, args.days, args.action_days)

    with open(os.path.join(args.output, "full_results.json"), "w") as f:
        json.dump(results, f, indent=2, default=str)

    html_path = generate_html(results, args.output)
    print(f"\nReport: {html_path}", file=sys.stderr)
    print(f"JSON:   {args.output}/full_results.json", file=sys.stderr)


if __name__ == "__main__":
    main()
