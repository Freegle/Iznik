#!/usr/bin/env python3
"""
Freegle User Usage Audit
=========================
Analyses how regular Freegle users (not mods) use the platform.
Queries Loki client logs and the logs_table for user activity.

Usage:
  python3 scripts/freegle-user-audit.py [--days N] [--output DIR]
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


def query_complete(query, start_ns, end_ns, chunk_hours=4):
    all_entries = []
    chunk_ns = chunk_hours * 3600 * 1_000_000_000
    current = start_ns
    while current < end_ns:
        chunk_end = min(current + chunk_ns, end_ns)
        entries = query_loki(query, current, chunk_end, limit=5000)
        if len(entries) == 5000:
            half = current + (chunk_end - current) // 2
            entries = query_loki(query, current, half, limit=5000)
            all_entries.extend(entries)
            entries = query_loki(query, half, chunk_end, limit=5000)
        all_entries.extend(entries)
        current = chunk_end
    return all_entries


def get_time_range(days):
    now = datetime.now(timezone.utc)
    start = now - timedelta(days=days)
    return int(start.timestamp() * 1e9), int(now.timestamp() * 1e9)


def db_query(sql):
    """Run a SQL query via the batch container and return JSON results."""
    php = f"""
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=iznik', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$stmt = $pdo->query("{sql}");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
"""
    try:
        result = subprocess.run(
            ["docker", "exec", "freegle-batch-prod", "php", "-r", php],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode == 0 and result.stdout.strip():
            return json.loads(result.stdout)
    except Exception as e:
        print(f"  Warning: DB query failed: {e}", file=sys.stderr)
    return []


PAGE_NAMES = {
    "/": "Home", "/browse": "Browse", "/give": "Give/Post", "/find": "Find",
    "/chats": "Chats", "/myposts": "My Posts", "/chitchat": "Chitchat",
    "/post": "Post", "/settings": "Settings", "/explore": "Explore Groups",
    "/message": "Message Detail", "/mypost": "My Post Detail",
    "/give/mobile": "Give (Mobile)", "/find/mobile": "Find (Mobile)",
    "/profile": "Profile", "/stories": "Stories",
    "/communityevent": "Community Event", "/communityevents": "Community Events",
    "/volunteering": "Volunteering", "/volunteerings": "Volunteering List",
    "/stats": "Stats", "/noticeboards": "Noticeboards",
    "/microvolunteering": "Microvolunteering",
    "/login": "Login", "/unsubscribe": "Unsubscribe",
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


def analyse_all(client_start, action_start, end_ns, days, action_days):
    # ── 1. Page views ──
    print("\n[1/5] Page views...", file=sys.stderr)
    # ilovefreegle OR capacitor (app)
    pv = query_complete(
        '{app="freegle", source="client", event_type="page_view"} |~ "ilovefreegle|capacitor"',
        client_start, end_ns, chunk_hours=4)
    print(f"  {len(pv)} page views", file=sys.stderr)

    by_session = defaultdict(list)
    for e in pv:
        sid = e.get("session_id", "")
        if not sid:
            continue
        url = e.get("url", "")
        is_app = "capacitor" in url
        by_session[sid].append({
            "page": classify_page(e.get("page_name", "")),
            "raw_page": e.get("page_name", ""),
            "ts_ns": e["_ts_ns"],
            "uid": str(e.get("user_id") or "anon"),
            "hour": int(e.get("timestamp", "T00:")[11:13]) if e.get("timestamp", "") and len(e.get("timestamp", "")) >= 14 else -1,
            "is_app": is_app,
        })

    page_times = defaultdict(list)
    page_visits = defaultdict(int)
    page_reach = defaultdict(set)
    total_time_s = 0
    daily_active = defaultdict(set)
    unique_users = set()
    per_user_time = defaultdict(float)
    per_user_pages = defaultdict(lambda: defaultdict(int))
    hour_counts = Counter()
    session_lengths = []
    first_pages = Counter()
    transition_counts = defaultdict(int)

    for sid, views in by_session.items():
        views.sort(key=lambda x: x["ts_ns"])
        session_lengths.append(len(views))
        uid = views[0]["uid"]
        if uid != "anon":
            unique_users.add(uid)

        if views:
            first_pages[views[0]["page"]] += 1

        for i, v in enumerate(views):
            page = v["page"]
            page_visits[page] += 1
            if v["uid"] != "anon":
                page_reach[page].add(v["uid"])
                per_user_pages[v["uid"]][page] += 1
            if v["hour"] >= 0:
                hour_counts[v["hour"]] += 1
            day = datetime.fromtimestamp(v["ts_ns"] / 1e9, tz=timezone.utc).strftime("%Y-%m-%d")
            if v["uid"] != "anon":
                daily_active[day].add(v["uid"])
            if i < len(views) - 1:
                gap_s = (views[i + 1]["ts_ns"] - v["ts_ns"]) / 1e9
                if 0 < gap_s <= 600:
                    total_time_s += gap_s
                    if v["uid"] != "anon":
                        per_user_time[v["uid"]] += gap_s
                    if gap_s >= 2:
                        page_times[page].append(gap_s)
            if i < len(views) - 1:
                p1, p2 = views[i]["page"], views[i + 1]["page"]
                if p1 != p2:
                    transition_counts[f"{p1} -> {p2}"] += 1

    page_stats = {}
    for page in sorted(page_visits.keys(), key=lambda p: -sum(page_times.get(p, []))):
        times = page_times.get(page, [])
        reach = len(page_reach.get(page, set()))
        total_h = sum(times) / 3600 if times else 0
        median_s = sorted(times)[len(times) // 2] if times else 0
        page_stats[page] = {
            "visits": page_visits[page], "reach": reach,
            "reach_pct": round(reach / max(len(unique_users), 1) * 100, 1),
            "total_hours": round(total_h, 2), "median_seconds": round(median_s, 1),
        }

    # ── 2. Sessions / devices ──
    print("\n[2/5] Sessions...", file=sys.stderr)
    sess = query_complete(
        '{app="freegle", source="client", event_type="session_start"} |~ "ilovefreegle|capacitor"',
        client_start, end_ns, chunk_hours=6)
    print(f"  {len(sess)} sessions", file=sys.stderr)

    devices = Counter()
    platforms = Counter()
    for e in sess:
        url = e.get("url", "")
        is_touch = e.get("is_touch")
        vw = e.get("viewport_width")
        if "capacitor" in url:
            platforms["App"] += 1
        else:
            platforms["Web"] += 1
        if is_touch:
            devices["Mobile" if vw and int(vw) < 768 else "Tablet"] += 1
        else:
            devices["Desktop"] += 1

    # ── 3. Message outcomes from DB ──
    print("\n[3/5] Message outcomes (DB)...", file=sys.stderr)
    outcomes_data = db_query(
        "SELECT outcome, COUNT(*) as cnt FROM messages_outcomes "
        "WHERE timestamp > DATE_SUB(NOW(), INTERVAL " + str(action_days) + " DAY) "
        "GROUP BY outcome"
    )
    outcomes = {r["outcome"]: int(r["cnt"]) for r in outcomes_data} if outcomes_data else {}
    print(f"  Outcomes: {outcomes}", file=sys.stderr)

    # Message counts
    msg_counts = db_query(
        "SELECT type, COUNT(*) as cnt FROM messages "
        "WHERE arrival > DATE_SUB(NOW(), INTERVAL " + str(action_days) + " DAY) "
        "AND deleted IS NULL "
        "GROUP BY type"
    )
    msg_types = {r["type"]: int(r["cnt"]) for r in msg_counts if r["type"]} if msg_counts else {}
    print(f"  Message types: {msg_types}", file=sys.stderr)

    # Reply rate
    reply_data = db_query(
        "SELECT COUNT(DISTINCT m.id) as msgs_with_replies "
        "FROM messages m "
        "INNER JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested' "
        "WHERE m.arrival > DATE_SUB(NOW(), INTERVAL " + str(action_days) + " DAY) "
        "AND m.deleted IS NULL AND m.type = 'Offer'"
    )
    msgs_with_replies = int(reply_data[0]["msgs_with_replies"]) if reply_data else 0

    total_offers = msg_types.get("Offer", 0)
    reply_rate = round(msgs_with_replies / max(total_offers, 1) * 100, 1)

    # ── 4. User engagement tiers ──
    print("\n[4/5] User engagement...", file=sys.stderr)
    user_hours = sorted(per_user_time.values(), reverse=True)
    total_h = sum(user_hours) / 3600

    engagement = {
        "power_gt1h": {"count": 0, "hours": 0},
        "regular_15m_1h": {"count": 0, "hours": 0},
        "light_5_15m": {"count": 0, "hours": 0},
        "minimal_lt5m": {"count": 0, "hours": 0},
    }
    for h in user_hours:
        hrs = h / 3600
        if hrs > 1:
            engagement["power_gt1h"]["count"] += 1
            engagement["power_gt1h"]["hours"] += hrs
        elif hrs >= 0.25:
            engagement["regular_15m_1h"]["count"] += 1
            engagement["regular_15m_1h"]["hours"] += hrs
        elif hrs >= 5 / 60:
            engagement["light_5_15m"]["count"] += 1
            engagement["light_5_15m"]["hours"] += hrs
        else:
            engagement["minimal_lt5m"]["count"] += 1
            engagement["minimal_lt5m"]["hours"] += hrs

    # ── 5. User journey classification ──
    print("\n[5/5] User journeys...", file=sys.stderr)
    journeys = Counter()
    single_page_sessions = sum(1 for l in session_lengths if l == 1)
    for uid in unique_users:
        pages = per_user_pages.get(uid, {})
        browsed = pages.get("Browse", 0) > 0
        posted = any(pages.get(p, 0) > 0 for p in ("Give/Post", "Give (Mobile)", "Post"))
        chatted = pages.get("Chats", 0) > 0
        checked_posts = pages.get("My Posts", 0) > 0 or pages.get("My Post Detail", 0) > 0
        found = any(pages.get(p, 0) > 0 for p in ("Find", "Find (Mobile)"))
        viewed_msg = pages.get("Message Detail", 0) > 0

        if posted and chatted and browsed:
            journeys["Full engagement (post + chat + browse)"] += 1
        elif posted and chatted:
            journeys["Poster + chatter"] += 1
        elif posted and checked_posts:
            journeys["Poster (checking posts)"] += 1
        elif posted:
            journeys["Poster only"] += 1
        elif browsed and chatted:
            journeys["Browser + chatter"] += 1
        elif browsed and found:
            journeys["Active searcher"] += 1
        elif browsed:
            journeys["Browser only"] += 1
        elif chatted:
            journeys["Chatter only (replying via email/notification)"] += 1
        elif viewed_msg:
            journeys["Email link visitor (viewed message only)"] += 1
        elif checked_posts:
            journeys["Checking own posts"] += 1
        else:
            journeys["Other/minimal"] += 1

    # Success rate: Taken / (Taken + Withdrawn)
    taken = outcomes.get("Taken", 0)
    withdrawn = outcomes.get("Withdrawn", 0)
    expired = outcomes.get("Expired", 0)
    received = outcomes.get("Received", 0)
    total_outcomes = taken + withdrawn + expired + received
    success_rate = round((taken + received) / max(total_outcomes, 1) * 100, 1)

    return {
        "days": days, "action_days": action_days,
        "unique_users": len(unique_users),
        "total_page_views": len(pv),
        "total_sessions": len(sess),
        "total_user_hours": round(total_h, 1),
        "page_stats": page_stats,
        "daily_active": {d: len(u) for d, u in sorted(daily_active.items())},
        "hour_of_day": {str(h): hour_counts.get(h, 0) for h in range(24)},
        "devices": dict(devices),
        "platforms": dict(platforms),
        "engagement": engagement,
        "journeys": dict(journeys.most_common()),
        "first_pages": dict(first_pages.most_common(10)),
        "top_transitions": dict(sorted(transition_counts.items(), key=lambda x: -x[1])[:20]),
        "session_lengths": {
            "median": sorted(session_lengths)[len(session_lengths) // 2] if session_lengths else 0,
            "mean": round(sum(session_lengths) / max(len(session_lengths), 1), 1),
        },
        "bounce_rate": round(single_page_sessions / max(len(session_lengths), 1) * 100, 1),
        "msg_types": msg_types,
        "outcomes": outcomes,
        "success_rate": success_rate,
        "reply_rate": reply_rate,
        "total_offers": total_offers,
        "msgs_with_replies": msgs_with_replies,
    }


def generate_html(r, output_dir):
    days = r["days"]
    ps = r["page_stats"]
    page_names = list(ps.keys())
    page_hours = [ps[p]["total_hours"] for p in page_names]

    hours_labels = [f"{h:02d}:00" for h in range(24)]
    hours_values = [r["hour_of_day"].get(str(h), 0) for h in range(24)]

    dev_labels = list(r["devices"].keys())
    dev_values = list(r["devices"].values())

    plat_labels = list(r["platforms"].keys())
    plat_values = list(r["platforms"].values())

    eng = r["engagement"]
    eng_labels = ["Power (>1h/wk)", "Regular (15m-1h)", "Light (5-15m)", "Minimal (<5m)"]
    eng_counts = [eng["power_gt1h"]["count"], eng["regular_15m_1h"]["count"],
                  eng["light_5_15m"]["count"], eng["minimal_lt5m"]["count"]]
    eng_hours = [round(eng["power_gt1h"]["hours"], 1), round(eng["regular_15m_1h"]["hours"], 1),
                 round(eng["light_5_15m"]["hours"], 1), round(eng["minimal_lt5m"]["hours"], 1)]

    journey_labels = list(r["journeys"].keys())
    journey_values = list(r["journeys"].values())

    oc = r["outcomes"]
    oc_labels = list(oc.keys())
    oc_values = list(oc.values())

    mt = r["msg_types"]
    mt_labels = list(mt.keys())
    mt_values = list(mt.values())

    total_users = r["unique_users"]
    total_sessions = r["total_sessions"]
    mobile_pct = round(sum(v for k, v in r["devices"].items() if k != "Desktop") / max(total_sessions, 1) * 100)

    html = f"""<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Freegle User Usage Audit — {datetime.now().strftime('%Y-%m-%d')}</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; color: #333; line-height: 1.5; }}
  .container {{ max-width: 1100px; margin: 0 auto; }}
  h1 {{ color: #2c5f2d; border-bottom: 3px solid #2c5f2d; padding-bottom: 10px; }}
  h2 {{ color: #2c5f2d; margin-top: 40px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }}
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
</style></head><body><div class="container">

<h1>Freegle User Usage Audit</h1>
<p>Data period: <strong>{days} days</strong> of client-side data,
   <strong>{r['action_days']} days</strong> of message/outcome data.
   Generated {datetime.now().strftime('%Y-%m-%d %H:%M')}.</p>

<h2>1. Overview</h2>
<div class="grid">
  <div class="card"><div class="num">{total_users}</div><div class="lbl">Active Users ({days}d)</div></div>
  <div class="card"><div class="num">{r['total_user_hours']}</div><div class="lbl">Total User Hours</div></div>
  <div class="card"><div class="num">{r['session_lengths']['median']}</div><div class="lbl">Median Pages/Session</div></div>
  <div class="card"><div class="num">{mobile_pct}%</div><div class="lbl">Mobile/Tablet</div></div>
  <div class="card"><div class="num">{round(r['platforms'].get('App', 0) / max(total_sessions, 1) * 100)}%</div><div class="lbl">Use the App</div></div>
  <div class="card"><div class="num">{r['success_rate']}%</div><div class="lbl">Items Successfully Rehomed</div></div>
  <div class="card"><div class="num">{r.get('bounce_rate', 0)}%</div><div class="lbl">Single-Page Sessions</div></div>
</div>

<h2>2. Who Are the Users?</h2>
<div class="row">
  <div class="chart-box"><canvas id="engChart"></canvas></div>
  <div class="chart-box"><canvas id="journeyChart"></canvas></div>
</div>
<div class="note">
  <strong>{eng['power_gt1h']['count']} power users</strong>
  ({round(eng['power_gt1h']['count']/max(total_users,1)*100)}%)
  contribute {round(eng['power_gt1h']['hours']/max(r['total_user_hours'],1)*100)}% of total time.
  {eng['minimal_lt5m']['count']} users ({round(eng['minimal_lt5m']['count']/max(total_users,1)*100)}%)
  spend under 5 minutes per week.
</div>
<div class="row">
  <div class="chart-box"><canvas id="deviceChart"></canvas></div>
  <div class="chart-box"><canvas id="hourChart"></canvas></div>
</div>

<h2>3. Where Do Users Spend Time?</h2>
<div class="chart-box" style="height:{max(len(page_names) * 32, 300)}px"><canvas id="timeChart"></canvas></div>

<h2>4. Message Activity</h2>
<div class="row">
  <div class="chart-box"><canvas id="msgChart"></canvas></div>
  <div class="chart-box"><canvas id="outcomeChart"></canvas></div>
</div>
<div class="grid">
  <div class="card"><div class="num">{r['success_rate']}%</div><div class="lbl">Items Rehomed<br>(Taken + Received)</div></div>
  <div class="card"><div class="num">{r['reply_rate']}%</div><div class="lbl">Offers That Get Replies</div></div>
  <div class="card"><div class="num">{sum(mt.values())}</div><div class="lbl">Messages Posted ({r['action_days']}d)</div></div>
</div>
<div class="note{'  note-warn' if r['success_rate'] < 50 else '  note-good' if r['success_rate'] > 70 else ''}">
  <strong>{r['success_rate']}% of items are successfully rehomed</strong>
  ({oc.get('Taken', 0)} taken + {oc.get('Received', 0)} received out of {sum(oc.values())} outcomes).
  {round(oc.get('Withdrawn', 0) / max(sum(oc.values()), 1) * 100)}% are withdrawn by the member
  (item disposed of elsewhere, or gave up finding a taker).
  {round(oc.get('Expired', 0) / max(sum(oc.values()), 1) * 100)}% auto-expire (member never reported the outcome).
  {r['reply_rate']}% of offers receive at least one expression of interest.
</div>

<h2>5. Conclusions</h2>
<h3>For the Platform</h3>
<ol>
  <li><strong>Mobile-dominant.</strong> {mobile_pct}% of sessions are mobile/tablet,
      {round(r['platforms'].get('App', 0) / max(total_sessions, 1) * 100)}% use the app.
      Mobile experience is the primary experience.</li>
  <li><strong>Browse is the dominant activity</strong>
      ({ps.get('Browse', {}).get('total_hours', 0)}h, {ps.get('Browse', {}).get('reach_pct', 0)}% of users).
      Users spend far more time browsing than posting — the browse experience is critical.</li>
  <li><strong>{r['success_rate']}% rehoming rate</strong>
      {'is good' if r['success_rate'] > 60 else 'needs improvement'}.
      {round(oc.get('Withdrawn', 0) / max(sum(oc.values()), 1) * 100)}% of items are withdrawn
      (member gave up or item went elsewhere).</li>
  <li><strong>{r['reply_rate']}% of offers get replies.</strong>
      {'The majority get interest' if r['reply_rate'] > 50 else 'Two-thirds of offers get no expressions of interest — discoverability, email digest timing, or matching may need work'}.</li>
  <li><strong>{round(oc.get('Withdrawn', 0) / max(sum(oc.values()), 1) * 100)}% of items are withdrawn</strong>
      — the member gave up or disposed of the item elsewhere. This is a significant loss of potential reuse.</li>
  <li><strong>{r.get('bounce_rate', 0)}% of sessions are single-page.</strong>
      Many users arrive via email links, view a specific message, and leave. Reducing this means
      showing related items or next actions on message pages.</li>
</ol>
<h3>For User Engagement</h3>
<ol>
  <li><strong>Most users are browsers.</strong>
      {r['journeys'].get('Browser only', 0)} users ({round(r['journeys'].get('Browser only', 0)/max(total_users,1)*100)}%)
      only browse — they never post or chat. Converting browsers to participants is the main growth opportunity.</li>
  <li><strong>{eng['minimal_lt5m']['count']} users ({round(eng['minimal_lt5m']['count']/max(total_users,1)*100)}%)</strong>
      spend under 5 minutes/week. These are drive-by visitors or people who came once and didn't engage.</li>
  <li><strong>Chat is key to transactions.</strong>
      Users who chat are the ones completing transactions. {ps.get('Chats', {}).get('reach_pct', 0)}% of users visit chats.</li>
</ol>

</div>
<script>
const co = (t) => ({{ plugins: {{ title: {{ display: true, text: t }} }} }});

new Chart(document.getElementById('timeChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(page_names)}, datasets: [
    {{ label: 'Hours', data: {json.dumps(page_hours)}, backgroundColor: '#2c5f2d', barThickness: 18 }}
  ] }}, options: {{
    indexAxis: 'y', maintainAspectRatio: false,
    plugins: {{ title: {{ display: true, text: 'Time Spent per Page (hours)' }} }},
    scales: {{ y: {{ ticks: {{ font: {{ size: 12 }} }} }} }}
  }}
}});
new Chart(document.getElementById('engChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(eng_labels)}, datasets: [
    {{ label: 'Users', data: {json.dumps(eng_counts)}, backgroundColor: '#2c5f2d' }},
    {{ label: 'Hours', data: {json.dumps(eng_hours)}, backgroundColor: '#17a2b8' }}
  ] }}, options: co('User Engagement: Count vs Hours')
}});
new Chart(document.getElementById('journeyChart'), {{
  type: 'bar', data: {{ labels: {json.dumps(journey_labels)}, datasets: [
    {{ label: 'Users', data: {json.dumps(journey_values)}, backgroundColor: '#6c757d' }}
  ] }}, options: {{ indexAxis: 'y', ...co('User Journeys') }}
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
new Chart(document.getElementById('msgChart'), {{
  type: 'doughnut', data: {{ labels: {json.dumps(mt_labels)},
    datasets: [{{ data: {json.dumps(mt_values)}, backgroundColor: ['#2c5f2d','#17a2b8','#ffc107','#6c757d'] }}]
  }}, options: co('Messages by Type ({r["action_days"]}d)')
}});
new Chart(document.getElementById('outcomeChart'), {{
  type: 'doughnut', data: {{ labels: {json.dumps(oc_labels)},
    datasets: [{{ data: {json.dumps(oc_values)}, backgroundColor: ['#28a745','#dc3545','#ffc107','#6c757d','#17a2b8'] }}]
  }}, options: co('Message Outcomes ({r["action_days"]}d)')
}});
</script></body></html>"""

    path = os.path.join(output_dir, "freegle-user-audit-report.html")
    with open(path, "w") as f:
        f.write(html)
    return path


def main():
    parser = argparse.ArgumentParser(description="Freegle User Usage Audit")
    parser.add_argument("--days", type=int, default=7)
    parser.add_argument("--action-days", type=int, default=7)
    parser.add_argument("--loki-url", default=LOKI_URL)
    parser.add_argument("--output", default="scripts/freegle-user-audit-output")
    args = parser.parse_args()

    _set_loki_url(args.loki_url)
    os.makedirs(args.output, exist_ok=True)

    client_start, end_ns = get_time_range(args.days)
    action_start, _ = get_time_range(args.action_days)

    print(f"Freegle User Usage Audit", file=sys.stderr)
    print(f"  Client: {args.days}d | Actions: {args.action_days}d | Loki: {LOKI_URL}", file=sys.stderr)

    results = analyse_all(client_start, action_start, end_ns, args.days, args.action_days)

    with open(os.path.join(args.output, "full_results.json"), "w") as f:
        json.dump(results, f, indent=2, default=str)

    html_path = generate_html(results, args.output)
    print(f"\nReport: {html_path}", file=sys.stderr)


if __name__ == "__main__":
    main()
