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

## Which container actually runs your tests

The runner is not always the container you expect, and the wrong one bakes its own copy of the
source and dependencies at image build time. Symptoms of running against a baked copy:

- Dozens of phantom failures in whole files, from stale packages rather than your change.
- A new frontend dependency needing a stub before the runner can resolve it at all.
- Files that file sync routes to one set of containers and not another, so a shared module is
  current in the app and stale in the test runner.

Confirm which container is running before concluding anything from a local result.

## Stale networks and wedged state

- Removed worktrees leave their networks behind and eventually **exhaust Docker's address
  pools**, after which nothing new will start.
- A swept worktree's network is gone, so starting it again fails until it is recreated.
- The main checkout and a worktree do not sync symmetrically, and sessions sharing the main
  checkout pollute each other.
- Production containers are built at start rather than by a separate build, so "rebuild" means
  something different for them.

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

## Work in a worktree, and commit early

The shared main checkout has been hard reset at least once with about forty files of
uncommitted work in it, and none of it was recoverable: the containers are not a backup, because
file sync propagates the reset into them too. A host crash can also lose newly created files and
recent edits that were never flushed to disk.

Both have the same remedy. Do the work in a worktree, and commit sooner than feels necessary. An
untidy commit you amend later costs nothing; an afternoon of uncommitted work costs the
afternoon.

Worktree host scripts have also synced into the **main** containers rather than the worktree's
own, so a worktree run can silently be exercising the main checkout.

## A fresh worktree is not ready to test

Several things are missing or stale the moment it exists, and each fails in a way that points
somewhere else:

- **Its test database is empty.** Run the setup script, or fixture ids come out wrong and
  poison everything downstream.
- **The map data file is absent**, so the spatial containers exit immediately and restart, and
  the Go suite looks wedged rather than failing.
- **Images are baked at create time.** Merging master in does not rebuild them: the spatial
  image goes stale and starts refusing reach requests, the Go API keeps serving the old commit,
  an old batch image lacks a required PHP extension, and the status container serves whatever
  it was built from.
- **A long-lived worktree runs the scheduler**, whose hourly auto-approve wipes the pending
  fixtures your tests depend on.

After merging master into a worktree, rebuild. Do not restart.

## Its test API can escape to the main instance

The worktree's status API runners have escaped to the **main** containers because of a
hard-coded name, and its live-API client has reached the main API because a port was missing.
A worktree result is only about the worktree if you have checked which containers answered.

Test runs read the worktree's files **as they execute**, because the batch container bind-mounts
the tree. So do not edit files or merge during a run, and a run that must be red needs the
implementation genuinely absent rather than present-but-broken. `docker cp` into that container
writes into your working tree and silently reverts your edits.

Note also that a Laravel test cannot read the Go tree and a Go test cannot read the PHP tree, so
a cross-language assertion has to go through a fixture or the API.

## `setup-test-database.sh` reads the env var, not the worktree `.env`

Its container prefix is `${COMPOSE_PROJECT_NAME:-freegle}`. Compose reads that from the
worktree's `.env`; a plain shell script does not. So run bare from inside a worktree it targets
`freegle-percona` and `freegle-batch` - the **main** instance - and says so in one line of
output that is easy to read past:

```
Verifying required containers...
freegle-percona is running
```

It then migrates the main database, reloads its fixtures, rolls the fixture post dates forward
and re-clones `iznik_go_test`, all against the instance another session is probably using. The
`DROP DATABASE` is gated behind `SELF_HOSTED_RUNNER=true`, so that much is spared. Per
`.claude/rules/tests-and-ci.md` it also disrupts the spatial index, so the main instance needs a
re-index afterwards before its failures mean anything.

Always:

```bash
COMPOSE_PROJECT_NAME=freegle-<name> ./scripts/setup-test-database.sh
```

Check the "is running" lines name your own prefix before letting it continue.

