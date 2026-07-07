# CircleCI Self-Hosted Runner

Custom Docker image for CircleCI self-hosted runner with all tools needed for Freegle builds.

## Build

```bash
cd circleci-runner
docker build -t freegle-circleci-runner .
```

## Run

```bash
docker run -d \
    --name circleci-runner \
    --restart=always \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -e CIRCLECI_RUNNER_NAME="docker-runner" \
    -e CIRCLECI_RUNNER_API_AUTH_TOKEN="<your-runner-token>" \
    -e CIRCLECI_RUNNER_RESOURCE_CLASS="freegle/circleci-docker-runner" \
    freegle-circleci-runner
```

## Get a Runner Token

If you need a new token:

```bash
circleci runner token create freegle/circleci-docker-runner --nickname "docker-runner"
```

## Included Tools

- git
- docker CLI
- docker-compose
- curl
- jq
- sudo (passwordless for circleci user)
