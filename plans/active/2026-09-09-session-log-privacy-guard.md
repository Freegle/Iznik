# Session Log Privacy Guard

Implementation brief, 9 September 2026. Stop member data and ops detail landing in
`.claude-session.md`, scrub what is already there, and fix the reminder hook that stopped
reading it.

| | |
|---|---|
| Repo | `/home/edward/FreegleDockerWSL`, hooks in `.claude/` |
| Work in | A worktree or a temp clone of origin/master. Never commit from the main checkout. |
| Deliver as | One PR to master. Humans merge. |
| Tooling | bash, jq, grep, like the hooks beside it. No Python. |

## Why this exists

`.claude-session.md` is the gitignored working log (`.gitignore` line 76). Every session
reads it on start and Ralph writes to it as work progresses (`.claude/skills/ralph/SKILL.md`,
section 1). It runs to about 2,600 lines. An audit on 9 September 2026 read all of it. No
keys, tokens or passwords, but thirteen lines carried things that must not leave the machine:

- **Freegle member data.** A member's full name with a drive time to a post. A member id with
  their home postcode. Internal user ids for two moderators and three members. A TN member id
  with the ids of their private chat and chat message. A test account on a personal domain.
- **Ops detail** that CLAUDE.md already forbids in docs ("Ops docs contain no secrets,
  credentials or IPs"). The production host IP with its SSH port. A Google Cloud project
  number, twice.

The log is shared between Claude sessions, gets quoted into Discourse and PR text, and has
been published as an artifact. Nothing checks what goes into it. The same gap covers `plans/`
and `docs/`, which are committed to a public repo, and the per-project memory directory.

**Do not paste the values themselves anywhere new, including this PR's body, tests or commit
messages. Describe them by category, as this brief does. The test corpus uses invented values.**

A redacted copy of the log exists from the audit session at
`/tmp/claude-1000/-home-edward-FreegleDockerWSL/780bb486-5224-44ee-817b-f7cc604d1ceb/scratchpad/claude-session-redacted.md`.
It may not survive a reboot. The live file is still unredacted.

## What to build, in this order

### 1. Write the scanner, then scrub the live log

Build the hook's `--scan` mode first (task 2). Back up `.claude-session.md` outside the repo,
for example `~/.claude/backups/`. It is gitignored, so there is no history to fall back on.
Run the scan on it. Expect eleven of the thirteen lines: the other two carry ids with no label
or an ambiguous one and are the conventions' job. They are the OLLY exhibit in the 25 August
TN Msgids entry and the two member ids beside unseen counts in the 22 August "stuck on 99"
entry. Replace every value with the placeholder from the table, by hand or with sed on the
specific values, then re-run the scan and get zero hits.

### 2. The hook: `.claude/check-session-log-privacy.sh`

Three modes, one script.

- **PreToolUse on Write|Edit.** The shape `.claude/check-raw-sql.sh` already uses. Read
  `tool_input.file_path`. If the basename is `.claude-session.md`, or the path is under
  `plans/`, `docs/`, or a `.claude/projects/*/memory/` directory, scan `tool_input.content`
  (Write) or `tool_input.new_string` (Edit). Scan only the new text, so an edit near an old
  hit is not blocked for someone else's line.
- **PreToolUse on Bash.** Fire only when the command writes to one of those paths: `>`, `>>`,
  `tee`, `sed -i`, `perl -i`. Scan the command text. Hooks receive the command unexpanded, so
  a heredoc body is visible and `$(cat file)` is not. That is acceptable: the Write|Edit arm
  covers tool edits. A read-only grep over the file must not fire, even when its pattern is a
  full IP.
- **`--scan <file>`.** Print every hit as `line:pattern:match`, exit 2 if there are any. Used
  by task 1, the tests and task 6.

On a hit, exit 2 with a message naming the line, the pattern and the placeholder to use. The
escape hatch lives in the content, not the environment, because an Edit call has no env: a
`[pii-ok: reason]` marker on the same line lets that line through. Fail closed: if the JSON
cannot be parsed and the path matches, block and say so. Precedent is the fail-open bug fixed
in `check-pr-text.sh` in August.

### 3. Wire it in `.claude/settings.json`

Two PreToolUse entries, matchers `Write|Edit` and `Bash`, timeout 5, in the style of the
existing entries. Keep a live copy of the script and the wiring working in the main checkout
while the PR is open. The main checkout's settings.json has been wiped by a reset before; that
is a hazard to watch, not a reason to skip.

### 4. Write the conventions down

A short "Session log hygiene" paragraph in `.claude/skills/ralph/SKILL.md` section 1, and the
same in the Session Log paragraph of CLAUDE.md. Members by role or by post id (posts are
public), never by name, user id, chat id, postcode or email. Hosts by role, never IP or port.
Cloud projects by name, never number. Test accounts by role. Placeholders use square brackets,
`[id]` not `<id>`, because Markdown renderers swallow angle brackets. Full names of people who
post publicly on Discourse are fine when the entry is about their public post.

### 5. Fix `.claude/remind-session-log.sh`

It runs on every Skill call and reads CLAUDE.md for `### 2026-` headings. Those moved to
`.claude-session.md` months ago, so it prints nothing. Point it at the session log and match
the heading forms actually in use there: `# Session log - 2026-...`, `## 2026-...` and
`### ... 2026-...`. Print the first dated entry and its next ten lines.

### 6. Report on the memory directory

Run `--scan` over `/home/edward/.claude/projects/-home-edward-FreegleDockerWSL/memory/*.md`
and put the hit count by category in the PR body. Do not edit those files. Some of the values
are working knowledge, one is a load-bearing registry host, and whether to keep them is the
user's call.

### 7. Tests, then the PR

`.claude/check-session-log-privacy.test.sh` in the style of `check-pr-uncommitted.test.sh`:
self-contained, builds its JSON with jq, prints PASS or FAIL per case, exits non-zero on any
failure. It has to be a file, not typed at a shell, because the cases contain the literals the
hook fires on. Write the failing cases before the hook logic. Cases:

- Each pattern in the table fires on an Edit `new_string` to `.claude-session.md`, and on a
  Write `content`.
- The same text to an unrelated path, for example a PHP file with a fixture email, does not
  fire.
- Allowed forms do not fire: private and loopback IPs, `@test.com` and `noreply@` addresses,
  `group 522709`, `msg 121573028`, a 19-digit Loki timestamp, a commit hash.
- `[pii-ok: fixture]` on the line lets it through.
- A Bash heredoc appending to the file fires. A grep for a full IP over the file does not.
- Unparseable JSON with a matching path blocks.
- `--scan` on a fixture file lists every hit and exits 2, and exits 0 on a clean file.

PR body in plain English (the check-pr-text hook grades it), end state only, no em-dash,
humans merge. Include the memory scan count from task 6 and the test run output.

## Patterns

| Pattern | Catches | Leave alone | Placeholder |
|---|---|---|---|
| `\b[0-9]{1,3}(\.[0-9]{1,3}){3}\b`, then drop 10/8, 172.16 to 172.31, 192.168, 127 and 0.0.0.0 with a second `grep -v` | Production hosts, SSH tunnels | Docker bridge and WSL addresses | `[prod-host]`, `[batch-host]`, `[ssh-port]` |
| `\b[A-Z]{1,2}[0-9][0-9A-Z]? ?[0-9][A-Z]{2}\b` | Member home postcodes | Nothing in a log needs one | `[postcode]` |
| `[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}` | Member emails, test accounts on personal domains | `@test.com`, `@example.*`, `noreply@`, the bare `@user.trashnothing.com` domain pattern | `[member-email]`, `[test-account]` |
| `\b(user|member|userid|chat|chatid|chat_messages|chat_rooms)[ :=#]*[0-9]{5,9}\b`, case-insensitive | Member and chat identifiers | `group N`, `msg N`, `message N`, `post N`: groups and posts are public | `[id]` |
| `\b[0-9]{12}\b` | Cloud project numbers | Loki timestamps are 19 digits, phone numbers 11 | `[gcp-project]` |
| `-----BEGIN`, `sk-ant-`, `ghp_`, `xox[abp]-`, `AKIA[0-9A-Z]{16}`, `eyJ[A-Za-z0-9_-]{20,}\.` | Private keys, API tokens, JWTs. The log already mentions minted JWTs by file path only; keep it that way. | Nothing | None. Remove it. |

Full personal names and bare 8-digit member ids cannot be caught by a pattern without firing
on Sentry issue ids, pipelines and hashes. Do not add a bare-number rule. The conventions and
the reviewer cover those.

## Traps you will hit

- Bash ERE has no lookahead. Match every IPv4, then filter the private ranges with a second
  grep.
- Hooks see the command unexpanded, and this repo's hooks have a history of failing open on
  that. Memory: `finding_hooks_see_unexpanded_command_and_fail_open`.
- Hook scripts must be committed executable, mode 100755. `run-loop.sh` shipped as 100644
  once and was dead for three days.
- `check-test-command.sh` blocks any Bash command containing "go test", "go build", "go vet"
  or "vitest" as a substring, including inside quotes. Keep those words out of your test file
  and out of the commands you run.
- Never commit `.claude-session.md`. It is gitignored because log edits make open PRs go
  BEHIND.
- The main checkout is shared and has been hard-reset mid-session before. Develop in a
  worktree or temp clone. Memory: `reference_worktree_dev_recipe`,
  `feedback_isolate_pr_in_tmp_checkout`.
- Run `node scripts/check-docs-freshness.mjs` before pushing. If a developer page lists the
  hooks, add this one there.

## Done when

- [ ] `bash .claude/check-session-log-privacy.test.sh` passes every case.
- [ ] `--scan .claude-session.md` exits 0 on the live file, and the backup exists outside the
      repo.
- [ ] An Edit that adds a postcode to `.claude-session.md` is blocked with a message naming
      the pattern and placeholder. The same edit with `[pii-ok: fixture]` passes. A grep over
      the file passes.
- [ ] `remind-session-log.sh` prints the latest dated entry when invoked.
- [ ] Wiring is in the PR's settings.json and live in the main checkout.
- [ ] Docs freshness is green. PR is open against master with the memory scan count in its
      body. Not merged.

## Out of scope

- Editing memory files (report only, task 6).
- Scanning the committed history of `docs/` and `plans/` for past hits. Worth a follow-up;
  say so in the PR.
- Names of people who post publicly on Discourse.

## Read first

- `.claude/check-raw-sql.sh` for the Write|Edit hook shape. `.claude/check-pr-uncommitted.sh`
  and its `.test.sh` for the Bash hook and test style. `.claude/check-pr-text.sh` for the
  fail-closed precedent.
- Memory files in `/home/edward/.claude/projects/-home-edward-FreegleDockerWSL/memory/`:
  `finding_hooks_see_unexpanded_command_and_fail_open`, `feedback_isolate_pr_in_tmp_checkout`,
  `feedback_pr_plain_english_hook`, `reference_worktree_dev_recipe`,
  `feedback_no_python_use_php_or_js`.

Same brief as an artifact (private unless shared from its page):
https://claude.ai/code/artifact/0d6c7706-03a8-4ddc-b4a0-7db768bc7942
