## Summary

<!-- 1–3 bullets: what changed and why. -->

## Test plan

<!-- What you ran or will run to verify. -->

---

### Sentry-sourced?

<!-- If this PR addresses a Sentry issue, answer all three. Otherwise delete this section. -->

- **Issue**: <Sentry link>
- **Breadcrumbs read?** What do the last 5–30s before the crash show? Paste the proximate trigger event (e.g. `console: Reset uploader`, `ui.click`, `navigation`) — not just the exception.
- **Loki cross-referenced?** State the query + time window you ran (`{source="api"}`, `{source="client"}`, etc.), or explain why Loki is **not applicable** to this class of error. "Not needed because …" is fine; "didn't check" is not.
- **Integration gap?** Does the failing code path bypass Sentry's fetch/xhr integrations (e.g. `<img>` / `<link>` / `<script>` tag loads, `new Image()`, WebSocket, no-cors fetch)? If yes, name the alternative visibility you used (CDN/weserv/tusd access logs, client-side `console` breadcrumbs) — or flag the gap as unresolved.
