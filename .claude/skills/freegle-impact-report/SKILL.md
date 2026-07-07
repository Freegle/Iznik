---
name: freegle-impact-report
description: Use when producing a Freegle social-impact / social-value / SROI report or one-pager for councils, ReLondon or funders, or refreshing the annual impact report, or quantifying Freegle's tonnes/CO2/value/jobs/local-authority-savings, or building any designed report that needs headless-Chrome visual review.
---

# Freegle impact report

Produce a designed, evidence-backed, funder-facing Freegle social-impact report from live data, adversarially reviewed, with visuals checked by headless Chrome. Verified pipeline (Impact Report 2025).

## Pipeline

1. **Pull live canonical figures** from the apiv2 dashboard API (Weight, Outcomes, ApprovedMemberCount, new members/posts) and derive CO2/value/LA-savings. ONE tonnage flows through everything. See `data-sources.md` for endpoints, formulas and current numbers.
2. **Volunteer hours** (for the value-for-money ratio): if the user has the `freegle-apiv2-live` tunnel up, count 2025 mod actions in `logs` and value at 1 min/action. See `data-sources.md`.
3. **Evidence base**: external factors (WRAP gate fees & reuse value, jobs ratios, ReLondon GVA, Green Book/HACT wellbeing, fly-tipping) are compiled in `data-sources.md`. Mine the partnerships archive (the Google-Drive dump) for case studies and council quotes; convert docx/pdf/xlsx/pptx to text first (`pandoc`, `pdftotext`, `openpyxl`, `python-pptx`).
4. **Build the report** by editing `report-template.html` (Freegle brand: green `#338808`/`#1d6607`, Fraunces + Public Sans, 14 A4 pages, inline-SVG charts, stat cards, case-study heroes, callouts). Structure leads with Wayne's five pillars in order: **USP -> money saved to local authorities -> waste & carbon -> local economy & jobs -> community co-benefits**, plus governance and a specific funding ask.
5. **Render & review visuals**: `bash render.sh report.html outdir 120` (headless `google-chrome --disable-gpu --print-to-pdf` then `pdftoppm` to per-page PNGs). Read the PNGs and iterate. Run render Bash with `dangerouslyDisableSandbox:true` so web fonts load.
6. **Adversarially review**: fan out agents on distinct lenses (figures/arithmetic, methodology/double-counting, sceptical funder, USP & pillars, visual design). Apply every critical/major fix, then a verification agent re-checks the arithmetic and that fixes landed. See the `code-review`/Workflow tools.

## Non-negotiable correctness rules (each one was a real review finding)

- **One tonnage everywhere.** CO2, £ value and LA savings all derive from the same Weight figure. Never mix in legacy self-reported tonnages.
- **Never sum the monetary lenses.** £ reuse value, £ carbon value and £ LA savings value the *same* tonnes for *different* beneficiaries. Present separately; disclose the carbon value "may overlap, not added".
- **Members = memberships, not people.** State cumulative (4.84m) AND active (~1.9m/month). 
- **Value-for-money ratio must be arithmetically self-consistent.** State numerator and denominator explicitly; £11.1m / (cash + volunteer time) must actually equal the ratio you print.
- **Lead with the money to local authorities** (Wayne) - on the cover, the at-a-glance, and as the first substantive section.
- **Attribute honestly**: £1,019/t is Freegle's CPI uprating of WRAP's £711 (not a WRAP number); the Berkeley study is Freecycle not Freegle; date the 2019 fly-tipping figure; note case-study £ that use the old £711/t base.
- **Don't claim a rising trend** (annual tonnage fell post-2022 COVID peak); lead the longevity story with the cumulative-since-2016 figure, which only rises.
- **Under-claim co-benefits**: quote wellbeing proxies illustratively only, pending a beneficiary survey.

## Files

- `data-sources.md` - API endpoints, formulas, current numbers, external factors, case studies (read this first).
- `report-template.html` - the full designed 14-page report; edit in place.
- `render.sh` - HTML -> PDF -> per-page PNGs for visual review.
- `measurement-framework.md` - the social-impact measurement framework (theory of change, five pillars, valuation methods, assurance).

## Common mistakes

- Plotting the annual tonnage trend (shows a post-COVID decline) instead of the cumulative figure.
- Running headless Chrome under the default Bash sandbox (network/listening-socket blocked) - use `dangerouslyDisableSandbox:true`.
- Pandoc can't read `.pptx`/legacy `.doc`/`.xls`; use `python-pptx`/`xlrd` fallbacks.
- Leaving empty lower-thirds on A4 pages - fill with stat strips or pull-quotes.
