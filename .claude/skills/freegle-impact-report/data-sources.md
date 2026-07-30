# Freegle impact data: canonical figures, formulas and external factors

## Live canonical figures (no auth) - apiv2 dashboard API
Base: `https://api.ilovefreegle.org/apiv2/dashboard` . Components return daily `[{count,date}]`; SUM over the period.

| Want | Query (append `&start=YYYY-MM-DD&end=YYYY-MM-DD`) | Note |
|---|---|---|
| Tonnes reused | `?systemwide=true&components=Weight` | sum kg / 1000 |
| Successful exchanges | `?systemwide=true&components=Outcomes` | TAKEN+RECEIVED |
| Total members (cumulative) | `?systemwide=true&components=ApprovedMemberCount` | last value = current; **memberships, NOT unique people** |
| New members / posts | `?systemwide=true` (legacy) | `newmembers`,`newmessages` |

Python: `json.load(urllib.request.urlopen(url))["components"]["Weight"]`. Run Bash with `dangerouslyDisableSandbox:true` (headless chrome / many-connection clients are blocked by the sandbox; plain curl is not).

## The derivation chain (everything flows from Weight)
Source of truth in code: `iznik-nuxt3/composables/useReuseBenefit.js`, `iznik-batch/app/Services/StatsGenerationService.php`.
- CO2e = tonnes x **0.51** (WRAP, tCO2e per tonne reused)
- Value of reuse = tonnes x **£711/tonne (2011)** x CPI(targetYear)/CPI(2011). CPI 2024=133.9, 2011=93.4 -> **~£1,019/tonne** in 2025 prices. (This £1,019 is Freegle's CPI uprating of WRAP, not a WRAP-published number - say so.)
- Cross-check: live /stats page = 9,680 t -> £9,863,928 (=£1,019/t) and 4,937 t CO2 (=0.51). Internal consistency confirms the chain.
- Calendar 2025 (derived 2026-06): 10,910 t; 404,220 exchanges; 5,564 t CO2e; £11.1m; 4.84m memberships (~1.9m active/month); 256,535 new members.
- Cumulative 2016-2025 (tracking started Sept 2016): ~89,000 t, ~45,000 t CO2e, ~£90m. Annual peaked 2022 (16,673 t), settled to ~11,000 t. **Do NOT plot the annual trend (it declines post-COVID); use the cumulative figure, which only rises.**

## Volunteer hours (for value-for-money ratio) - apiv2-live prod DB tunnel
User starts the Windows SSH tunnel; container `freegle-apiv2-live` appears. Creds: `docker exec freegle-apiv2-live env | grep MYSQL` (host `db-live`=host-gateway, port 11234, user root). Query via throwaway `mysql:8 --add-host db-live:host-gateway`.
- Mod actions 2025 = `logs` rows where `byuser IS NOT NULL` and (Message Approved/Rejected/Hold/Release/Deleted, Chat Approved, Group Edit, User RoleChange/Merged, StdMsg). = **412,094** in 2025; **252** distinct active moderators. (Exclude Message Edit - those byusers are not group mods = members self-editing.)
- Volunteer hours = actions x 1 minute = ~6,900 h. Value at UK median wage (~£16/h) = ~£110k.
- Value-for-money: £110/£1 cash income (£11.1m/£100k); **~£45/£1 of total resources** = £11.1m / (£133k expenditure + £110k volunteer). KEEP THE ARITHMETIC CONSISTENT - state the denominator.

## External evidence factors (sourced)
- **LA disposal saving/tonne**: WRAP UK Gate Fees 2024-25: standard EfW **£121/t**, bulky (POPs furniture) **£164/t**; landfill tax £126.15/t (Apr 2025). Freegle's own range £120-160/t (Singham). Fly-tipping: 2019 Freegle/Defra Monte Carlo ~£167,910 (range £112k-235k).
- **Jobs**: WRAP/Green Alliance 2015: reuse 8-20 / recycling 5-10 / landfill 0.1 jobs per 1,000 t (reuse up to 200x landfill).
- **ReLondon/Valpak 2022**: London 231,000 -> 515,000 circular jobs, £24.2bn GVA by 2030 (conditional on Mayor's targets).
- **Households**: WRAP - reuse saves UK households ~£1bn/yr; sofa saving ~£940 vs new.
- **Wellbeing (Green Book/HACT)**: WELLBY £13,000/pt; belonging ~£7,975/person/yr (HACT); loneliness ~£8,100 (MeasureUp); volunteering ~£3,400. DESNZ carbon ~£260/tCO2e (2025). Use illustratively until a beneficiary survey exists.
- **Berkeley/Stanford gift-economy**: Ballard & Bhatta (2012), Admin Science Quarterly - on **Freecycle/Craigslist** (attribute as "comparable platform", not Freegle).
- **Governance**: Community Benefit Society, FCA-registered, HMRC-charitable; FY to 31 Mar 2025 income ~£91k / expenditure ~£133k / reserves ~£80k (planned deficit = investment).
- **National TOMs proxies**: Freegle has a free Open Access TOM System account ("Freegle - Freegle Geeks", registered 2026-07-20) = **Light set (12) only, no NT31/NT88**. Live 2026 proxies from the account: NT29 volunteering **£17.48/h**, NT28 in-kind £1/£, NT117 environmental £1/£, NT15 expert hrs £106.34. NT31 carbon **£244.63/tCO2e** is the published NT2022 framework proxy (older PDFs say ~£67 - stale, do not use); NT88 reuse £1/£-equiv (also not in free tier - disclose vintage or have partners claim via their paid accounts). Full extract: `plans/2026-07-20-toms-light-set-12-freegle-account.json`. See `measurement-framework.md` §4b.

## Best case studies in the partnerships archive
Wandsworth Cost-of-Living (flagship: £15k grant -> £78,086 community value = 5.2x; 110+ orgs identified vs target 10; £7.75/new member; Pat Gabriel foodbank quote; Ethelburga champion). Brighton Free Shop (since 2021; 2025: 52,000 visitors, 38t, £140k social value, 2,240 vol hrs, ~£12,400/yr cost). Essex (320t, £228k, 72,000 members; Cathryn Wood quote). Sutton (156t, on ReLondon's website). Council quotes: Singham (Richmond&Wandsworth), Cathryn Wood (Essex), Sutton, Cumbria.

## Real freegler photos (people, not just graphs)
Homepage portraits: `iznik-nuxt3/public/landingpage/Freegler1..25.jpeg` (full-length studio shots on a green backdrop). `report-template.html` references them as `@@FREEGLERN@@` tokens. To build: resize each (~560px, JPEG q80), base64-encode, and replace `@@FREEGLERN@@` with `data:image/jpeg;base64,<...>`. CRUCIAL: these are full-length, so frame with `object-fit:cover; object-position:center top` (NOT center) or heads get cropped. Also embed a couple of real member stories from `https://api.ilovefreegle.org/apiv2/story` (list of ids) then `/apiv2/story/{id}` (fields: headline, story) - e.g. the Totton railway book-exchange (#13519).
