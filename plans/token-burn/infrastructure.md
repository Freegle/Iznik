# Infrastructure for the token window: one VM per stream, nothing shared

Status: draft
Last reviewed: 2026-10-28

## The problem

During the window the tokens are close to free and the CPU is not. The candidates in this folder
split into about eleven parallel tracks (finance 4, ModTools 4, the combined experiment 3), each
of which wants its own Compose stack to develop against and its own CI runs. Running several
stacks on one host is what we do today and it is the thing to stop doing: the Playwright
container is host-network and any container churn on the box aborts its page loads, timings are
skewed by whatever else is running, and an out-of-memory kill silently swaps a worktree's dev
container for its baked image. The dev box is already shared with other projects' stacks (60 GB
of 94 GB in use by them on 2026-10-28). Chasing those problems is wasted time.

## What one unit costs

| Unit | Measured | Source |
|---|---|---|
| One dev stack | about 29 containers, 15 to 19 GiB warm, about 100 GB disk on first build; percona in RAM | `docs/getting-started/developer-track.md`, `.claude/rules/dev-containers.md` |
| One CI run | one `build-and-test` job on a cpx62 (16 shared vCPU, 32 GB, 20 GB swap), 16 min with the serial checks overlapped (PR #1933), 20 to 22 min before; three small CircleCI-cloud jobs beside it | `ci-status` on branch `ci/overlap-serial-steps`, `scripts/hetzner/cloud-init.yaml` |
| Runner limit | the Hetzner project's shared-core cap fits one cpx62 at a time, though the config asks for two | memory note from 2026-10-10 |
| Runner cost | cpx62 is now €0.21/hr (€130/mo) after Hetzner's June 2026 increase; billed per started hour, which `reap-runner.sh` already exploits | Hetzner price adjustment page |

## Two kinds of VM

**Builder.** One per stream. Runs Claude Code headless (`ralph.sh` on the candidate's task), a
clone of the monorepo on its own branch, one Compose stack, and the targeted tests. It pushes the
PR; CI runs the full suites. It holds state that is expensive to rebuild (the warm stack, the
in-RAM database, the agent's place in the task), so it is **not** a spot instance. Size: 16
vCPU, 64 GB, 200 GB disk. Candidates, post-increase prices:

| Option | Spec | Price | Note |
|---|---|---|---|
| Krystal Cloud (Katapult) C-48 | 16 vCPU, 48 GB, 350 GB | list £0.3185/hr (£232.50/mo); **about £0.16/hr, £3.80/day at Freegle's 50% charity discount** | billed by the second; the production estate already runs here at that discount (about £530/month); the CI orb still knows how to run runners here (`RUNNER_PROVIDER=katapult`); 48 GB is enough for one stack |
| Hetzner CCX43 | 16 dedicated vCPU, 64 GB, 360 GB | €0.44/hr, about €10.60/day | no preemption; the runner scripts are already written for Hetzner; dedicated cores |
| GCP c3d-standard-16 on demand | 16 vCPU, 64 GB | about $0.76 to 0.86/hr | dearer than Hetzner for the same thing |
| GCP c3d-standard-16 spot | 16 vCPU, 64 GB | about $0.21/hr in europe-west1, $0.40 to 0.45 in west3/west4 | cheapest, but can be stopped with 30 seconds' notice; a stopped builder loses its RAM database and its warm stack and costs 20 to 30 minutes to re-warm |

Recommendation: builders on Krystal Cloud C-48. With the discount it is a third of the Hetzner
price and cheaper than GCP spot, without preemption, and it is billed by the second so a builder
reaped the moment its PR opens costs only what it used. Two cautions: the discount should be
confirmed on the next invoice as applying to Cloud compute (Krystal's public charity page names
VPS and shared hosting; ours was agreed separately in 2023 and is what the production bill shows),
and a payment-card failure on this account blocked all CI in July 2026, so the card and the
spending cap are checked before the window, not during it. Hetzner CCX43 is the fallback if the
account cannot carry eight more VMs.

**CI runner.** Normal CI is unchanged and stays on the Hetzner runners (Edward, 2026-10-28). What follows is capacity the window could add, not a change to CI.

Stateless and already ephemeral (`scripts/hetzner/provision-runner.sh`,
`cloud-init.yaml`, `reap-runner.sh`). This is exactly what spot is for. Two routes, do both:

Why CI is on Hetzner at all: the Katapult runner path stalled on 2026-07-11 when a payment-card
problem on the account blocked VM creation, and Hetzner was wired in as a drop-in the same day.
It stayed because nobody switched back; the Katapult path in `.circleci/config.yml` is intact and
dormant behind `RUNNER_PROVIDER`.

1. Run them on Krystal Cloud at the discount, which the orb already supports
   (`RUNNER_PROVIDER=katapult`): a C-48 runner is about £0.16/hr against the Hetzner cpx62's
   €0.21/hr, with more RAM, and no Hetzner core cap. Confirm the account's VM quota allows three or
   four at once. Keep Hetzner as the switch-back (`RUNNER_PROVIDER=hetzner`) and ask Hetzner for
   the core limit anyway, since that request is free and slow.
2. Port the runner cloud-init to GCP spot as overflow: `gcloud compute instances create
   --provisioning-model=SPOT --instance-termination-action=DELETE`, same user-data, Docker pinned
   to 27.5.1, the Hub mirror and build cache at the Katapult registry VM reachable from GCP.
   A preempted runner just fails the job, and CircleCI reruns it on the next runner.

## How many

- Builders: up to eleven tracks, but Edward's review bandwidth caps useful concurrency at about
  six to eight. Plan for eight, spin them up as tracks start, and reap each when its PR is open.
- Runners: eight builders pushing three to six times a day is 25 to 50 CI runs a day, 7 to 14
  runner-hours. Three runners during working hours keep queue wait under ten minutes; one
  overnight. Spot overflow covers bursts.
- Rough week on Krystal Cloud at the discount: 8 builders x £3.80 x 7 = about £215; runners
  3 x 10h x 7 x £0.16 = about £35. Order of £250 to £350 for the window, under £1,000 even if
  everything ran on Hetzner instead. The local host carries Edward's own session and nothing else.

## Decisions settled

- **One stack per VM.** No second Compose project on any VM, ever, for the reasons above.
- **Builders are not spot.** State is expensive; the saving is small.
- **Runners stay on the existing provisioning paths.** Krystal Cloud first (the orb's katapult path), Hetzner as the switch-back, GCP spot as a port of the Hetzner scripts for overflow, not a new design.
- **Docker is pinned to 27.5.1 everywhere.** Docker 28 breaks container-to-container networking on these runners (commit `5ec47b823`).
- **A golden image, not a cold build.** A Hetzner snapshot (or GCP custom image) with Docker, the repo, pulled images, `node_modules` and the Go module cache, so a builder is useful in minutes rather than the hour a first build takes. Snapshot storage is €0.014/GB/month.
- **Builders spend the broker's `burn` pool, not their own tokens** (Edward, 2026-10-28). The pool is a separate set of accounts on the live claude-broker, reached over a restricted ssh tunnel to the docker host; `docs/ops/runbooks/claude-broker.md` has the mechanics. A `claude setup-token` token is the fallback only.
- **Builders carry dev secrets only.** The dev `.env`, no production keys; anything that needs production data runs from Edward's machine as now.

## Open questions for Edward

- Krystal Cloud: does the account's quota and spending cap allow eight builders and four runners at once, and does the 50% discount show on Cloud compute lines? Recommended: check the billing console now and raise the cap; the invoice line settles the discount.
- Hetzner limit request: how many shared cores to ask for as the fallback? Recommended: enough for four cpx62 runners (64), asked for today.
- Does the GCP spot port happen at all if Hetzner raises the limit? Recommended: build it anyway as the cheap overflow; it is a day's work and removes a single point of failure.
- Region: Recommended: Hetzner nbg1 as now; GCP europe-west1 for spot, where c3d spot was half the price of west3/west4.
- Budget ceiling for the week? Recommended: €1,000, with the fleet script printing the running spend.

## External prerequisites

- [ ] Krystal Cloud quota, spending cap and payment card checked; discount confirmed on Cloud compute. Owner: Edward. Start now.
- [x] Katapult token rotated on 2026-10-28 in both places the old expired one lived: the reaper on the cache VM (`/etc/katapult-reaper.env`, answers 200 again) and the CircleCI project variable `KATAPULT_API_TOKEN` (ends with the same four characters as the new token).
- [ ] Hetzner shared-core limit increase, as the fallback. Owner: Edward. Start now.
- [ ] GCP project with billing, Compute API, and spot vCPU quota in europe-west1 (new projects get a low quota; the request takes days). Owner: Edward.
- [ ] The accounts for the window imported into the live broker and moved into the `burn` pool (`scripts/katapult/broker-pool.sh add <id>`). Owner: Edward. Done so far (2026-10-28): the pool mechanics on the host, the tunnel user, the loopback port, the burn-order setting; the pool itself is empty.
- [ ] Hetzner API token and an SSH key for builders in the same project as the runners (exists for runners).

## Tasks to do before the window

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Builder provisioning: `scripts/katapult/provision-builder.sh` and `builder-bootstrap.sh` (written 2026-10-28; Docker 27.5.1, cache mirrors, swap, Node 22, Claude Code, repo clone, `run-task` entrypoint for `ralph.sh`); a Hetzner variant only if the fallback is ever needed | - | A | scripts/katapult |
| 2 | Golden snapshot build and refresh script (pull images, warm caches, snapshot) | 1 | A | scripts/hetzner |
| 3 | GCP spot runner: port of cloud-init and provision/reap scripts; registry reachability; CircleCI registration under the existing resource class | - | B | scripts/gcp, .circleci/orb |
| 4 | Fleet script: `./freegle fleet up <track> | ls | reap <name>` listing VM, branch, PR, uptime and spend | 1 | A | freegle CLI |
| 5 | Dry run: one builder on a small real task end to end, timed from create to PR open; fix what it finds. Done 2026-10-28 up to the smoke test: VM in 75 to 99 s, runner registered in 2 min; a real task waits for accounts in the pool | 2, 4 | A | - |
| 6 | Runner concurrency check: `scripts/katapult/provision-runner.sh` proves one registers; then two at once and two PRs building in parallel, with `RUNNER_PROVIDER` switched to katapult and `MAX_VMS` raised from 2 | - | B | .circleci/config.yml |
| 7 | Ops page for the fleet: how to start, watch and stop it, with no secrets | 5 | A | docs/ops |

## Acceptance

- A new builder is doing useful work (tests passing on the warm stack) within 15 minutes of the create call, from the golden image.
- Two builders and two runners run at the same time with no shared host between any of them, and Playwright on each runner sees no network-changed aborts.
- A runner preempted mid-job causes one CircleCI rerun and nothing else.
- The fleet listing shows every VM with its branch and PR, and nothing is left running an hour after its PR is open.
- No production secret is on any VM.

## Sources

- Google Cloud spot prices: [gcloud-compute.com c3d-standard-16](https://gcloud-compute.com/c3d-standard-16.html), [gcloud-compute.com n2d-standard-16](https://gcloud-compute.com/n2d-standard-16.html), [DevZero c3d-standard-16](https://www.devzero.io/instances/gcp/c3d-standard-16), [Spot VMs pricing](https://cloud.google.com/spot-vms/pricing)
- Krystal Cloud: [Cloud pricing](https://katapult.io/pricing/), [charity hosting discount](https://help.krystal.io/sales-faq/how-to-get-free-charity-hosting-with-krystal); the 50% Freegle discount is in the February 2023 and April 2025 mail threads and on the production invoices
- Hetzner: [Price adjustment 15 June 2026](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/), [Northflank breakdown](https://northflank.com/blog/hetzner-cloud-server-price-increases), [cloudtally price list](https://cloudtally.eu/hetzner-cloud-prices)
