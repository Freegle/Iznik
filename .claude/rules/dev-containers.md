---
paths:
  - "docker-compose*.yml"
  - "scripts/**"
  - "freegle"
  - ".env*"
---

# Traps in the dev containers and worktrees

Every one of these produces **a local test result about code that is not the code you are
looking at**. When a local pass and CI disagree, suspect this page before suspecting CI.

## file-sync has no catch-up

Edits made while the stack is stopped never reach the dev containers when it starts again. The
local suite then passes against the old code and CI fails on the new. After restarting a stopped
stack, touch the files you changed, or restart the container.

It also only watches directories that existed when it started, so **a brand new source directory
never syncs at all**. Adding a Go package and seeing no behaviour change is this.

Switching branches in the main checkout syncs the new branch's files in and **never deletes the
old ones**, so removed files linger as orphans in the container and can fail as phantom tests.

## file-sync defeats a red proof

It copies edits into the containers within seconds, so a fix written before a deliberately
failing run can land *in* that run. If you need to prove a test is red without the fix, compare
the container's copy of the file against yours before trusting the red.

## A restarted container comes back on its baked image

After an out-of-memory kill, a restarted worktree Go or API container runs its **baked image**,
which is the commit it was built at, not your checkout. The main dev containers are also baked
from whatever branch they were built on, so validating a master-based branch in them produces
unrelated failures.

Rebuild rather than restart when the code should have changed. A stale spatial image is the same
class: it answers one endpoint with a 404, every reach write silently produces no row, and a
large number of tests fail for what looks like an unrelated reason.

## Compose variables leak out of the shell

The interactive shell profile exports `COMPOSE_PROJECT_NAME`, `COMPOSE_FILE` and friends
globally. Real environment variables beat the `.env` file, so changing directory into a secondary
worktree and running compose there can still act on the **main** project. Worktree isolation
depends on those variables not being set in your shell.

Related: worktree dev-live containers can reach the main checkout's API rather than their own
when a port is missing from the environment, so you test the wrong stack without any error.

## Rebasing a worktree after creating it

Creating a worktree and then resetting hard onto a different base leaves the containers with a
stale file tree **and** stale dependencies. The symptom is a missing dependency or a schema error
that makes no sense for the branch. Rebuild the containers after any reset that moves the base.

## `git checkout --ours` replaces the whole file

During a merge or rebase conflict, `git checkout --ours <file>` or `--theirs <file>` replaces the
**entire file** with that side's pre-merge version, silently discarding every other hunk you had
already resolved in it. Resolve hunks individually.

## See also

- `CLAUDE.md` - the container quick reference and the worktree CLI.
- `docs/developers/local-development.md` - getting a working environment.
- `.claude/rules/tests-and-ci.md` - green results that mean nothing.
