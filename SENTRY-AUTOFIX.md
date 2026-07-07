# Sentry Auto-Fix Integration

Automatically analyzes Sentry issues using Claude Code CLI and creates PRs with fixes and test cases.

## Requirements

- Node.js 18+ installed on the host
- Claude Code CLI installed (`claude` command available)
- Sentry API auth token
- GitHub CLI (`gh`) installed and authenticated

## Installation

1. Install Node.js dependencies:
```bash
cd /path/to/FreegleDocker
npm install dotenv better-sqlite3
```

2. Ensure Claude CLI is installed:
```bash
claude --version
```

3. Configure Sentry in `.env`:
```bash
SENTRY_AUTH_TOKEN=sntryu_your_token_here
SENTRY_ORG_SLUG=freegle
FREEGLE_BASE_PATH=/path/to/FreegleDockerWSL  # Optional, defaults to current directory
```

## Usage

### Auto-Fix Tool

```bash
# Analyze one issue and exit
./sentry-autofix --poll-one

# Run continuously (every 30 min)
./sentry-autofix --daemon 30

# Debug mode (no Sentry comments)
./sentry-autofix --poll-one --no-comments

# Show help
./sentry-autofix --help
```

### PR Review Tool

Review and address comments on auto-generated PRs:

```bash
# Interactive mode - select from list
./sentry-pr-review

# Review specific PR
./sentry-pr-review --pr 123

# List all Sentry PRs
./sentry-pr-review --list

# Show help
./sentry-pr-review --help
```

The PR review tool:
- Lists all PRs created by the Sentry auto-fix system across all repositories
- Shows PR status (open/merged/closed) and creation date
- Launches Claude Code with full PR context including review comments
- Allows you to address feedback and push changes

## How It Works

### Branch Management

The tool automatically manages git branches:
- Stores the original branch at the start of processing (e.g., `master` or `modtools`)
- Creates a new branch for fixes (e.g., `sentry-auto-fix-1234567890`)
- **Always restores the original branch after processing** (even if errors occur)
- For modtools repository: restores to `modtools` branch
- For all other repositories: restores to `master` branch

This ensures your local repositories are always left in their original state.

### Analysis Strategy

The tool uses a simplified diagnostic approach focusing on obvious fixes:

**Criteria for Processing:**
- Only fixes obvious issues: missing parameters, typos, missing null checks
- Fix must be within a **single method** (no multi-file refactoring)
- Requires **90%+ confidence** the fix will work
- Automatically skips complex issues requiring deep investigation

**Examples of Fixable Issues:**
- `array_key_exists(): Argument #2 must be of type array, null given` → add null check
- Missing function argument → add default parameter
- Typo in variable name → fix spelling

**Filtering:**
- **Priority ordering**: Processes issues by most affected users first across all projects
- **Age filtering**: Skips issues not seen in the past week
- **Active issues only**: Skips issues with 0 events in the last 24h
- **Confidence filtering**: Skips fixes with confidence below "high"
- **Complexity filtering**: Skips fixes not classified as "simple"

### Test Verification

Tests are run by CircleCI after PR creation:
- PRs are created with suggested fixes (no local test execution)
- CircleCI runs the full test suite on each PR
- Tests must pass on CircleCI before the PR can be merged
- This approach is faster and more reliable than local test execution

## Error Handling

The tool includes comprehensive error handling:
- Validates repository paths on startup
- Uses temp files for PR bodies to avoid shell escaping issues
- Gracefully recovers from PR creation failures
- Provides clear error messages with remediation steps
- **Always restores original branch** even if processing fails

If you see "Repository path does not exist" errors, set `FREEGLE_BASE_PATH` in your `.env` file.

See full documentation in the script help output.

## Future Enhancements

Planned improvements to the auto-fix system:

### CircleCI Integration (Coming Soon)

- Check PR test results automatically via CircleCI API
- Parse test failures to determine if related to the fix
- Check for review comments via GitHub API
- Automatically update fixes based on CI failures or review feedback
- Periodic PR monitoring (hourly) while PRs remain open

This will enable the system to iterate on fixes based on actual test results and human feedback.

