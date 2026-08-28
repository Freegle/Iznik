#!/bin/bash
# File sync script for Freegle Docker development
# Monitors WSL filesystem changes and syncs to Docker containers
# Uses a "settle" pattern - waits for files to stop changing before syncing

# Skip file-sync in CI - not needed since code is in containers from Docker build
if [ "${CI:-false}" = "true" ]; then
    echo "CI mode: file-sync disabled (code is already in containers from build)"
    exit 0
fi

# Use the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"

# Container name prefix from COMPOSE_PROJECT_NAME (defaults to 'freegle')
CN="${COMPOSE_PROJECT_NAME:-freegle}"

# Host-side path of THIS checkout. file-sync runs inside ${CN}-host-scripts, whose
# /workspace bind mount is this checkout's directory on the host. Docker's compose
# labels and bind-mount sources are HOST paths, so we need this to tell our own
# containers apart from a different checkout's (worktree-escape guard below). Left
# empty if it can't be resolved, in which case that guard fails OPEN (never blocks a
# legitimate sync); the bind-mount guard does not depend on it.
MY_HOST_DIR=$(timeout 5 docker inspect "${CN}-host-scripts" \
    --format '{{range .Mounts}}{{if eq .Destination "/workspace"}}{{.Source}}{{end}}{{end}}' 2>/dev/null)
[[ -z "$MY_HOST_DIR" ]] && echo "WARNING: could not resolve this checkout's host dir from ${CN}-host-scripts; the worktree-escape guard is DISABLED for this run (the bind-mount guard, which prevents source deletion, is unaffected)." >&2

# Queue file for pending syncs
QUEUE_FILE="/tmp/freegle-sync-queue.$$"
QUEUE_LOCK="/tmp/freegle-sync-queue.$$.lock"

echo "Starting Freegle file sync monitor..."
echo "Project: $PROJECT_DIR"
echo "Press Ctrl+C to stop"
echo ""

# Cleanup on exit
cleanup() {
    rm -f "$QUEUE_FILE" "$QUEUE_LOCK"
    kill $SETTLE_PID 2>/dev/null
    kill $BACKFILL_PID 2>/dev/null
}
trap cleanup EXIT

# Function to get file mtime
get_mtime() {
    local file="$1"
    if [[ -f "$file" ]]; then
        stat -c %Y "$file" 2>/dev/null || echo "0"
    else
        echo "0"
    fi
}

# Function to get file size
get_size() {
    local file="$1"
    if [[ -f "$file" ]]; then
        stat -c %s "$file" 2>/dev/null || echo "0"
    else
        echo "0"
    fi
}

# Which of our containers are up, cached for ~5s. The routing below asks this up
# to four times per file, so a settle burst of N files used to cost 4N `docker ps`
# invocations; the back-fill classifies a few thousand files at a time.
_RUNNING_CACHE=""
_RUNNING_TS=-1000
is_running() {
    if (( SECONDS - _RUNNING_TS >= 5 )); then
        _RUNNING_CACHE=$(timeout 5 docker ps --format '{{.Names}}' 2>/dev/null)
        _RUNNING_TS=$SECONDS
    fi
    [[ $'\n'"$_RUNNING_CACHE"$'\n' == *$'\n'"$1"$'\n'* ]]
}

