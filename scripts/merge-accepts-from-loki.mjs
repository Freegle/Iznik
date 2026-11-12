#!/usr/bin/env node
// Writes the input file for `php artisan merges:complete-accepted` from the production logs.
//
// It finds the Accept answers to a merge offer that the Go API refused with a 400 (the /merge page
// sent the offer id as a string, which the API could not read), keeps the LAST attempt per offer,
// and prints one line per offer: "offer id,keep user id,other user id". "keep" is the account the
// member chose to keep (user1 in the request).
//
// Loki keeps 7 days, so run this soon after the deploy of the fix and before the logs age out.
//
//   LOKI_URL=http://localhost:3100 node scripts/merge-accepts-from-loki.mjs > accepts.txt
//
// The same selection as a LogQL query:
//
//   {app="freegle",source="api",method="POST",status_code="400"}
//     |= `"endpoint":"/apiv2/merge"` |= `"action":"Accept"`
//
// The output holds member ids. Do not commit it; delete it after the run.

const base = (process.env.LOKI_URL || 'http://localhost:3100').replace(/\/$/, '')
const query =
  '{app="freegle",source="api",method="POST",status_code="400"} ' +
  '|= `"endpoint":"/apiv2/merge"` |= `"action":"Accept"`'

const DAY = 24 * 3600 * 1e9
const end = BigInt(Date.now()) * 1000000n
let start = end - BigInt(7 * DAY)
const last = new Map()

while (start < end) {
  const url =
    `${base}/loki/api/v1/query_range?` +
    new URLSearchParams({
      query,
      start: start.toString(),
      end: end.toString(),
      limit: '5000',
      direction: 'forward',
    })
  const res = await fetch(url)
  if (!res.ok) throw new Error(`Loki answered ${res.status}`)
  const streams = (await res.json()).data.result
  let newest = start
  let lines = 0

  for (const stream of streams) {
    for (const [ts, text] of stream.values) {
      lines++
      const t = BigInt(ts)
      if (t > newest) newest = t
      let entry
      try {
        entry = JSON.parse(text)
      } catch {
        continue
      }
      const body = entry.request_body || {}
      const id = Number(body.id)
      const keep = Number(body.user1)
      const other = Number(body.user2)
      if (!id || !keep || !other || body.action !== 'Accept') continue
      const prior = last.get(id)
      if (!prior || t >= prior.t) last.set(id, { t, keep, other })
    }
  }

  if (lines < 5000) break
  start = newest + 1n
}

for (const [id, { keep, other }] of [...last.entries()].sort((a, b) => a[0] - b[0])) {
  console.log(`${id},${keep},${other}`)
}
