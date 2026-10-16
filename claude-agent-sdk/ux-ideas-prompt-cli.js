#!/usr/bin/env node
'use strict'

// Prints the idea generator's prompt for a brief.json (see ux-ideas-prompt.js), so the backtest
// workflow and a person can see exactly what the live endpoint would send. Usage:
//   node ux-ideas-prompt-cli.js path/to/brief.json [--schema]
const fs = require('node:fs')
const { buildIdeasPrompt, IDEAS_SCHEMA } = require('./ux-ideas-prompt')

const file = process.argv[2]
if (!file) {
  console.error('usage: ux-ideas-prompt-cli.js brief.json [--schema]')
  process.exit(2)
}
if (process.argv.includes('--schema')) {
  process.stdout.write(JSON.stringify(IDEAS_SCHEMA, null, 2) + '\n')
} else {
  const brief = JSON.parse(fs.readFileSync(file, 'utf8'))
  process.stdout.write(buildIdeasPrompt(brief) + '\n')
}
