'use strict'

// Keeping the agent's copy of the Freegle monorepo up to date.
//
// The agent answers support questions from the CODE, so it needs a checkout to search. That
// checkout is fetched at RUNTIME, not baked into the image: a `RUN git clone` in the Dockerfile
// makes `docker compose build` depend on reaching github.com anonymously, and when that failed
// the whole CI stack bringup failed with it (2026-09-02, jobs 34594/34597: "could not read
// Username for 'https://github.com'"). It also froze the agent's view of the code at whenever
// the image happened to be built.
//
// Runtime is the honest place for it. A clone that fails leaves the agent without code search
// but with a container that starts, serves /health and says so, and the next sync half an hour
// later picks it up.

const CODEBASE_REPO = process.env.CODEBASE_REPO || 'https://github.com/Freegle/Iznik.git'

/**
 * Bring the checkout at `dir` up to date: clone it when it is not there yet, fast-forward it
 * when it is.
 *
 * Shallow, because nothing here reads history - the agent greps the working tree. A shallow
 * clone needs `--depth 1` on the pull too, or git fetches the whole history it was cloned
 * without.
 *
 * @param {object} opts
 * @param {string} opts.dir - the checkout path.
 * @param {string} [opts.repo] - clone URL.
 * @param {function} opts.run - runs a shell command; (cmd, opts) => void, throws on failure.
 * @param {function} opts.exists - path predicate; (path) => boolean.
 * @returns {{present:boolean, action:string, error:(string|null)}} what happened, for /health.
 */
function syncCodebase({ dir, repo = CODEBASE_REPO, run, exists }) {
  const cloned = exists(dir)

  try {
    if (cloned) {
      run(`git pull --ff-only --depth 1 2>&1`, { cwd: dir, timeout: 60000 })

      return { present: true, action: 'pulled', error: null }
    }

    run(`git clone --depth 1 ${repo} ${dir} 2>&1`, { timeout: 300000 })

    return { present: true, action: 'cloned', error: null }
  } catch (e) {
    // A failed pull leaves the previous checkout in place, which is still worth searching; a
    // failed clone leaves nothing. Say which, so /health does not report "no codebase" for a
    // container that has a perfectly good, slightly stale one.
    return { present: cloned, action: cloned ? 'pull-failed' : 'clone-failed', error: e.message }
  }
}

module.exports = { syncCodebase, CODEBASE_REPO }
