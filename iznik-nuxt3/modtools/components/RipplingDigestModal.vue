<template>
  <div v-if="visible" class="rpl-modal-root">
    <div class="rpl-modal-backdrop" @click="close" />
    <div class="rpl-modal-card">
      <div class="rpl-modal-header">
        <span>{{ headerText }}</span>
        <button class="rpl-modal-close" @click="close">×</button>
      </div>
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div class="rpl-modal-body" v-html="bodyHTML" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { classifyPost, thumbUrlFor, formatTimeAgo, escapeHTML }
  from '~/composables/rippling/scoring.js'
import { crowFliesKm } from '~/composables/rippling/geometry.js'

const visible = ref(false)
const headerText = ref('📄 Digest mock-up')
const bodyHTML = ref('')

function close() {
  visible.value = false
}

// Build the inner HTML for one digest entry — title, group, distance,
// time, score breakdown bars, optional thumbnail.
function buildDigestRow(p, rank, memberLat, memberLng) {
  const cls = classifyPost(p)
  const homeChip = p.home_group
    ? `<span style="color:#27ae60;font-weight:600">home</span>`
    : `<span style="color:#1f77b4">rippled in</span>`
  const subject = escapeHTML(p.subject || '(no title)')
  const msgtype = escapeHTML(p.msgtype)
  const groupName = escapeHTML(
    p.groupname || (p.groupid ? `group ${p.groupid}` : 'no group')
  )
  const kmStraight =
    memberLat !== null && memberLat !== undefined
      ? crowFliesKm(memberLat, memberLng, p.lat, p.lng)
      : null
  const distStr =
    kmStraight !== null
      ? `${kmStraight.toFixed(1)} km`
      : `${p.drive_min.toFixed(0)} min in reach`
  const when = formatTimeAgo(p.arrival)
  const thumb = thumbUrlFor(p)
  const thumbHTML = thumb
    ? `<img src="${thumb}" loading="lazy" referrerpolicy="no-referrer" style="width:48px;height:48px;border-radius:4px;object-fit:cover;flex-shrink:0" onerror="this.style.display='none'"/>`
    : `<div style="width:48px;height:48px;border-radius:4px;background:#eee;flex-shrink:0"></div>`
  const fmt = (n) => (n || 0).toFixed(2)
  const bar = (label, val, color) => {
    const pct = Math.max(0, Math.min(1, val || 0)) * 100
    return (
      `<div style="font-size:9px;color:#666;line-height:1.2;display:flex;align-items:center;gap:3px">` +
      `<span style="width:38px;flex-shrink:0">${label}</span>` +
      `<span style="flex:1;background:#eee;height:5px;border-radius:2px;position:relative;overflow:hidden">` +
      `<span style="position:absolute;left:0;top:0;bottom:0;width:${pct.toFixed(0)}%;background:${color}"></span>` +
      `</span></div>`
    )
  }
  const scoreCol =
    `<div style="width:100px;flex-shrink:0;padding:0 4px;display:flex;flex-direction:column;justify-content:center;gap:2px">` +
    `<div style="font-size:11px;font-weight:700;color:#333;text-align:center;line-height:1">` +
    `${fmt(p.score)}<div style="font-size:8px;font-weight:400;color:#888;margin-top:1px">score</div></div>` +
    bar('close',  p.score_close,  '#3498db') +
    bar('budget', p.score_budget, '#e67e22') +
    bar('home',   p.score_anchor, '#27ae60') +
    `</div>`
  return (
    `<div class="rpl-digest-row">` +
    `<div class="rpl-digest-rank" style="background:${cls.color}">${rank}</div>` +
    `<div class="rpl-digest-body">` +
    `<div class="rpl-digest-title">${msgtype}: ${subject}</div>` +
    `<div class="rpl-digest-meta">` +
    `${homeChip} · ${groupName} · ${distStr} · ${when}` +
    `</div></div>` +
    scoreCol +
    thumbHTML +
    `</div>`
  )
}

