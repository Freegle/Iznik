#!/bin/bash
#
# ralph.sh - Thin wrapper around ralphy-cli for Freegle development
#
# Delegates to the community-maintained ralphy (https://github.com/michaelshimeles/ralphy)
# for the core autonomous AI coding loop, while adding Freegle-specific pre-flight checks.
#
# Usage:
#   ./ralph.sh <plan-file> [options]              - Execute a plan (PRD) file
#   ./ralph.sh -t "task description" [options]    - Execute a single task
#   ./ralph.sh --help                             - Show ralphy help
#
# Examples:
#   ./ralph.sh plans/active/my-feature.md
#   ./ralph.sh plans/active/my-feature.md --max-iterations 5
#   ./ralph.sh -t "Fix failing CircleCI tests"
#   ./ralph.sh -t "Add unit tests for UserController" --fast
#   ./ralph.sh --parallel plans/active/big-refactor.md
#
# All ralphy flags are supported (--parallel, --fast, --model, etc.)
# See: ralphy --help

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colours
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() {
    local level="$1"; shift
    local colour=""
    case "$level" in
        INFO) colour="$BLUE" ;;
        OK) colour="$GREEN" ;;
        WARN) colour="$YELLOW" ;;
        ERROR) colour="$RED" ;;
    esac
    echo -e "${colour}[ralph] [$level] $*${NC}"
}

# =============================================================================
# Pre-flight checks (Freegle-specific)
# =============================================================================

check_ralphy() {
    if ! command -v ralphy &> /dev/null; then
        log ERROR "ralphy not found. Install with: sudo npm install -g ralphy-cli"
        exit 1
    fi
}

check_claude() {
    if ! command -v claude &> /dev/null; then
        log ERROR "claude CLI not found. Install Claude Code first."
        exit 1
    fi
}

check_docker_containers() {
    # Quick health check - are the critical containers running?
    local missing=()

    for container in freegle-apiv2 freegle-apiv1 percona; do
        if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q "$container"; then
            missing+=("$container")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log WARN "Containers not running: ${missing[*]}"
        log WARN "Start with: docker compose up -d"
        echo ""
        read -p "Continue anyway? (y/n): " -n 1 -r
        echo ""
        [[ ! $REPLY =~ ^[Yy]$ ]] && exit 0
    else
        log OK "Docker containers running."
    fi
}

check_git_clean() {
    local has_changes=false

    if [[ -n $(git -C "$SCRIPT_DIR" status --porcelain 2>/dev/null) ]]; then
        log WARN "FreegleDocker has uncommitted changes."
        has_changes=true
    fi

    for sub in iznik-nuxt3 iznik-server iznik-server-go iznik-batch; do
        if [[ -d "$SCRIPT_DIR/$sub" && -n $(git -C "$SCRIPT_DIR/$sub" status --porcelain 2>/dev/null) ]]; then
            log WARN "$sub has uncommitted changes."
            has_changes=true
        fi
    done

    if $has_changes; then
        log WARN "Uncommitted changes detected. Ralphy will see and can build on them."
    fi
}

# =============================================================================
# Argument parsing - translate our conventions to ralphy flags
# =============================================================================

build_ralphy_args() {
    RALPHY_ARGS=()
    local plan_file=""
    local task_string=""
    local passthrough=()

    while [[ $# -gt 0 ]]; do
        case "$1" in
            -t|--task)
                task_string="$2"
                shift 2
                ;;
            --help|-h)
                ralphy --help
                exit 0
                ;;
            --parallel|--fast|--no-tests|--no-lint|--no-commit|--dry-run|--sandbox)
                passthrough+=("$1")
                shift
                ;;
            --max-iterations|--max-retries|--retry-delay|--max-parallel|--model|--base-branch)
                passthrough+=("$1" "$2")
                shift 2
                ;;
            --sonnet|--branch-per-task|--create-pr|--draft-pr|--verbose|-v)
                passthrough+=("$1")
                shift
                ;;
            --)
                # Pass everything after -- directly to the engine
                shift
                passthrough+=("--" "$@")
                break
                ;;
            *)
                # If it looks like a file, treat as PRD
                if [[ -f "$1" ]]; then
                    plan_file="$1"
                elif [[ "$1" =~ ^[0-9]+$ ]]; then
                    # Legacy: number after plan file was max-iterations
                    passthrough+=("--max-iterations" "$1")
                else
                    # Unknown arg, pass through
                    passthrough+=("$1")
                fi
                shift
                ;;
        esac
    done

    # Build the ralphy command via global array to preserve quoting
    if [[ -n "$task_string" ]]; then
        # Single task mode
        RALPHY_ARGS+=("$task_string")
    elif [[ -n "$plan_file" ]]; then
        # PRD mode
        RALPHY_ARGS+=("--prd" "$plan_file")
    else
        # No args - ralphy will look for PRD.md
        :
    fi

    RALPHY_ARGS+=("${passthrough[@]}")
}

# =============================================================================
# Main
# =============================================================================

main() {
    echo ""
    echo -e "  ${GREEN}ralph${NC} - Freegle Autonomous AI Coding"
    echo -e "  ${BLUE}Powered by ralphy (github.com/michaelshimeles/ralphy)${NC}"
    echo ""

    # Show help if no args
    if [[ $# -eq 0 ]]; then
        echo "Usage:"
        echo "  $0 <plan-file> [ralphy-options]           - Execute a plan file"
        echo "  $0 -t \"task description\" [ralphy-options]  - Execute a single task"
        echo "  $0 --help                                  - Show all ralphy options"
        echo ""
        echo "Examples:"
        echo "  $0 plans/active/my-feature.md"
        echo "  $0 plans/active/my-feature.md --max-iterations 5"
        echo "  $0 -t \"Fix failing tests\" --fast"
        echo "  $0 --parallel plans/active/big-refactor.md"
        echo ""
        echo "Available plans:"
        find "$SCRIPT_DIR/plans" -name "*.md" -type f 2>/dev/null | sort
        exit 1
    fi

    # Pre-flight checks
    check_ralphy
    check_claude
    check_docker_containers
    check_git_clean

    # Build ralphy arguments (populates global RALPHY_ARGS array)
    build_ralphy_args "$@"

    log INFO "Running: ralphy ${RALPHY_ARGS[*]}"
    echo ""

    # Execute ralphy from the project directory
    cd "$SCRIPT_DIR"
    ralphy "${RALPHY_ARGS[@]}"
}

main "$@"
