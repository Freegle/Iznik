# Freegle Tests Orb

This orb provides shared CircleCI commands and jobs for testing Freegle repositories.


## Publishing the Orb

### Initial Setup (one time) - COMPLETED

The namespace and orb have already been created:

1. ~~Create a CircleCI namespace for Freegle:~~
   ```bash
   circleci namespace create freegle github Freegle
   ```

2. ~~Create the orb:~~
   ```bash
   circleci orb create freegle/tests
   ```

### Publishing Updates

1. Validate the orb:
   ```bash
   circleci orb validate .circleci/orb/freegle-tests.yml
   ```

2. Publish a dev version (for testing):
   ```bash
   circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@dev:first
   ```

3. Publish a production version (increment version number):
   ```bash
   circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.1.0
   ```

Note: You need a CircleCI API token. Set it via `circleci setup` or use `--token` flag.

## Usage in Repositories

### iznik-server (PHPUnit tests)

```yaml
version: 2.1

orbs:
  freegle: freegle/tests@1.0.0

workflows:
  pr-tests:
    jobs:
      - freegle/php-tests:
          filters:
            branches:
              ignore:
                - master
                - main
```

### iznik-server-go (Go tests)

```yaml
version: 2.1

orbs:
  freegle: freegle/tests@1.0.0

workflows:
  pr-tests:
    jobs:
      - freegle/go-tests:
          filters:
            branches:
              ignore:
                - master
                - main
```

### iznik-nuxt3 (Playwright tests)

```yaml
version: 2.1

orbs:
  freegle: freegle/tests@1.0.0

workflows:
  pr-tests:
    jobs:
      - freegle/playwright-tests:
          filters:
            branches:
              ignore:
                - master
                - main
                - production
                - modtools
```

## Available Jobs

### Test Jobs
- `freegle/build-and-test` - Full FreegleDocker build and test (all three test suites)
- `freegle/php-tests` - Run PHPUnit tests for iznik-server PRs
- `freegle/go-tests` - Run Go tests for iznik-server-go PRs
- `freegle/playwright-tests` - Run Playwright E2E tests for iznik-nuxt3 PRs

### App Build Jobs
- `freegle/increment-version` - Increment app version number
- `freegle/build-android-debug` - Build debug APK for testing
- `freegle/build-android` - Build and deploy to Google Play beta track
- `freegle/build-ios` - Build and deploy to TestFlight
- `freegle/auto-promote-production` - Auto-promote beta to production after 24 hours
- `freegle/auto-submit-ios` - Auto-submit TestFlight build to App Store review
- `freegle/auto-release-ios` - Auto-release approved apps from pending state

## Available Commands

If you need more control, you can use individual commands:

### Test Commands
- `freegle/setup-dependencies` - Install system dependencies and docker-compose
- `freegle/start-docker-services` - Start Docker services with auto-retry
- `freegle/start-services` - Start Docker services
- `freegle/wait-for-basic-services` - Wait for API v1/v2
- `freegle/wait-for-prod-container` - Wait for prod containers
- `freegle/run-go-tests` - Run Go tests
- `freegle/run-php-tests` - Run PHPUnit tests
- `freegle/run-playwright-tests` - Run Playwright tests
- `freegle/collect-artifacts` - Collect test results
- `freegle/upload-coverage` - Upload coverage to Coveralls

### App Build Commands
- `freegle/setup-android-fastlane` - Install Fastlane and decode Google Play API key

## Coverage Upload

The `upload-coverage` command supports Go, PHP, and Playwright coverage uploads to Coveralls.

### Parameters

- `expected` (optional): Comma-separated list of expected coverage types. If specified, the job fails if any expected coverage is missing.

### Usage Examples

```yaml
# Upload coverage without validation (graceful)
- freegle/upload-coverage

# Require Go coverage
- freegle/upload-coverage:
    expected: "go"

# Require PHP coverage
- freegle/upload-coverage:
    expected: "php"

# Require all coverage types (used in build-and-test)
- freegle/upload-coverage:
    expected: "go,php,playwright"
```

### Environment Variables

- `COVERALLS_REPO_TOKEN` - Required for coverage upload. If not set and `expected` is specified, the job fails.

## PR Comments

When tests run in CircleCI, results can be posted as PR comments because the workflow has access to the PR context.