// Public API ─────────────────────────────────────────────────────────────

// Show the full mock-up: top picks → promised → completed, each
// section soft-capped at 100 entries with a "+N more" footer.
function openDigest(ranked, memberLat, memberLng) {
  if (!ranked || !ranked.length) {
    const msg =
      memberLat === null || memberLat === undefined
        ? 'Drop a location first.'
        : 'No posts in the digest yet — either the data is still loading, or the server returned no results for this location.'
    bodyHTML.value = `<div style="padding:16px;color:#888">${msg}</div>`
    headerText.value = '📄 Digest mock-up'
    visible.value = true
    return
  }

  const intro =
    `<div style="padding:10px 12px;font-size:11px;color:#555;background:#fff7e0;border-bottom:1px solid #f1d97d;line-height:1.4">` +
    `<strong>This isn't how the digest will look</strong> — it's a list of what the digest would <em>contain</em> under the current sliders, in order.  The real email will be laid out by the unified-digest team.` +
    `</div>`

  const sections = { active: [], promised: [], completed: [] }
  ranked.forEach((p) => {
    const cls = classifyPost(p)
    sections[cls.section].push(buildDigestRow(p, p._rank, memberLat, memberLng))
  })

  const SOFT_CAP = 100
  // The real unified digest only emails the first DigestStyle::DIGEST_POST_CAP (65) live
  // posts ("Top picks"); the rest are website-only. Mirror that cap here so the preview
  // reflects what actually goes out, rather than the looser 100-row display soft cap.
  const DIGEST_POST_CAP = 65
  const truncate = (rows) => {
    if (rows.length <= SOFT_CAP) return { html: rows.join(''), extra: 0 }
    return {
      html: rows.slice(0, SOFT_CAP).join(''),
      extra: rows.length - SOFT_CAP,
    }
  }
  const moreFooter = (n) =>
    `<div style="padding:10px 12px;font-size:11px;color:#888;text-align:center;background:#fafafa;border-top:1px solid #eee">` +
    `<em>… and ${n} more on the website.  Amazed you've scrolled this far.</em></div>`
  const sectionHeader = (title, sub) =>
    `<div style="padding:8px 12px;background:#f6f9f1;border-top:1px solid #ececec;border-bottom:1px solid #ececec;font-weight:600;color:#555;font-size:11px">${title}` +
    (sub ? `<div style="font-weight:400;color:#888;font-size:10.5px;margin-top:2px">${sub}</div>` : '') +
    `</div>`

  let html = intro
  if (sections.active.length) {
    html += sectionHeader(`Top picks (${sections.active.length})`)
    // Apply the real digest's 65 live-post cap (not the 100-row display soft cap).
    const extra = Math.max(0, sections.active.length - DIGEST_POST_CAP)
    html += sections.active.slice(0, DIGEST_POST_CAP).join('')
    if (extra) html += moreFooter(extra)
  }
  if (sections.promised.length) {
    html += sectionHeader(
      `Promised (${sections.promised.length})`,
      'Someone has expressed interest but the post is still in flight.'
    )
    const r = truncate(sections.promised)
    html += r.html
    if (r.extra) html += moreFooter(r.extra)
  }
  if (sections.completed.length) {
    html += sectionHeader(
      `Since last digest, these came and went (${sections.completed.length})`,
      '<em>Switch to immediate notifications to see these in time.</em>'
    )
    const r = truncate(sections.completed)
    html += r.html
    if (r.extra) html += moreFooter(r.extra)
  }
  bodyHTML.value = html
  headerText.value = '📄 Digest mock-up'
  visible.value = true
}

// Show all posts at a single map coordinate (TrashNothing centroid case).
function openCluster(posts, memberLat, memberLng) {
  let html =
    `<div style="padding:10px 12px;font-size:11px;color:#555;background:#fff7e0;border-bottom:1px solid #f1d97d">` +
    `<strong>${posts.length} posts at this exact location.</strong> ` +
    `Common with TrashNothing cross-posts that use a group centroid.` +
    `</div>`
  posts.forEach((p) => (html += buildDigestRow(p, p._rank, memberLat, memberLng)))
  bodyHTML.value = html
  headerText.value = `📍 ${posts.length} posts here`
  visible.value = true
}

