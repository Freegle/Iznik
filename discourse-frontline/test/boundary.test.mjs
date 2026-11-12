// The promise this service makes is about what it cannot reach. These tests fail if
// somebody wires in a way to reach it.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { loadConfig } from '../src/config.mjs'

const root = new URL('..', import.meta.url).pathname
const walk = (dir) => readdirSync(dir).flatMap((n) => {
  const p = join(dir, n)
  return statSync(p).isDirectory() ? walk(p) : p.endsWith('.mjs') ? [p] : []
})
const sources = [...walk(join(root, 'src')), ...walk(join(root, 'bin'))].map((p) => [p, readFileSync(p, 'utf8')])

test('the service has no setting for production data', () => {
  const env = { DB_HOST: 'db-host-value', MYSQL_PASSWORD: 'db-pass-value', LOKI_URL: 'http://logs.invalid', SENTRY_AUTH_TOKEN: 'errors-token-value', GITHUB_TOKEN: 'code-token-value' }
  const cfg = JSON.stringify(loadConfig(env))
  for (const v of Object.values(env)) assert.ok(!cfg.includes(v), `config picked up ${v}`)
})

test('no source file reaches for the database, the logs, the error tracker or the member API', () => {
  const banned = /mysql|mariadb|percona|\bloki\b|sentry|apiv[12]|modtools\.org\/api|\bDB_(?:HOST|PASS)|GITHUB_TOKEN/i
  for (const [p, src] of sources) {
    const code = src.split('\n').filter((l) => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n')
    assert.ok(!banned.test(code), `${p} mentions a production source`)
  }
})

test('the model is given no tools and the service runs no commands on its say-so', () => {
  const llm = sources.find(([p]) => p.endsWith('anthropic.mjs'))[1]
  assert.ok(!/\btools\s*:|tool_choice|mcp_servers/.test(llm))
  const cli = sources.find(([p]) => p.endsWith('claude-cli.mjs'))[1]
  assert.match(cli, /'--tools', '', '--strict-mcp-config', '--disable-slash-commands'/)
  assert.ok(!/shell:\s*true|exec\(/.test(cli), 'the model command must not go through a shell')
  for (const [p, src] of sources) {
    // Two files start a program, each with fixed arguments: the index reads `git log`,
    // and the command-line model adapter starts `claude` with its tools switched off.
    if (p.endsWith(join('kb', 'index.mjs')) || p.endsWith('claude-cli.mjs')) continue
    assert.ok(!/child_process|\beval\([^)]|new Function\(/.test(src), `${p} can run code`)
  }
})

test('only the outbound door and the by-hand send call the forum\'s reply', () => {
  const callers = sources.filter(([, src]) => /\.reply\(/.test(src)).map(([p]) => p.slice(root.length)).sort()
  assert.deepEqual(callers, ['bin/frontline.mjs', 'src/outbound.mjs'])
})

test('the default mode posts nothing', () => {
  assert.equal(loadConfig({}).mode, 'shadow')
  assert.throws(() => loadConfig({ FRONTLINE_MODE: 'everything' }))
})
