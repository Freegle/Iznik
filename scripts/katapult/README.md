# Krystal Cloud (Katapult) VMs for the token window

One VM per development stream, and CI runners, on the account that already hosts production
at Freegle's charity discount. The plan is `plans/token-burn/infrastructure.md`.

| Script | What it does |
|---|---|
| `lib.sh` | API helpers; sourced by the others. Org, Ubuntu template and data centre ids match the CI path in `.circleci/config.yml`. |
| `provision-builder.sh <track> [--package C-48] [--prove]` | Build a VM, bootstrap it (Docker 27.5.1 pinned, cache mirrors, swap, Node 22, Claude Code, `builder` user, repo clone on a branch, `run-task`), smoke-test it. `--prove` destroys it afterwards. |
| `builder-bootstrap.sh` | Runs on the VM; what the builder is made of. |
| `provision-runner.sh [--new-token] [--prove]` | Build a VM and configure it as a CircleCI runner with the existing `scripts/configure-katapult-vm.sh`; waits for it to register. |
| `list.sh [--packages]` | VMs in the org, or the package sizes. |
| `destroy-vm.sh <id-or-name>... \| --all-builders` | Delete builders or runners, nothing else. |

Needs `KATAPULT_API_TOKEN` (env or `./.env`; a Krystal Cloud API token with VM build and delete
rights), `sshpass`, `jq`, `curl`. `provision-runner.sh` also needs `RUNNER_AUTH_TOKEN` for
`freegle/katapult-runner`, or `--new-token` with `CIRCLECI_TOKEN` to mint and later delete one.
`CLAUDE_CODE_OAUTH_TOKEN` (from `claude setup-token`) is passed to builders when set.

Proof run, both ways, from the repo root:

```
scripts/katapult/provision-builder.sh proof --prove
scripts/katapult/provision-runner.sh --new-token --prove
```

Each prints the time from create to ready and destroys what it made. Nothing here touches a VM
whose name does not start with `tb-builder-` or `circleci-runner-`.
