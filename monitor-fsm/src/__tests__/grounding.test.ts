import { describe, it, expect } from 'vitest'
import { validateReadOnlySql, ensureLimit, lokiCandidates, parseEnvFile } from '../grounding.js'

describe('validateReadOnlySql', () => {
  it('accepts a plain SELECT', () => {
    expect(validateReadOnlySql('SELECT id FROM messages WHERE id = 1')).toBeNull()
  })

  it('accepts SHOW statements', () => {
    expect(validateReadOnlySql('SHOW CREATE TABLE messages')).toBeNull()
  })

  it('accepts a trailing semicolon', () => {
    expect(validateReadOnlySql('SELECT 1;')).toBeNull()
  })

  it('rejects writes', () => {
    for (const sql of [
      'UPDATE users SET systemrole = "Admin"',
      'DELETE FROM messages',
      'INSERT INTO logs VALUES (1)',
      'DROP TABLE messages',
      'CREATE TABLE x (id int)',
    ]) {
      expect(validateReadOnlySql(sql)).not.toBeNull()
    }
  })

  it('rejects stacked statements', () => {
    expect(validateReadOnlySql('SELECT 1; DELETE FROM messages')).not.toBeNull()
  })

  it('rejects INTO OUTFILE', () => {
    expect(validateReadOnlySql("SELECT * FROM users INTO OUTFILE '/tmp/x'")).not.toBeNull()
  })

  it('rejects empty input', () => {
    expect(validateReadOnlySql('   ')).not.toBeNull()
  })
})

describe('ensureLimit', () => {
  it('appends LIMIT to an unbounded SELECT', () => {
    expect(ensureLimit('SELECT id FROM messages')).toBe('SELECT id FROM messages LIMIT 200')
  })

  it('keeps an existing LIMIT', () => {
    expect(ensureLimit('SELECT id FROM messages LIMIT 5')).toBe('SELECT id FROM messages LIMIT 5')
  })

  it('leaves SHOW statements alone', () => {
    expect(ensureLimit('SHOW TABLES')).toBe('SHOW TABLES')
  })

  it('strips a trailing semicolon before appending', () => {
    expect(ensureLimit('SELECT 1;')).toBe('SELECT 1 LIMIT 200')
  })
})

describe('lokiCandidates', () => {
  it('prefers LOKI_PROD_URL, then the gateway tunnel, then local dev', () => {
    const c = lokiCandidates({ LOKI_PROD_URL: 'http://tunnel:9999/' }, '172.20.0.1')
    expect(c[0]).toEqual({ url: 'http://tunnel:9999', source: 'prod' })
    expect(c[1]).toEqual({ url: 'http://172.20.0.1:3102', source: 'prod' })
    expect(c[2]).toEqual({ url: 'http://localhost:3100', source: 'local-dev' })
  })

  it('labels the local fallback as local-dev so it is never mistaken for prod evidence', () => {
    const c = lokiCandidates({}, null)
    expect(c).toEqual([{ url: 'http://localhost:3100', source: 'local-dev' }])
  })
})

describe('parseEnvFile', () => {
  it('parses simple KEY=value lines and skips comments', () => {
    const env = parseEnvFile('# comment\nA=1\nB=two=three\n\nnotakv\n')
    expect(env).toEqual({ A: '1', B: 'two=three' })
  })
})
