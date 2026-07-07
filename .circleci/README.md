# CircleCI Configuration for FreegleDocker

This directory contains CircleCI configuration that runs integration tests for the monorepo.

## Overview

The CircleCI pipeline:
- **Runs full integration tests** using the complete Docker Compose stack
- **Scheduled integration tests** every 2 days

## Workflows

### 1. `build-and-test`
- **Trigger**: Push to `master` branch
- **Purpose**: Run the full test suite (Go, PHP, Laravel, Playwright)

### 2. `scheduled-integration-test`
- **Trigger**: Scheduled cron job every 2 days at 4pm UTC (`0 16 1,3,5,7,9,11,13,15,17,19,21,23,25,27,29,31 * *`)
- **Branch**: Only runs on `master`
- **Purpose**: Regular automated integration testing

## Test Execution

Tests are executed via the status container's API endpoints:

```bash
# PHPUnit tests
curl -X POST http://localhost:8081/api/tests/php

# Go tests
curl -X POST http://localhost:8081/api/tests/go

# Playwright tests
curl -X POST http://localhost:8081/api/tests/playwright
```

The status container provides a unified API for test execution, making it easy to integrate with CircleCI or run tests manually.

## Manual Testing

### Trigger via CircleCI UI
1. Go to CircleCI dashboard
2. Select FreegleDocker project  
3. Click "Trigger Pipeline"
4. Select branch and optionally set parameters

### Trigger via API
```bash
curl -X POST \
  -H "Circle-Token: YOUR_CIRCLECI_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"branch": "master"}' \
  https://circleci.com/api/v2/project/github/Freegle/Iznik/pipeline
```

## Environment Variables

The configuration uses these environment variables:

- `CIRCLE_BRANCH` - Current branch being built
- `CIRCLE_BUILD_NUM` - CircleCI build number
- `CIRCLE_BUILD_URL` - URL to build details
- `CIRCLE_SHA1` - Git commit SHA

## SSH Debugging (Preferred Method)

**IMPORTANT**: When pushing changes that will trigger CI, ALWAYS cancel the auto-triggered run and rerun with SSH enabled. This ensures you can diagnose and fix failures on the live CI machine instead of iterating blind.

### Standard Push-and-Monitor Workflow

After every `git push` that triggers CI:

1. Find the auto-triggered pipeline
2. Get its workflow and job IDs
3. Cancel the workflow
4. Rerun with `enable_ssh: true`
5. SSH in and monitor tests

**Never just push and wait** - always ensure SSH access is available.

### Authentication

The CLI token in `~/.circleci/cli.yml` (CCIPAT_*) works with the REST API v2 via **basic auth** (`-u "$TOKEN:"`). The `Circle-Token` header does NOT work with CCIPAT tokens.

### Triggering SSH Rerun via API

```bash
# 1. Get the CLI token
CIRCLECI_PAT=$(grep '^token:' ~/.circleci/cli.yml | awk '{print $2}')

# 2. Find the latest pipeline
curl -s -u "$CIRCLECI_PAT:" \
  "https://circleci.com/api/v2/project/gh/Freegle/Iznik/pipeline?branch=master" \
  | python3 -c "import sys,json
for p in json.load(sys.stdin).get('items',[])[:3]:
    print(f\"Pipeline: {p['id']} state: {p.get('state')}\")"

# 3. Get workflow and job IDs
curl -s -u "$CIRCLECI_PAT:" \
  "https://circleci.com/api/v2/pipeline/<PIPELINE_ID>/workflow" | python3 -c "..."
curl -s -u "$CIRCLECI_PAT:" \
  "https://circleci.com/api/v2/workflow/<WORKFLOW_ID>/job" | python3 -c "..."

# 4. Rerun with SSH enabled
curl -s -X POST -u "$CIRCLECI_PAT:" -H "Content-Type: application/json" \
  "https://circleci.com/api/v2/workflow/<WORKFLOW_ID>/rerun" \
  -d '{"enable_ssh": true, "jobs": ["<JOB_ID>"]}'

# 5. Get SSH connection details from "Enable SSH" step output
curl -s -u "$CIRCLECI_PAT:" \
  "https://circleci.com/api/v1.1/project/github/Freegle/Iznik/<JOB_NUMBER>/output/101/0" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)[0]['message'])"

# 6. Connect
ssh -o StrictHostKeyChecking=no -p <PORT> <IP>
```

### Key Notes

- SSH details are in the "Enable SSH" step output (step index 101, action 0 in the v1.1 API)
- The `node` field in the v1.1 job API is often null; always use the step output instead
- SSH sessions timeout after ~2 hours of idle time
- `enable_ssh: true` requires `jobs: [...]` and is mutually exclusive with `from_failed`
- On the CI machine, use `docker exec` to run commands inside containers
- Test fixes on the CI machine first, then push changes for a clean run
- Use `curl -s http://localhost:8081/api/tests/go/status` (etc.) to check test progress

## Monitoring and Debugging

### Build Artifacts
Each build collects:
- Docker Compose logs (`docker-logs.txt`)
- Container status (`docker-status.txt`)
- Playwright HTML report (if available)
- Build information (`build-info.txt`)

