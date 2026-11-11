// Tests for scripts/check-dependency-licences.mjs. Run with: node --test scripts/check-dependency-licences.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { licenceAllowed, exceptionFor, packagesIn, licenceFromText } from './check-dependency-licences.mjs'

test('permissive and GPL-3-compatible licences are allowed', () => {
  for (const l of ['MIT', 'ISC', 'Apache-2.0', 'BSD-3-Clause', 'MPL-2.0', 'LGPL-3.0', 'GPL-3.0', 'GPL-2.0-or-later', 'AGPL-3.0-or-later']) {
    assert.equal(licenceAllowed(l), true, l)
  }
})

test('licences the AGPL cannot live with are refused', () => {
  for (const l of ['GPL-2.0', 'GPL-2.0-only', 'ELv2', 'Elastic-2.0', 'BUSL-1.1', 'SSPL-1.0', 'FSL-1.1-MIT', 'EPL-1.0', 'CC-BY-NC-4.0', 'UNLICENSED', 'proprietary']) {
    assert.equal(licenceAllowed(l), false, l)
  }
})

test('a missing or unreadable licence is refused, so a person has to look', () => {
  assert.equal(licenceAllowed(''), false)
  assert.equal(licenceAllowed(null), false)
  assert.equal(licenceAllowed('SEE LICENSE IN LICENSE.txt'), false)
})

test('OR takes any branch, AND needs every term', () => {
  assert.equal(licenceAllowed('(EDL-1.0 OR EPL-1.0)'), true)
  assert.equal(licenceAllowed('BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only'), true)
  assert.equal(licenceAllowed('(MIT AND Zlib)'), true)
  assert.equal(licenceAllowed('(MIT AND ELv2)'), false)
  assert.equal(licenceAllowed('MIT OR SEE LICENSE IN FEEL-FREE.md'), true)
})

test('named exceptions and their prefixes', () => {
  assert.ok(exceptionFor('npm:add-to-calendar-button'))
  assert.ok(exceptionFor('npm:@sentry/cli-linux-x64'))
  assert.equal(exceptionFor('npm:left-pad'), null)
})

test('npm: development packages are skipped, the rest are read with their licence', () => {
  const lock = JSON.stringify({
    packages: {
      '': { name: 'app' },
      'node_modules/a': { version: '1.0.0', license: 'MIT' },
      'node_modules/b': { version: '2.0.0', license: 'ELv2', dev: true },
      'node_modules/c': { version: '3.0.0', license: 'ISC', devOptional: true },
      'node_modules/x/node_modules/d': { version: '4.0.0', license: { type: 'BSD-2-Clause' } },
    },
  })
  const found = packagesIn('web/package-lock.json', lock)
  assert.deepEqual([...found.keys()], ['npm:a@1.0.0', 'npm:d@4.0.0'])
  assert.equal(found.get('npm:d@4.0.0').licence, 'BSD-2-Clause')
})

test('composer: only packages, not packages-dev, and several licences are a choice', () => {
  const lock = JSON.stringify({
    packages: [{ name: 'nette/utils', version: 'v4', license: ['BSD-3-Clause', 'GPL-2.0-only'] }],
    'packages-dev': [{ name: 'phpunit/phpunit', version: '11', license: ['BSD-3-Clause'] }],
  })
  const found = packagesIn('batch/composer.lock', lock)
  assert.deepEqual([...found.keys()], ['composer:nette/utils@v4'])
  assert.equal(licenceAllowed(found.get('composer:nette/utils@v4').licence), true)
})

test('go.mod: both require forms, local replaces skipped, module replaces followed', () => {
  const mod = [
    'module example.com/app',
    'go 1.23',
    'require example.com/single v1.0.0',
    'require (',
    '\texample.com/a v1.2.3',
    '\texample.com/b v0.1.0 // indirect',
    '\texample.com/local v0.0.0',
    '\texample.com/old v0.0.0-00010101000000-000000000000 // indirect',
    ')',
    'replace example.com/local => ../local',
    'replace example.com/old => example.com/new/v3 v3.10.0',
  ].join('\n')
  assert.deepEqual(
    [...packagesIn('svc/go.mod', mod).keys()].sort(),
    ['go:example.com/a@v1.2.3', 'go:example.com/b@v0.1.0', 'go:example.com/new/v3@v3.10.0', 'go:example.com/single@v1.0.0'],
  )
})

test('licence files are named from their text', () => {
  assert.equal(licenceFromText('Permission is hereby granted, free of charge, to any person'), 'MIT')
  assert.equal(licenceFromText('Apache License\n                           Version 2.0, January 2004'), 'Apache-2.0')
  assert.equal(licenceFromText('Redistribution and use in source and binary forms ... Neither the name of'), 'BSD-3-Clause')
  assert.equal(licenceFromText('GNU GENERAL PUBLIC LICENSE\n Version 2, June 1991'), 'GPL-2.0-only')
  assert.equal(licenceFromText('GNU GENERAL PUBLIC LICENSE\n Version 2, June 1991 ... either version 2 of the License, or (at your option) any later version'), 'GPL-2.0-or-later')
  assert.equal(licenceFromText('Elastic License 2.0'), 'Elastic-2.0')
  assert.equal(licenceFromText('All rights reserved.'), null)
})
