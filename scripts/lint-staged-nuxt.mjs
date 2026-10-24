#!/usr/bin/env node
// Lint the iznik-nuxt3 files a commit is about to record, the way CI's lint-nuxt job does:
// eslint, errors only (warnings do not fail the build there, so they do not block here).
//
// It lints what is staged, read from the index, not the working copy. So a file half-edited
// after `git add` is judged as committed, and a clone whose checkout has CRLF line endings
// (core.autocrlf) is judged on the LF text git will store.
//
// Usage: node scripts/lint-staged-nuxt.mjs [--repo <dir>] [--all]
//   --repo  the repository to look in (default: the current one)
//   --all   also tracked files changed but not staged, for `git commit -a`
//
// eslint comes from the repo's own iznik-nuxt3/node_modules, else from the checkout that
// owns the git directory (a worktree's main checkout), else from $CLAUDE_PROJECT_DIR. With
// none of those it says so and passes: CI still lints.
// Exit 0 = clean (or nothing to lint), 1 = errors.
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import fs from 'node:fs'
import path from 'node:path'

const args = process.argv.slice(2)
const all = args.includes('--all')
const repoArg = args.includes('--repo') ? args[args.indexOf('--repo') + 1] : null
const git = (...a) =>
  execFileSync('git', ['-C', repo, ...a], { encoding: 'utf8', maxBuffer: 64 << 20 })

const repo = repoArg
  ? path.resolve(repoArg)
  : execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim()

const LINTED = /^iznik-nuxt3\/(?!.*(?:^|\/)(?:node_modules|\.nuxt|\.output)\/).+\.(?:js|mjs|cjs|ts|vue)$/
const list = (out) => out.split('\n').filter((f) => LINTED.test(f))
const staged = list(git('diff', '--cached', '--name-only', '--diff-filter=ACMR'))
const unstaged = all ? list(git('diff', '--name-only', '--diff-filter=ACMR')) : []
const files = [...new Set([...staged, ...unstaged])]
if (!files.length) process.exit(0)

function eslintHome() {
  const common = path.resolve(repo, git('rev-parse', '--git-common-dir').trim())
  const roots = [repo, path.dirname(common), process.env.CLAUDE_PROJECT_DIR].filter(Boolean)
  for (const root of roots) {
    const dir = path.join(root, 'iznik-nuxt3')
    if (fs.existsSync(path.join(dir, 'node_modules', 'eslint', 'package.json'))) return dir
  }
  return null
}

const home = eslintHome()
if (!home) {
  console.error(
    'lint-staged-nuxt: no iznik-nuxt3/node_modules with eslint found, so the staged files were not linted. CI will lint them.'
  )
  process.exit(0)
}

// The nuxt eslint config resolves its plugins from the process's own directory, so being
// told cwd is not enough: without the chdir it fails to find @typescript-eslint.
process.chdir(home)
const { ESLint } = createRequire(path.join(home, 'package.json'))('eslint')
const eslint = new ESLint({ cwd: home })
const failures = []
for (const file of files) {
  const rel = file.slice('iznik-nuxt3/'.length)
  const target = path.join(home, rel)
  if (await eslint.isPathIgnored(target)) continue
  const text = unstaged.includes(file)
    ? fs.readFileSync(path.join(repo, file), 'utf8')
    : git('show', `:${file}`)
  const [result] = await eslint.lintText(text.replace(/\r\n/g, '\n'), { filePath: target })
  for (const m of result.messages.filter((m) => m.severity === 2)) {
    failures.push({ file, m })
  }
}

if (!failures.length) process.exit(0)
for (const { file, m } of failures) {
  console.error(`${file}:${m.line}:${m.column}  ${m.message}  (${m.ruleId ?? 'parse'})`)
}
const bad = [...new Set(failures.map((f) => f.file.slice('iznik-nuxt3/'.length)))]
console.error(
  `\n${failures.length} eslint error(s) in ${bad.length} file(s); CI's lint-nuxt would fail. Fix with:\n  cd iznik-nuxt3 && npx eslint --fix ${bad.join(' ')}`
)
process.exit(1)