// Show the deep-dive panel for a single post.
function openPost(p, rank, memberLat, memberLng) {
  const cls = classifyPost(p)
  const groupName = p.groupname || (p.groupid ? `group ${p.groupid}` : 'no group')
  const fmt = (n) => (n || 0).toFixed(2)
  const subject = escapeHTML(p.subject || '(no title)')
  const msgtype = escapeHTML(p.msgtype)
  const thumb = thumbUrlFor(p)
  const thumbHTML = thumb
    ? `<img src="${thumb}" referrerpolicy="no-referrer" style="width:120px;height:120px;border-radius:6px;object-fit:cover;flex-shrink:0" onerror="this.style.display='none'"/>`
    : ''
  const kmStraight =
    memberLat !== null && memberLat !== undefined
      ? crowFliesKm(memberLat, memberLng, p.lat, p.lng)
      : null
  const distStr =
    kmStraight !== null
      ? `${kmStraight.toFixed(1)} km as the crow flies · ${p.drive_min.toFixed(0)} min in reach`
      : `${p.drive_min.toFixed(0)} min in reach`
  bodyHTML.value = `
    <div style="padding:16px;font-size:13px;line-height:1.5">
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px">
        <div class="rpl-digest-rank" style="background:${cls.color};width:32px;height:32px;font-size:13px;flex-shrink:0">${rank + 1}</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:14px;color:#333;word-break:break-word">${msgtype}: ${subject}</div>
          <div style="font-size:11px;color:#888;margin-top:2px">${formatTimeAgo(p.arrival)}</div>
        </div>
        ${thumbHTML}
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <tr><td style="color:#888;padding:4px 6px;width:130px">Status</td><td style="padding:4px 6px"><span style="color:${cls.color};font-weight:600">${cls.label}</span></td></tr>
        <tr><td style="color:#888;padding:4px 6px">Group</td><td style="padding:4px 6px">${escapeHTML(groupName)}</td></tr>
        <tr><td style="color:#888;padding:4px 6px">Distance</td><td style="padding:4px 6px">${distStr}</td></tr>
        <tr><td style="color:#888;padding:4px 6px">Eyeballs so far</td><td style="padding:4px 6px">${p.views} view${p.views===1?'':'s'} · ${p.replies} ${p.replies===1?'reply':'replies'}</td></tr>
        <tr><td style="color:#888;padding:4px 6px">Score</td><td style="padding:4px 6px"><strong>${fmt(p.score)}</strong> = close ${fmt(p.score_close)} + budget ${fmt(p.score_budget)} + anchor ${fmt(p.score_anchor)}</td></tr>
      </table>
    </div>`
  headerText.value = `Post #${rank + 1}`
  visible.value = true
}

defineExpose({ openDigest, openCluster, openPost, close })
</script>

<style>
.rpl-modal-root {
  position: absolute;
  inset: 0;
  z-index: 2000;
}
.rpl-modal-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
}
.rpl-modal-card {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: min(680px, 92%);
  max-height: 80%;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
  display: flex;
  flex-direction: column;
}
.rpl-modal-header {
  background: #61ae24;
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px 8px 0 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.rpl-modal-close {
  background: none;
  border: none;
  color: #fff;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  padding: 0 4px;
}
.rpl-modal-body {
  padding: 0;
  overflow-y: auto;
}
.rpl-digest-row {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 8px 12px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 12px;
}
.rpl-digest-row:hover {
  background: #fafafa;
}
.rpl-digest-rank {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.rpl-digest-body {
  flex: 1;
  min-width: 0;
}
.rpl-digest-title {
  font-weight: 600;
  color: #333;
  word-break: break-word;
}
.rpl-digest-meta {
  font-size: 10.5px;
  color: #777;
  margin-top: 2px;
}
</style>