### Common Issues

1. **Docker startup failures**: Check `docker-logs.txt` artifact
2. **Test timeouts**: Tests timeout after 30 minutes - may need adjustment
3. **Git push failures**: Check repository permissions and branch protection

### Resource Management

- **Docker Layer Caching**: Enabled to speed up builds
- **Timeouts**: Generous timeouts for Docker environment startup
- **Cleanup**: Always cleans up Docker resources after completion
- **Artifacts**: Retained for debugging failed builds

## Configuration Customization

Key configuration options:

```yaml
# Adjust schedule frequency
cron: "0 16 1,3,5,7,9,11,13,15,17,19,21,23,25,27,29,31 * *"  # Every 2 days at 4pm UTC

# Modify test timeout  
timeout_duration=1800  # 30 minutes

# Change Docker startup wait
sleep 60  # Wait time after docker-compose up

# Adjust service readiness check
for i in {1..60}; do  # 60 attempts = 10 minutes max
```

## Self-Hosted Runner

The `build-and-test` job can run on a self-hosted CircleCI runner for significantly faster builds. A setup workflow checks runner availability and routes automatically — if the runner is online, tests run locally; if not, they fall back to CircleCI cloud.

### How It Works

1. **Setup workflow** (`config.yml`): A lightweight cloud job checks the CircleCI runner API for online runners in `freegle/circleci-docker-runner`
2. **Continuation** (`continue-config.yml`): Routes to `build-test-local` (self-hosted) or `build-test-cloud` depending on availability
3. **Other workflows** (`build-android-apps`, `deploy-apps`, etc.) always run on CircleCI cloud regardless

### Speed Comparison (Cloud vs Local)

| Step | Cloud | Local | Improvement |
|------|-------|-------|-------------|
| Build containers | ~8 min | <1 min (cached) | ~7 min saved |
| Wait for prod containers | ~5.5 min | 0s | 5.5 min saved |
| Vitest | ~6 min | ~3.5 min | 42% faster |
| Parallel tests (Go/Laravel/Playwright) | ~21 min | ~3.5 min | 6x faster |
| **Total** | **~42 min** | **~10 min** | **~75% faster** |

The local runner benefits from: Docker layer cache (persistent between runs), faster disk I/O, more CPU cores, and no VM startup overhead.

### Runner Setup

The runner runs in a dedicated WSL2 distro called `circleci-runner`, separate from the main development distro.

**Runner location and config:**
```
/opt/circleci-runner/circleci-runner          # Binary
/opt/circleci-runner/circleci-runner-config.yaml  # Config
/opt/circleci-runner/start.sh                 # Boot script
```

**Key config settings:**
- `resource_class: freegle/circleci-docker-runner`
- `cleanup_working_directory: false` (preserves Docker cache between runs)
- `max_run_time: 2h`

**Prerequisites in the runner distro:**
- Docker and docker-compose
- Git
- curl, jq, sysstat
- Node.js 22 (for Vitest)
- Passwordless sudo for `circleci` user

### Starting the Runner

The runner distro starts automatically on Windows boot via a script in the Windows Startup folder (`start-wsl-on-boot.bat`). It also starts keepalive sessions to prevent WSL idle termination.

To start manually:
```bash
# From the main WSL distro
wsl.exe -d circleci-runner -- echo started
# Start keepalive sessions
nohup wsl.exe -d circleci-runner -- bash -c 'while true; do sleep 60; done' > /dev/null 2>&1 &
nohup wsl.exe -d circleci-runner -- bash -c 'while true; do sleep 300; done' > /dev/null 2>&1 &
```

### Orb Compatibility

The orb (`freegle/tests`) detects the self-hosted runner via `SELF_HOSTED_RUNNER` env var and adjusts behaviour:
- **Docker cache**: Uses layer cache on self-hosted (skips `--no-cache`), forces rebuild on cloud
- **Docker cleanup**: Pre-checkout step cleans up containers/volumes from previous runs (self-hosted only)
- **Path resolution**: All scripts use CWD-relative paths first (`./scripts/`, `./iznik-nuxt3/`) for runner compatibility, with fallbacks to `~/project/` (cloud) and `~/FreegleDocker/`

### Troubleshooting

```bash
# Check runner is running
wsl.exe -d circleci-runner -- ps aux | grep circleci-runner

# Check runner logs
wsl.exe -d circleci-runner -- cat /opt/circleci-runner/logs/*.log | tail -50

# Check Docker containers in runner
wsl.exe -d circleci-runner -- docker ps

# Restart the runner
wsl.exe -d circleci-runner -- sudo kill -9 $(wsl.exe -d circleci-runner -- pgrep circleci-runner)
wsl.exe -t circleci-runner
wsl.exe -d circleci-runner -- echo restarted
```

## Security Considerations

- API tokens should be stored as encrypted secrets
- Self-hosted runner has passwordless sudo for build operations

## Benefits

✅ **Comprehensive Testing**: Full Docker stack validation
✅ **Artifact Collection**: Debugging information preserved for failed builds
✅ **Rollback Safety**: Only commits updates if tests pass
