#!/bin/sh
# Detect tusd starved of its upload directory, and with --resolve clear it.
#
# The upload store is ONE flat NFS directory with millions of entries. Anything
# that lists it - find, ls, du, a shell tab-completion - holds the directory's
# inode lock for every getdents(), and on NFS one getdents() of a directory that
# size is a long chain of READDIRPLUS calls. Every tusd upload create, finish
# and delete needs the same lock exclusively, so they all queue behind the scan.
# Seen 2026-09-18: two orphaned `find /` processes from an ssh session left
# 1,036 tusd threads in D state, uploads hung for ~25 minutes, and the load
# average reached 1,049 with the CPU 95% idle. The NFS server was healthy the
# whole time - this is client-side lock contention, not a storage outage.
#
# Detection reads /proc only and never touches the NFS mount, so it cannot
# become part of the problem. Both probes measure at ~0.1 s on this host.
#
#   - tusd threads in D state: the depth of the queue of blocked uploads.
#   - scanners: processes other than tusd holding an fd on the directory
#     itself, which is what a readdir in progress looks like from outside.
#
# Exit 0: healthy.
# Exit 1: tusd starved AND a scanner is present. Resolvable: --resolve kills it.
# Exit 2: tusd starved with nothing scanning. Not this pathology (the NFS
#         server itself?), so --resolve does nothing and monit alerts instead.
#
# The kill is safe by construction: the scanner sits in a killable NFS wait,
# nothing legitimate iterates the upload store on this host, and the lock
# releases the moment it dies (1,036 -> 0 D threads within 5 s on 2026-09-18).
UPLOAD_DIR=${TUSD_UPLOAD_DIR:-/srv/tusd-data}
D_THRESHOLD=${TUSD_D_THRESHOLD:-50}
MODE=${1:-check}

tusd_pids=$(pgrep -x tusd)
# No tusd means the container is down or restarting; other checks cover that.
[ -n "$tusd_pids" ] || exit 0

d_count=0
for p in $tusd_pids; do
    # Field 3 of /proc/<tid>/stat is the state, once the "(comm)" is stripped.
    n=$(cat /proc/"$p"/task/*/stat 2>/dev/null | sed 's/^.*) //' \
        | awk '{ if ($1 == "D") n++ } END { print n + 0 }')
    d_count=$((d_count + n))
done

[ "$d_count" -ge "$D_THRESHOLD" ] || exit 0

# Every process with an fd open on the directory itself, minus tusd and us.
scanners=$(find /proc/[0-9]*/fd -maxdepth 1 -lname "$UPLOAD_DIR" -printf '%h\n' 2>/dev/null \
    | awk -F/ '{ print $3 }' | sort -u)
for p in $tusd_pids $$; do
    scanners=$(echo "$scanners" | grep -vx "$p")
done

if [ -z "$scanners" ]; then
    echo "tusd starved: $d_count threads in D state and nothing is scanning $UPLOAD_DIR - check the NFS server"
    exit 2
fi

detail=$(for p in $scanners; do
    printf '%s: ' "$p"; tr '\0' ' ' < /proc/"$p"/cmdline 2>/dev/null | cut -c1-120; echo
done)

if [ "$MODE" = "--resolve" ]; then
    # shellcheck disable=SC2086  # one pid per word is the point
    kill -9 $scanners 2>/dev/null
    logger -t tusd-nfs-starvation "killed scanner(s) of $UPLOAD_DIR with $d_count tusd threads blocked: $(echo "$detail" | tr '\n' ';')"
    echo "tusd starved: $d_count threads in D state; killed scanner(s) of $UPLOAD_DIR:"
    echo "$detail"
    exit 0
fi

echo "tusd starved: $d_count threads in D state; scanning $UPLOAD_DIR:"
echo "$detail"
exit 1
