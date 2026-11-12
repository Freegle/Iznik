// Renders the pgloader load file from tools/pg-migrate/pgloader.load.template.
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { ql, SKIPPED_TABLES } from './lib.mjs'
import { etlTables } from './etl.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))

// Fills the template. The two connection strings come from MIGRATE_MYSQL_URI and MIGRATE_PG_URI
// (for example mysql://user:password@host/iznik); nothing is defaulted, so a credential is never
// written into this repository.
export function fillTemplate(template, { mysqlUri, pgUri, excluded }) {
  if (!mysqlUri || !pgUri) throw new Error('set MIGRATE_MYSQL_URI and MIGRATE_PG_URI to the pgloader connection strings')
  const values = { MYSQL_URI: mysqlUri, PG_URI: pgUri, EXCLUDED_TABLES: excluded.map(ql).join(', ') }
  const text = template.replace(/\{\{(\w+)\}\}/g, (_, k) => {
    if (!(k in values)) throw new Error(`the template uses {{${k}}}, which has no value`)
    return values[k]
  })
  return text
}

export async function renderPgloaderConfig(env, vars = process.env) {
  const owned = (await etlTables(env)).map((t) => t.table)
  const excluded = [...new Set([...SKIPPED_TABLES, ...owned])].sort()
  const template = fs.readFileSync(path.join(here, 'pgloader.load.template'), 'utf8')
  return fillTemplate(template, { mysqlUri: vars.MIGRATE_MYSQL_URI, pgUri: vars.MIGRATE_PG_URI, excluded })
}
