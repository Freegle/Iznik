# Krystal hosting ad experiment

Branch `krystal-hosting-ad`. A one-time modal for signed-in web members whose email domain hosts a
WordPress site not already on Krystal, linking to the Awin affiliate deep link. Run as a server-decided
UX experiment (`krystal-hosting-ad`, arms `control` and `ad`) so the read is the click rate per member
shown, with a guardrail on members not coming back.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Migration `domain_hosting` + prod SQL | ✅ | per domain, never per member |
| 2 | Batch scanner: `DomainHostingService`, `hosting:scan-domains`, schedule, tests | ✅ | Http::fake, injectable DNS |
| 3 | Metric source `hosting_ad_click` + config + test | ✅ | converted = detail.clicked within window |
| 4 | Go `GET/POST /user/hostingad`, switch entry, tests | ✅ | once per member via users.settings.hostingad |
| 5 | Frontend composable, HostingAd.vue, HostingAdModal.vue, LayoutCommon, API, store, vitest | ✅ | |
| 6 | Shortlink `krystalhosting` SQL + fixture | ✅ | Awin clickref=hostingad, ued=krystal.io/hosting/wordpress |
| 7 | Docs: ux-experiments.md section + covers | ✅ | |
| 8 | Run Go, Laravel, vitest suites; lint | 🔄 | status API port 12020 |
| 9 | PR | ⬜ | deploy order: SQL, Go, batch, frontend; then create+start on batch host |