# Routing for one file, as "<container> <target path> <label>" lines. Assigns to
# GCI_RESULT rather than echoing, so the back-fill can classify thousands of
# files without forking a subshell each time; get_container_info below keeps the
# echoing contract every other caller expects.
_compute_container_info() {
    local file_path="$1"
    local relative_path="${file_path#$PROJECT_DIR/}"
    GCI_RESULT=""

    # ModTools is now inside iznik-nuxt3/modtools - check this first before generic iznik-nuxt3
    if [[ "$relative_path" == iznik-nuxt3/modtools/* ]]; then
        # Strip iznik-nuxt3/ prefix, keep modtools/ for the container path
        local targets="${CN}-modtools-dev-local /app/${relative_path#iznik-nuxt3/} ModTools-Dev-Local"
        # Only include dev-live if the container is running
        if is_running "${CN}-modtools-dev-live"; then
            targets="$targets"$'\n'"${CN}-modtools-dev-live /app/${relative_path#iznik-nuxt3/} ModTools-Dev-Live"
        fi
        GCI_RESULT="$targets"
    elif [[ "$relative_path" == iznik-nuxt3/plugins/* || "$relative_path" == iznik-nuxt3/composables/* || "$relative_path" == iznik-nuxt3/stores/* || "$relative_path" == iznik-nuxt3/utils/* || "$relative_path" == iznik-nuxt3/components/* || "$relative_path" == iznik-nuxt3/assets/* || "$relative_path" == iznik-nuxt3/pages/* || "$relative_path" == iznik-nuxt3/layouts/* || "$relative_path" == iznik-nuxt3/middleware/* || "$relative_path" == iznik-nuxt3/api/* || "$relative_path" == iznik-nuxt3/server/* || "$relative_path" == iznik-nuxt3/public/* || "$relative_path" == iznik-nuxt3/nuxt.config.ts || "$relative_path" == iznik-nuxt3/constants.js ]]; then
        # Shared code used by both Freegle and ModTools - sync to all containers.
        # assets/ is here rather than in the generic iznik-nuxt3/* branch below because that
        # branch skips modtools-dev-local, which is where vitest runs: a unit test that reads
        # a stylesheet (e.g. for the feed card sizing constants) would otherwise see the
        # copy baked into the image rather than the edit on disk.
        # pages/, layouts/ and middleware/ are here for the same reason: specs in
        # tests/unit/pages import ~/pages/<x>.vue directly, so without this the
        # spec syncs but the page under test does not, and vitest silently
        # validates the copy baked into the image instead of the edit on disk.
        # api/ and server/ are here for that same reason, and it had already bitten:
        # tests/unit/api/*.spec.js import ~/api/BaseAPI.js and friends, and
        # tests/unit/server/sitemap.spec.js imports ~/server/utils/sitemap. Because the
        # spec synced and the source did not, the two "abandoned without settling" tests
        # added with the never-settling-await fix (#1317) failed against a BAKED BaseAPI.js
        # that predated the warn() calls they assert - a false RED that reads exactly like a
        # real regression. The same gap hides false GREENs, which is worse.
        # public/ and nuxt.config.ts are here because a spec can assert on config
        # rather than import it: tests/unit/middleware/ask.global.spec.js reads
        # public/_redirects and nuxt.config.ts off disk to check the /find -> /ask
        # redirect is wired up in production too. Without them the vitest runner
        # reads the copy baked into the image and reports a stale answer.
        # constants.js is a top-level FILE, not a directory, which is how it was missed:
        # it fell through to the generic branch below, which never targets
        # modtools-dev-local. 36 source files and the unit specs import ~/constants, so
        # every new export read as undefined in a local vitest run and whole spec files
        # failed on "Cannot destructure property ... of ... as it is undefined" against
        # the copy baked into the image.
        # When adding a top-level source directory OR file that unit specs import, add it
        # here. Both of these must be a subset of this branch (plus modtools/, handled
        # above), and so must anything a spec opens with readFileSync:
        #   grep -rhoE "from '~/[a-zA-Z0-9_-]+/" iznik-nuxt3/tests/unit/ | sort -u
        #   grep -rhoE "from '~/[a-zA-Z0-9_.-]+'"  iznik-nuxt3/tests/unit/ | sort -u
        local targets="${CN}-dev-local /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Local"
        if is_running "${CN}-dev-live"; then
            targets="$targets"$'\n'"${CN}-dev-live /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Live"
        fi
        if is_running "${CN}-modtools-dev-local"; then
            targets="$targets"$'\n'"${CN}-modtools-dev-local /app/${relative_path#iznik-nuxt3/} ModTools-Dev-Local"
        fi
        if is_running "${CN}-modtools-dev-live"; then
            targets="$targets"$'\n'"${CN}-modtools-dev-live /app/${relative_path#iznik-nuxt3/} ModTools-Dev-Live"
        fi
        GCI_RESULT="$targets"
    elif [[ "$relative_path" == iznik-nuxt3/* ]]; then
        if [[ "$relative_path" == iznik-nuxt3/playwright.config.js || "$relative_path" == iznik-nuxt3/tests/e2e/* ]]; then
            # Playwright tests and config go to the Playwright container only
            GCI_RESULT="${CN}-playwright /app/${relative_path#iznik-nuxt3/} Playwright"
        elif [[ "$relative_path" == iznik-nuxt3/tests/* || "$relative_path" == iznik-nuxt3/vitest.config.mts ]]; then
            # Unit test files and vitest config go to the vitest runner container (modtools-dev-local)
            # Playwright container does not run vitest, so these are not needed there
            GCI_RESULT="${CN}-modtools-dev-local /app/${relative_path#iznik-nuxt3/} ModTools-Dev-Local"
        else
            local targets="${CN}-dev-local /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Local"
            # Only include dev-live if the container is running
            if is_running "${CN}-dev-live"; then
                targets="$targets"$'\n'"${CN}-dev-live /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Live"
            fi
            GCI_RESULT="$targets"
        fi
    elif [[ "$relative_path" == iznik-server-go/* ]]; then
        GCI_RESULT="${CN}-apiv2 /app/${relative_path#iznik-server-go/} API-v2"
    elif [[ "$relative_path" == iznik-batch/* ]]; then
        # iznik-batch is BIND-MOUNTED into the batch container (./iznik-batch ->
        # /var/www/html), so the container already sees host edits live. Running
        # `docker cp` onto that bind mount unlinks+recreates the host file and races
        # the delete-propagation path (do_delete -> docker exec rm -f), DELETING
        # newly-created host files (the PR #639 vanishing-file bug). Emit no target
        # so iznik-batch is never synced or delete-propagated here.
        :
    fi
}

# Function to determine target container
get_container_info() {
    _compute_container_info "$1"
    # An unrouted path (iznik-batch) prints nothing and still succeeds, as before.
    if [[ -n "$GCI_RESULT" ]]; then
        printf '%s\n' "$GCI_RESULT"
    fi
    return 0
}

# --- Self-heal guards --------------------------------------------------------
# Historically do_sync/do_delete ran `docker cp` / `docker exec rm -f` against
# ${CN}-<svc> purely by name. Two ways that destroys the wrong files:
#   1. Bind-mounted target: e.g. freegle-batch bind-mounts ./iznik-batch to
#      /var/www/html, so the container already sees host edits live. There `docker cp`
#      is redundant and `docker exec rm -f` DELETES THE SOURCE on the host - a transient
#      delete event (an editor's atomic save, a stray git op, the cp's own rename)
#      then gets amplified into permanent loss of a tracked file.
#   2. Worktree escape: a secondary checkout whose COMPOSE_PROJECT_NAME fell back to
#      "freegle" resolves ${CN}-<svc> to the MAIN containers and overwrites the main
#      checkout's code with its own.
# should_touch_container() gates both do_sync and do_delete. Fail direction on a docker
# inspect error is chosen by the caller: do_delete fails CLOSED (skip - never risk
# deleting a source file), do_sync fails OPEN (a redundant/missed copy is harmless).

# Per-process ~5s cache of each container's mount+label topology (it does not change at
# file-edit frequency), so a settle burst of N files costs ~1 docker inspect per
# container instead of 2N. The inotify loop and the settle drainer are separate
# processes and each keep their own copy; that is fine.
declare -A _META_CACHE   # container -> "<working_dir>\n<bind dest>\n<bind dest>..."
declare -A _META_TS      # container -> $SECONDS when cached

# Echo "<compose working_dir>\n<bind destination per line>" for a container. All docker
# inspects are `timeout`-wrapped so a hung daemon can't block do_delete (which runs
# inline in the inotify loop) indefinitely. Non-zero exit == inspect failed/timed out.
_container_meta() {
    local container="$1" meta
    if [[ -n "${_META_TS[$container]:-}" ]] && (( SECONDS - _META_TS[$container] < 5 )); then
        printf '%s' "${_META_CACHE[$container]}"
        return 0
    fi
    meta=$(timeout 5 docker inspect "$container" --format \
        '{{index .Config.Labels "com.docker.compose.project.working_dir"}}{{range .Mounts}}{{if eq .Type "bind"}}{{printf "\n"}}{{.Destination}}{{end}}{{end}}' 2>/dev/null) || return 1
    _META_CACHE[$container]="$meta"
    _META_TS[$container]=$SECONDS
    printf '%s' "$meta"
}

# Gate shared by do_sync/do_delete. $3="closed" (delete) => an inspect failure SKIPS;
# default "open" (sync) => an inspect failure PROCEEDS. Returns non-zero (skip) with a
# one-line reason when the container is another checkout's or the path is bind-mounted.
should_touch_container() {
    local container="$1" target_path="$2" failmode="${3:-open}" meta wd rest d
    if ! meta=$(_container_meta "$container"); then
        if [[ "$failmode" == closed ]]; then
            echo "  ⤳ skip $container: cannot verify mounts (docker inspect failed/timed out); not risking a delete"
            return 1
        fi
        return 0
    fi
    wd="${meta%%$'\n'*}"
    # Worktree-escape guard: the container was created by a different checkout than ours.
    if [[ -n "$MY_HOST_DIR" && -n "$wd" && "$wd" != "$MY_HOST_DIR" ]]; then
        echo "  ⤳ skip $container: belongs to another checkout ($wd, not $MY_HOST_DIR)"
        return 1
    fi
    # Bind-mount guard: host edits are already live there; cp redundant, rm deletes source.
    if [[ "$meta" == *$'\n'* ]]; then
        rest="${meta#*$'\n'}"
        while IFS= read -r d; do
            [[ -z "$d" ]] && continue
            if [[ "$target_path" == "$d" || "$target_path" == "$d"/* ]]; then
                echo "  ⤳ skip $container: $target_path is bind-mounted (host edits are already live there)"
                return 1
            fi
        done <<< "$rest"
    fi
    return 0
}

# Function to actually perform the sync
do_sync() {
    local file_path="$1"

    local container_info
    container_info=$(get_container_info "$file_path")

    if [[ -z "$container_info" ]]; then
        return
    fi

    local filename=$(basename "$file_path")
    local timestamp=$(date '+%H:%M:%S')

    # Handle multiple containers (for test files)
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            read -r container target_path service <<< "$line"
            if ! should_touch_container "$container" "$target_path"; then
                continue
            fi
            echo "[$timestamp] $service: $filename"

            # docker cp fails outright when the target's parent directory does
            # not exist in the container, so the first file of any NEW package
            # never lands and every later edit to it fails the same way. The
            # symptom is a build error naming a package that plainly exists on
            # the host, which is thoroughly confusing to debug.
            local target_dir="${target_path%/*}"
            if [[ -n "$target_dir" && "$target_dir" != "$target_path" ]]; then
                docker exec "$container" mkdir -p "$target_dir" >/dev/null 2>&1 || true
            fi

            local cp_error
            if cp_error=$(docker cp "$file_path" "$container:$target_path" 2>&1); then
                echo "  ✓ Synced to $container"
            else
                echo "  ✗ Failed to sync to $container: $cp_error"
            fi
        fi
    done <<< "$container_info"
}

# Function to propagate a deletion to the target container(s). inotifywait does
# not sync deletes by default, so a removed source/test file would otherwise
# linger in the dev container - e.g. a deleted unit test keeps being collected
# and fails the run for a file that no longer exists in the tree. Mirrors
# do_sync's target resolution, but removes the file instead of copying it.
do_delete() {
    local file_path="$1"

    local container_info
    container_info=$(get_container_info "$file_path")

    if [[ -z "$container_info" ]]; then
        return
    fi

    local filename=$(basename "$file_path")
    local timestamp=$(date '+%H:%M:%S')

    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            read -r container target_path service <<< "$line"
            # Never propagate a delete into a bind-mounted or foreign-checkout container:
            # for a bind mount that rm would delete the source file on the host, which is
            # exactly the data-loss bug this guards against. Only genuine image-baked
            # copies (dev-local, apiv1/2) need a delete mirrored. "closed" => if docker
            # inspect can't confirm the mounts, SKIP rather than risk deleting a source.
            if ! should_touch_container "$container" "$target_path" closed; then
                continue
            fi
            # rm -f is a no-op if the file was never synced, so this is safe even
            # for editor temp files that briefly appear and vanish.
            if docker exec "$container" rm -f "$target_path" 2>/dev/null; then
                echo "[$timestamp] $service: removed $filename"
            fi
        fi
    done <<< "$container_info"
}

# --- Back-fill for containers that were down ---------------------------------
# inotify only reports changes happening NOW, and the routing above only lists a
# dev-live/modtools container while it is actually running. So anything written
# while a container was stopped is never copied to it: when it later starts, /app
# still holds the copy baked into the image and nothing revisits it. The symptom
# is a build error naming a component that plainly exists on the host -
# freegle-dev-live was missing NewsUnreadDivider.vue, NewsConvertModal.vue,
# NewsConvertedNotice.vue, useIsIOS.js and useUppyHeic.js this way and could not
# compile NewsReplies.vue at all, while 45 further files had quietly gone stale.
# Reconciling at start-up, and whenever a container comes up, closes that gap.

# Mirrors the inotify --exclude list, as find -prune arguments, so the back-fill
# and the steady-state sync always consider the same set of files.
_PRUNE=( -name node_modules -o -name .git -o -name .nuxt -o -name .output
         -o -name dist -o -name vendor -o -name migrations -o -name coverage )

# Paths (relative to $root) that a normal edit would sync to $container. Reusing
# the routing function means the back-fill can never disagree with it.
files_for_container() {
    local container="$1" root="$2" f line c
    while IFS= read -r f; do
        _compute_container_info "$f"
        [[ -z "$GCI_RESULT" ]] && continue
        while IFS= read -r line; do
            read -r c _ _ <<< "$line"
            if [[ "$c" == "$container" ]]; then
                printf '%s\n' "${f#$PROJECT_DIR/$root/}"
                break
            fi
        done <<< "$GCI_RESULT"
    done < <(find "$PROJECT_DIR/$root" \( "${_PRUNE[@]}" \) -prune -o -type f -print 2>/dev/null)
}

# Copy across only what actually differs. Rewriting every file would set a Nuxt
# dev container reloading for minutes, so compare hashes first - one exec for
# their side, one pass for ours - and ship the difference as a single tar.
reconcile_container() {
    local container="$1" root="$2"
    local srcdir="$PROJECT_DIR/$root"
    [[ -d "$srcdir" ]] || return
    is_running "$container" || return
    # Same guards as do_sync: never push into a bind mount (host edits are already
    # live there) or into a container belonging to another checkout.
    should_touch_container "$container" "/app" || return

    local -a want=()
    mapfile -t want < <(files_for_container "$container" "$root")
    ((${#want[@]})) || return

    local theirs ours h p
    theirs=$(printf '%s\0' "${want[@]}" |
        timeout 180 docker exec -i "$container" sh -c \
            'cd /app 2>/dev/null || exit 0; xargs -0 -r sha1sum 2>/dev/null' 2>/dev/null)
    ours=$(cd "$srcdir" && printf '%s\0' "${want[@]}" | xargs -0 -r sha1sum 2>/dev/null)

    local -A theirhash=()
    while read -r h p; do
        [[ -n "$p" ]] && theirhash["$p"]="$h"
    done <<< "$theirs"

    local -a stale=()
    while read -r h p; do
        [[ -z "$p" ]] && continue
        [[ "${theirhash[$p]:-}" == "$h" ]] || stale+=("$p")
    done <<< "$ours"

    ((${#stale[@]})) || return

    echo "[$(date '+%H:%M:%S')] Back-fill: $container missing/stale on ${#stale[@]} file(s) from $root"
    if tar -C "$srcdir" -cf - -- "${stale[@]}" 2>/dev/null |
        timeout 180 docker cp - "$container:/app" 2>/dev/null; then
        echo "  ✓ Back-filled $container (${#stale[@]} file(s))"
    else
        echo "  ✗ Back-fill of $container failed"
    fi
}

# Containers we keep in step, and the source tree that feeds each.
_BACKFILL_TARGETS=(
    "${CN}-dev-local:iznik-nuxt3"
    "${CN}-dev-live:iznik-nuxt3"
    "${CN}-modtools-dev-local:iznik-nuxt3"
    "${CN}-modtools-dev-live:iznik-nuxt3"
    "${CN}-playwright:iznik-nuxt3"
    "${CN}-apiv2:iznik-server-go"
)

# Reconcile once at start-up, then whenever a target goes from down to up.
# Polling docker ps avoids depending on a `docker events` stream staying alive
# for the life of the monitor.
watch_for_started_containers() {
    declare -A WAS_UP
    local first=1 entry container root
    while true; do
        for entry in "${_BACKFILL_TARGETS[@]}"; do
            container="${entry%%:*}"
            root="${entry##*:}"
            if is_running "$container"; then
                if (( first )) || [[ -z "${WAS_UP[$container]:-}" ]]; then
                    reconcile_container "$container" "$root"
                fi
                WAS_UP["$container"]=1
            else
                unset 'WAS_UP[$container]'
            fi
        done
        first=0
        sleep 15
    done
}

# Background process to handle settling files
process_pending_syncs() {
    declare -A PENDING_MTIME
    declare -A ZERO_LENGTH_WAIT  # Track how long we've waited for zero-length files

    while true; do
        sleep 2

        # Read any new entries from queue file with locking
        if [[ -s "$QUEUE_FILE" ]]; then
            # Copy and clear atomically with lock
            local temp_queue="/tmp/freegle-sync-temp.$$"
            (
                flock -x 200
                cp "$QUEUE_FILE" "$temp_queue" 2>/dev/null
                > "$QUEUE_FILE"
            ) 200>"$QUEUE_LOCK"

            # Now read from temp file (outside the lock/subshell)
            if [[ -f "$temp_queue" ]]; then
                while IFS='|' read -r file_path mtime; do
                    if [[ -n "$file_path" ]]; then
                        PENDING_MTIME["$file_path"]=$mtime
                    fi
                done < "$temp_queue"
                rm -f "$temp_queue"
            fi
        fi

        # Process pending files
        for file_path in "${!PENDING_MTIME[@]}"; do
            if [[ ! -f "$file_path" ]]; then
                # File was deleted, remove from pending
                unset PENDING_MTIME["$file_path"]
                unset ZERO_LENGTH_WAIT["$file_path"]
                continue
            fi

            local current_mtime=$(get_mtime "$file_path")
            local last_mtime="${PENDING_MTIME[$file_path]}"

            if [[ "$current_mtime" == "$last_mtime" ]]; then
                # File hasn't changed in 2 seconds - it's settled
                local file_size=$(get_size "$file_path")

                if [[ "$file_size" == "0" ]]; then
                    # Zero-length file - likely still being written
                    # Wait up to 10 more seconds before giving up
                    local wait_count="${ZERO_LENGTH_WAIT[$file_path]:-0}"
                    if (( wait_count < 5 )); then
                        ZERO_LENGTH_WAIT["$file_path"]=$((wait_count + 1))
                        echo "[$(date '+%H:%M:%S')] Waiting for content: $(basename "$file_path") (attempt $((wait_count + 1))/5)"
                        continue
                    else
                        # Gave up waiting - sync anyway (might be intentionally empty)
                        echo "[$(date '+%H:%M:%S')] Warning: Syncing zero-length file after timeout: $(basename "$file_path")"
                        unset ZERO_LENGTH_WAIT["$file_path"]
                    fi
                fi

                do_sync "$file_path"
                unset PENDING_MTIME["$file_path"]
            else
                # File changed, update mtime and wait another cycle
                PENDING_MTIME["$file_path"]=$current_mtime
                # Reset zero-length wait counter since file is changing
                unset ZERO_LENGTH_WAIT["$file_path"]
            fi
        done
    done
}

# Function to queue a file for sync
queue_sync() {
    local file_path="$1"
    local mtime=$(get_mtime "$file_path")
    (
        flock -x 200
        echo "${file_path}|${mtime}" >> "$QUEUE_FILE"
    ) 200>"$QUEUE_LOCK"
}

# Install inotify-tools if not present
if ! command -v inotifywait &> /dev/null; then
    echo "Installing inotify-tools..."
    if [ -f /etc/alpine-release ]; then
        apk add --no-cache inotify-tools
    else
        sudo apt-get update && sudo apt-get install -y inotify-tools
    fi
fi

echo "Starting file watcher with settle detection..."
echo "(Files sync after 2 seconds of no changes)"
echo "(Zero-length files wait up to 10 extra seconds for content)"
echo ""

# Initialize queue file
> "$QUEUE_FILE"

# Start the settle processor in the background
process_pending_syncs &
SETTLE_PID=$!

# Bring already-running containers up to date and keep watching for ones that
# start later - inotify alone can never tell them about edits they missed.
watch_for_started_containers &
BACKFILL_PID=$!

# Monitor file changes - exclude node_modules, .git, build artifacts, and migrations
# IMPORTANT: close_write is essential - it signals when a file is fully written
# Without it, we might sync files while they're still being written (empty/partial)
# delete/moved_from are included so removals propagate to the containers too.
inotifywait -m -r -e modify,create,move,close_write,delete \
    --exclude '(node_modules|\.git|\.nuxt|\.output|dist|vendor|migrations|storage/spool|~|\.tmp|\.swp|\.log)' \
    "$PROJECT_DIR/iznik-nuxt3" \
    "$PROJECT_DIR/iznik-server-go" \
    2>/dev/null | while read -r directory events filename; do

    full_path="$directory$filename"

    # A file was removed or renamed away (DELETE / MOVED_FROM). Propagate the
    # removal to the container so a deleted source/test file doesn't linger.
    # Skip directory events (rmdir) - an empty dir in the container is harmless
    # and rm -f can't remove it. Guard on the path being genuinely gone so a
    # rename-over / delete-then-recreate save leaves the sync path to re-copy it.
    case ",$events," in
        *,DELETE,*|*,MOVED_FROM,*)
            if [[ "$events" != *ISDIR* && ! -e "$full_path" ]]; then
                do_delete "$full_path"
                continue
            fi
            ;;
    esac

    # A brand-new DIRECTORY: queue everything inside it.
    #
    # inotifywait -r only holds watches on directories that existed when it
    # started. When a whole tree appears at once - a git checkout or a branch
    # switch bringing in a new package - the files land before a watch exists on
    # their parent, so they are never seen and never sync. The container then
    # runs without them, which for Go means the entire suite fails to COMPILE
    # and reports "0 passed, 0 failed", i.e. it reads as "no tests ran" rather
    # than as a missing file (iznik-server-go/firstreply did exactly this).
    # Walking the new directory here closes that gap for the common case.
    if [[ "$events" == *ISDIR* && -d "$full_path" ]]; then
        while IFS= read -r newfile; do
            [[ -f "$newfile" ]] && queue_sync "$newfile"
        done < <(find "$full_path" -type f 2>/dev/null)
        continue
    fi

    # Only process regular files - queue them for sync after settling
    if [[ -f "$full_path" ]]; then
        queue_sync "$full_path"
    fi
done