A worktree's own `iznik` can also arrive half-migrated - schema carrying foreign keys that the
`migrations` table does not record - which surfaces as `Duplicate foreign key constraint name`
on a migration that has plainly already run. Drop `iznik` and `iznik_go_test` in **that
worktree's** percona and run the script again.

## Local full suites can starve the CircleCI runner into an infrastructure failure

The self-hosted runner lives in its own WSL2 distro but on the **same physical machine** as your
worktrees. Run two full suites locally while a pipeline is building and the job can die as
`infrastructure_fail`, with its steps `canceled` rather than failed - so nothing in the CI output
names a test, and the branch looks broken when it is not.

Seen 2026-09-18: 90 containers up, 3GB of 94GB free, a full Go suite and a full Laravel suite
running against a worktree. Pipeline #12011 died that way; the identical commit passed as #12015
once the worktree's stack was stopped and 19GB came back.

Before blaming the branch, check `free -g` and `docker ps -q | wc -l`, and stop the stacks you
are not using:

```bash
cd /path/to/worktree
COMPOSE_PROJECT_NAME=freegle-<name> docker-compose stop
```

`stop` rather than `down` - the containers come straight back with no rebuild.

## On the FreegleDocker host, `git checkout` is a deploy

`batch-prod` bind-mounts `iznik-batch/` and runs against the **production** database. The tree is
therefore what production executes, which makes ordinary git operations production actions:

- **Committing to a branch and then switching away silently reverts the change out of production.**
  Work committed on a branch is live from the moment it is written, and stops being live the moment
  you `git checkout master`. Neither step prints anything about production. (Hit 2026-09-17: a
  monitoring check was reported to the operator as live, then taken out of production by the
  checkout that followed, with nothing to say so.)
- **An uncommitted edit is live.** Staging a change in the working tree to try it out has already
  shipped it.
- PHP loads code at process start, so none of this reaches a long-running command until it next
  starts. A multi-hour job keeps the code it began with.

Check what production is running by grepping the file, not by recalling what you last did. The
answer changes under you.

## Branches, clones and the tools around them

- **Creating a worktree branches off your local master**, which may be behind or ahead of the
  remote, so the base can include unpushed local commits.
- **Removing a worktree keeps the branch**, so the branch accumulates even when the directory
  does not.
- **Agents sharing one clone switch branches underneath each other**, and a push can end up a
  silent no-op.
- **The worktree guard is session-wide**, and a subagent can move it, after which commands are
  refused or land in the wrong place.
- **`pkill -f` matches its own command line** and kills the shell that ran it.

## `git checkout --ours` replaces the whole file

During a merge or rebase conflict, `git checkout --ours <file>` or `--theirs <file>` replaces the
**entire file** with that side's pre-merge version, silently discarding every other hunk you had
already resolved in it. Resolve hunks individually.

## `setup-test-database.sh` races the batch container's own migrations

The batch container's entrypoint (`iznik-batch/docker/entrypoint.sh`) runs `artisan migrate`
itself, with a retry loop, whenever it starts. `scripts/setup-test-database.sh` also runs
`artisan migrate`. Start the script while the container is still coming up and both run
against `iznik` at once.

What you see is not a race, it is a **migration chain that looks broken**: `Base table or
view already exists: aviva_votes`, or `Duplicate column name 'heldby'`, on a database you
just dropped. The obvious reading is that some migration is missing an idempotency guard,
and you can lose a long time adding guards to migrations that were fine.

The tell is that the failing migration is an arbitrary one, and a different one each run.
Laravel writes the `migrations` row only after `up()` returns, so whichever process loses
the race leaves the schema change applied with no row recorded, and the next attempt tries
it again.

Wait for `docker ps` to show the batch container settled before running the script, and run
it once. If the database is already half-migrated, drop `iznik` first, or the recorded rows
and the real schema stay out of step.

## See also

- `CLAUDE.md` - the container quick reference and the worktree CLI.
- `docs/developers/local-development.md` - getting a working environment.
- `.claude/rules/tests-and-ci.md` - green results that mean nothing.
