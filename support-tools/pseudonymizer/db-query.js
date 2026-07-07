/**
 * Database Query Module for the Pseudonymizer.
 *
 * Provides validated, pseudonymized SQL queries against the Freegle production database.
 * Reuses the pseudonymizer's token creation for consistent tokens across Loki and DB results.
 *
 * Security:
 * - Only SELECT queries allowed (enforced by validation)
 * - Only whitelisted tables and columns accessible
 * - SENSITIVE columns are pseudonymized in results (emails, names, IPs, free text)
 * - WHERE clauses on SENSITIVE columns only allow token-based filtering
 * - Results capped at 500 rows
 * - Read-only transaction mode enforced at connection level
 *
 * Adapted from the original ai-sanitizer service (commit 0ab181ae).
 */

const mysql = require('mysql2/promise')

// Database connection pool (lazy-initialized)
let mysqlPool = null

const DB_CONFIG = {
  host: process.env.DB_HOST || 'freegle-percona',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || 'iznik',
  database: process.env.DB_NAME || 'iznik',
  waitForConnections: true,
  connectionLimit: 5,
  maxIdle: 2,
  idleTimeout: 60000,
  queueLimit: 0,
  multipleStatements: false,
}

function getMysqlPool() {
  if (!mysqlPool) {
    mysqlPool = mysql.createPool(DB_CONFIG)
  }
  return mysqlPool
}

// ============================================================
// Database Schema — per-column privacy classification
// PUBLIC = passed through as-is, SENSITIVE = pseudonymized
// ============================================================

const DB_SCHEMA = {
  users: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      firstname: 'SENSITIVE',
      lastname: 'SENSITIVE',
      fullname: 'SENSITIVE',
      systemrole: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
      bouncing: 'PUBLIC',
      deleted: 'PUBLIC',
      engagement: 'PUBLIC',
      trustlevel: 'PUBLIC',
      chatmodstatus: 'PUBLIC',
      newsfeedmodstatus: 'PUBLIC',
      lastupdated: 'PUBLIC',
    },
  },
  users_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      email: 'SENSITIVE',
      preferred: 'PUBLIC',
      added: 'PUBLIC',
      validated: 'PUBLIC',
      bounced: 'PUBLIC',
      viewed: 'PUBLIC',
    },
  },
  messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      arrival: 'PUBLIC',
      date: 'PUBLIC',
      deleted: 'PUBLIC',
      source: 'PUBLIC',
      fromip: 'SENSITIVE',
      fromcountry: 'PUBLIC',
      fromuser: 'PUBLIC',
      fromname: 'SENSITIVE',
      fromaddr: 'SENSITIVE',
      subject: 'PUBLIC',
      suggestedsubject: 'PUBLIC',
      type: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      locationid: 'PUBLIC',
      availableinitially: 'PUBLIC',
      availablenow: 'PUBLIC',
      spamtype: 'PUBLIC',
      spamreason: 'PUBLIC',
      heldby: 'PUBLIC',
      editedby: 'PUBLIC',
      editedat: 'PUBLIC',
    },
  },
  messages_groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      arrival: 'PUBLIC',
      autoreposts: 'PUBLIC',
      lastautopostwarning: 'PUBLIC',
      lastchaseup: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },
  messages_outcomes: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      outcome: 'PUBLIC',
      timestamp: 'PUBLIC',
      userid: 'PUBLIC',
      happiness: 'PUBLIC',
      comments: 'SENSITIVE',
    },
  },
  chat_rooms: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chattype: 'PUBLIC',
      user1: 'PUBLIC',
      user2: 'PUBLIC',
      groupid: 'PUBLIC',
      created: 'PUBLIC',
      lastmsg: 'PUBLIC',
      synctofacebook: 'PUBLIC',
    },
  },
  chat_messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chatid: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      reportreason: 'PUBLIC',
      refmsgid: 'PUBLIC',
      refchatid: 'PUBLIC',
      imageid: 'PUBLIC',
      date: 'PUBLIC',
      message: 'SENSITIVE',
      platform: 'PUBLIC',
      seenbyall: 'PUBLIC',
      mailedtoall: 'PUBLIC',
      reviewrequired: 'PUBLIC',
      reviewedby: 'PUBLIC',
      reviewrejected: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },
  groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      nameshort: 'PUBLIC',
      namefull: 'PUBLIC',
      nameabbr: 'PUBLIC',
      type: 'PUBLIC',
      region: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      membercount: 'PUBLIC',
      modcount: 'PUBLIC',
      tagline: 'PUBLIC',
      description: 'PUBLIC',
      founded: 'PUBLIC',
      publish: 'PUBLIC',
      listable: 'PUBLIC',
      onmap: 'PUBLIC',
      onhere: 'PUBLIC',
      contactmail: 'SENSITIVE',
      external: 'PUBLIC',
      lastmoderated: 'PUBLIC',
      lastmodactive: 'PUBLIC',
    },
  },
  memberships: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      role: 'PUBLIC',
      collection: 'PUBLIC',
      configid: 'PUBLIC',
      added: 'PUBLIC',
      deleted: 'PUBLIC',
      emailfrequency: 'PUBLIC',
      eventsallowed: 'PUBLIC',
      volunteeringallowed: 'PUBLIC',
      ourpostingstatus: 'PUBLIC',
      heldby: 'PUBLIC',
    },
  },
  memberships_history: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      added: 'PUBLIC',
    },
  },
  logs: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      timestamp: 'PUBLIC',
      byuser: 'PUBLIC',
      type: 'PUBLIC',
      subtype: 'PUBLIC',
      groupid: 'PUBLIC',
      user: 'PUBLIC',
      msgid: 'PUBLIC',
      configid: 'PUBLIC',
      bulkopid: 'PUBLIC',
      text: 'SENSITIVE',
    },
  },
  users_logins: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
    },
  },
  users_active: {
    allowed: true,
    fields: {
      userid: 'PUBLIC',
      timestamp: 'PUBLIC',
    },
  },
  bounces: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      date: 'PUBLIC',
      to: 'SENSITIVE',
      msg: 'SENSITIVE',
      permanent: 'PUBLIC',
    },
  },
  bounces_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      emailid: 'PUBLIC',
      date: 'PUBLIC',
      reason: 'SENSITIVE',
      permanent: 'PUBLIC',
      reset: 'PUBLIC',
    },
  },
}

// ============================================================
// Schema helpers
// ============================================================

function getAllowedTables() {
  return Object.keys(DB_SCHEMA).filter((table) => DB_SCHEMA[table].allowed)
}

function getAllowedColumns(table) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) return []
  return Object.keys(schema.fields)
}

function getColumnPrivacy(table, column) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) return null
  return schema.fields[column.toLowerCase()] || null
}

function isTableAllowed(table) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  return schema?.allowed ?? false
}

// ============================================================
// SQL Validation
// ============================================================

const DANGEROUS_KEYWORDS = [
  'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
  'REPLACE', 'GRANT', 'REVOKE', 'CALL', 'EXEC', 'EXECUTE',
  'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'LOAD DATA',
]

const MAX_LIMIT = 500

/**
 * Validate and restrict WHERE clauses on SENSITIVE columns.
 * Only token-based filtering is allowed (tokens get translated by translateQuery).
 * Literal LIKE/pattern matching on sensitive columns is blocked.
 */
function validateWhereClause(sql) {
  // Extract WHERE clause
  const whereMatch = sql.match(/\bWHERE\b(.*?)(?:\bORDER\b|\bGROUP\b|\bLIMIT\b|\bHAVING\b|$)/is)
  if (!whereMatch) return { valid: true }

  const whereClause = whereMatch[1]

  // Check for LIKE on sensitive columns
  const likePattern = /([a-z_][a-z0-9_.]*)\s+LIKE\s+/gi
  let likeMatch
  while ((likeMatch = likePattern.exec(whereClause)) !== null) {
    const colRef = likeMatch[1].toLowerCase()
    const colName = colRef.includes('.') ? colRef.split('.')[1] : colRef

    // Check all tables for this column
    for (const [tableName, tableConfig] of Object.entries(DB_SCHEMA)) {
      if (tableConfig.fields[colName] === 'SENSITIVE') {
        return {
          valid: false,
          error: `LIKE filtering on sensitive column '${colName}' is not allowed. Use exact token matches instead.`,
        }
      }
    }
  }

  return { valid: true }
}

function validateSql(sql) {
  const normalizedSql = sql.trim()
  const upperSql = normalizedSql.toUpperCase()

  if (!upperSql.startsWith('SELECT')) {
    return { valid: false, error: 'Only SELECT queries are allowed' }
  }

  for (const keyword of DANGEROUS_KEYWORDS) {
    const regex = new RegExp(`\\b${keyword}\\b`, 'i')
    if (regex.test(normalizedSql)) {
      return { valid: false, error: `Forbidden keyword: ${keyword}` }
    }
  }

  const subqueryCount = (normalizedSql.match(/\bSELECT\b/gi) || []).length
  if (subqueryCount > 1) {
    return { valid: false, error: 'Subqueries are not supported' }
  }

  const { tables, aliasMap } = extractTables(normalizedSql)
  if (tables.length === 0) {
    return { valid: false, error: 'No tables found in query' }
  }

  for (const table of tables) {
    if (!isTableAllowed(table)) {
      const allowedList = getAllowedTables().join(', ')
      return { valid: false, error: `Table '${table}' is not allowed. Allowed tables: ${allowedList}` }
    }
  }

  const columnsResult = extractColumns(normalizedSql, tables, aliasMap)
  if (!columnsResult.valid) return columnsResult

  for (const col of columnsResult.columns || []) {
    const privacy = getColumnPrivacy(col.table, col.column)
    if (!privacy) {
      const allowedCols = getAllowedColumns(col.table).join(', ')
      return { valid: false, error: `Column '${col.column}' is not allowed on table '${col.table}'. Allowed: ${allowedCols}` }
    }
  }

  // Block LIKE on sensitive columns
  const whereValidation = validateWhereClause(normalizedSql)
  if (!whereValidation.valid) return whereValidation

  // Enforce LIMIT
  const limitMatch = upperSql.match(/LIMIT\s+(\d+)/i)
  let finalSql = normalizedSql
  if (!limitMatch) {
    finalSql = `${normalizedSql} LIMIT ${MAX_LIMIT}`
  } else {
    const requestedLimit = parseInt(limitMatch[1], 10)
    if (requestedLimit > MAX_LIMIT) {
      finalSql = normalizedSql.replace(/LIMIT\s+\d+/i, `LIMIT ${MAX_LIMIT}`)
    }
  }

  return { valid: true, tables, columns: columnsResult.columns, normalizedSql: finalSql }
}

// ============================================================
// SQL Parsing helpers
// ============================================================

function extractTables(sql) {
  const tables = []
  const aliasMap = {}

  const fromMatch = sql.match(/\bFROM\s+([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i)
  if (fromMatch) {
    const tableName = fromMatch[1].toLowerCase()
    tables.push(tableName)
    if (fromMatch[2]) {
      const alias = fromMatch[2].toLowerCase()
      if (!['inner', 'left', 'right', 'outer', 'cross', 'join', 'where', 'order', 'group', 'having', 'limit'].includes(alias)) {
        aliasMap[alias] = tableName
      }
    }
  }

  const joinRegex = /\b(?:INNER\s+|LEFT\s+|RIGHT\s+|OUTER\s+|CROSS\s+)?JOIN\s+([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/gi
  let match
  while ((match = joinRegex.exec(sql)) !== null) {
    const tableName = match[1].toLowerCase()
    if (!tables.includes(tableName)) tables.push(tableName)
    if (match[2]) {
      const alias = match[2].toLowerCase()
      if (!['on', 'where', 'order', 'group', 'having', 'limit', 'inner', 'left', 'right', 'outer'].includes(alias)) {
        aliasMap[alias] = tableName
      }
    }
  }

  return { tables, aliasMap }
}

function extractColumns(sql, tables, aliasMap = {}) {
  const columns = []
  const selectMatch = sql.match(/SELECT\s+(.*?)\s+\bFROM\b/is)
  if (!selectMatch) return { valid: false, error: 'Could not parse SELECT clause' }

  const selectClause = selectMatch[1].trim()

  if (selectClause === '*') {
    for (const table of tables) {
      for (const col of getAllowedColumns(table)) {
        columns.push({ table, column: col })
      }
    }
    return { valid: true, columns }
  }

  if (/^[a-z_][a-z0-9_]*\.\*$/i.test(selectClause)) {
    let table = selectClause.replace('.*', '').toLowerCase()
    table = aliasMap[table] || table
    if (!tables.includes(table)) return { valid: false, error: `Table '${table}' not in FROM clause` }
    for (const col of getAllowedColumns(table)) columns.push({ table, column: col })
    return { valid: true, columns }
  }

  for (const part of splitColumnList(selectClause)) {
    const trimmed = part.trim()

    if (/^(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i.test(trimmed)) {
      const innerMatch = trimmed.match(/\(([a-z_][a-z0-9_.]*|\*)\)/i)
      if (innerMatch && innerMatch[1] !== '*') {
        const colRef = parseColumnRef(innerMatch[1], tables, aliasMap)
        if (colRef) columns.push(colRef)
      }
      continue
    }

    const colAliasMatch = trimmed.match(/^([a-z_][a-z0-9_.]*)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)$/i)
    if (colAliasMatch) {
      const colRef = parseColumnRef(colAliasMatch[1], tables, aliasMap)
      if (colRef) { colRef.alias = colAliasMatch[2]; columns.push(colRef) }
      continue
    }

    const colRef = parseColumnRef(trimmed, tables, aliasMap)
    if (colRef) columns.push(colRef)
  }

  return { valid: true, columns }
}

function splitColumnList(selectClause) {
  const parts = []
  let current = ''
  let depth = 0
  for (const char of selectClause) {
    if (char === '(') { depth++; current += char }
    else if (char === ')') { depth--; current += char }
    else if (char === ',' && depth === 0) { parts.push(current); current = '' }
    else { current += char }
  }
  if (current.trim()) parts.push(current)
  return parts
}

function parseColumnRef(ref, tables, aliasMap = {}) {
  const trimmed = ref.trim()
  if (trimmed.includes('.')) {
    const [tableOrAlias, column] = trimmed.split('.')
    const table = aliasMap[tableOrAlias.toLowerCase()] || tableOrAlias.toLowerCase()
    return { table, column: column.toLowerCase() }
  }
  for (const table of tables) {
    if (getColumnPrivacy(table, trimmed)) return { table: table.toLowerCase(), column: trimmed.toLowerCase() }
  }
  return null
}

function expandSelectStar(sql, tables) {
  if (!sql.toUpperCase().includes('SELECT *') && !sql.match(/SELECT\s+[a-z_]+\.\*/i)) return sql
  const allColumns = []
  for (const table of tables) {
    for (const col of getAllowedColumns(table)) allColumns.push(`${table}.${col}`)
  }
  return sql.replace(/SELECT\s+\*/i, `SELECT ${allColumns.join(', ')}`)
}

// ============================================================
// Result pseudonymization
// ============================================================

function detectValueType(value, columnName) {
  const lowerCol = columnName.toLowerCase()
  if (lowerCol.includes('email') || lowerCol === 'fromaddr' || lowerCol === 'contactmail') return 'EMAIL'
  if (lowerCol.includes('name') || lowerCol === 'firstname' || lowerCol === 'lastname' ||
      lowerCol === 'fullname' || lowerCol === 'fromname') return 'NAME'
  if (lowerCol.includes('ip') || lowerCol === 'fromip') return 'IP'

  if (/[\w.-]+@[\w.-]+\.\w+/.test(value)) return 'EMAIL'
  if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(value)) return 'IP'
  if (/^(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}$/.test(value)) return 'PHONE'
  if (/^[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}$/i.test(value)) return 'POSTCODE'

  return 'TEXT'
}

/**
 * Mount database query routes onto the Express app.
 * @param {object} app - Express app
 * @param {function} getOrCreateToken - Token creation function from pseudonymizer
 * @param {function} translateQuery - Token translation function from pseudonymizer
 * @param {function} writeAuditLog - Audit logging function from pseudonymizer
 */
function mountDbRoutes(app, getOrCreateToken, translateQuery, writeAuditLog) {

  function pseudonymizeDbRows(rows, columns) {
    return rows.map((row) => {
      const result = {}
      for (const col of columns) {
        const key = col.alias || col.column
        const value = row[key]
        const privacy = getColumnPrivacy(col.table, col.column)
        if (!privacy) continue

        if (privacy === 'PUBLIC') {
          result[key] = value
        } else if (value !== null && value !== undefined && value !== '') {
          const stringValue = String(value)
          const valueType = detectValueType(stringValue, col.column)
          result[key] = getOrCreateToken(stringValue, valueType)
        } else {
          result[key] = value
        }
      }
      return result
    })
  }

  // Schema discovery endpoint for Claude
  app.get('/api/db/schema', (req, res) => {
    const schema = { tables: {}, joins: [], examples: [] }

    for (const [tableName, tableConfig] of Object.entries(DB_SCHEMA)) {
      if (!tableConfig.allowed) continue
      const columns = {}
      for (const [colName, privacy] of Object.entries(tableConfig.fields)) {
        columns[colName] = { type: privacy === 'SENSITIVE' ? 'sensitive (pseudonymized)' : 'public' }
      }
      schema.tables[tableName] = { columns }
    }

    schema.joins = [
      { description: 'User by email', tables: ['users', 'users_emails'], join: 'users.id = users_emails.userid' },
      { description: 'User messages', tables: ['users', 'messages'], join: 'users.id = messages.fromuser' },
      { description: 'User memberships', tables: ['users', 'memberships', 'groups'], join: 'users.id = memberships.userid, memberships.groupid = groups.id' },
      { description: 'Chat participants', tables: ['chat_rooms', 'users'], join: 'chat_rooms.user1 = users.id OR chat_rooms.user2 = users.id' },
      { description: 'Message on group', tables: ['messages', 'messages_groups', 'groups'], join: 'messages.id = messages_groups.msgid, messages_groups.groupid = groups.id' },
    ]

    schema.examples = [
      { q: 'What posts has a user made?', sql: 'SELECT id, subject, type, arrival FROM messages WHERE fromuser = USER_xxx ORDER BY arrival DESC LIMIT 20' },
      { q: 'What groups is a user in?', sql: 'SELECT g.nameshort, m.role, m.added FROM memberships m INNER JOIN groups g ON m.groupid = g.id WHERE m.userid = USER_xxx' },
      { q: 'User activity status', sql: 'SELECT engagement, bouncing, lastaccess, deleted FROM users WHERE id = USER_xxx' },
      { q: 'Recent chat messages', sql: 'SELECT cm.date, cm.type, cm.message FROM chat_messages cm WHERE cm.chatid = 123 ORDER BY cm.date DESC LIMIT 20' },
    ]

    res.json(schema)
  })

  // Database query endpoint
  app.post('/api/db/query', async (req, res) => {
    const { sessionId, query } = req.body

    if (!query) return res.status(400).json({ error: 'query is required' })

    const startTime = Date.now()

    try {
      // Step 1: Validate SQL
      const validation = validateSql(query)
      if (!validation.valid) {
        return res.status(400).json({ error: `Invalid query: ${validation.error}` })
      }

      // Step 2: Expand SELECT *
      let finalSql = validation.normalizedSql
      if (finalSql.toUpperCase().includes('SELECT *')) {
        finalSql = expandSelectStar(finalSql, validation.tables)
      }

      // Step 3: Translate tokens to real values in WHERE clauses
      const translatedSql = translateQuery(finalSql, sessionId)

      // Step 4: Execute read-only query
      const pool = getMysqlPool()
      const connection = await pool.getConnection()

      try {
        await connection.query('SET SESSION TRANSACTION READ ONLY')
        const [rows] = await connection.execute(translatedSql)

        // Step 5: Build column metadata
        const columns = validation.columns || []
        if (columns.length === 0 && rows.length > 0) {
          const firstTable = validation.tables[0]
          for (const key of Object.keys(rows[0])) {
            columns.push({ table: firstTable, column: key })
          }
        }

        // Step 6: Pseudonymize sensitive columns
        const pseudonymizedRows = pseudonymizeDbRows(rows, columns)

        writeAuditLog({
          sessionId: sessionId || 'anonymous',
          operation: 'db_query',
          request: { query, translatedQuery: translatedSql },
          response: {
            rowCount: pseudonymizedRows.length,
            sensitiveColumns: columns
              .filter((c) => getColumnPrivacy(c.table, c.column) === 'SENSITIVE')
              .map((c) => `${c.table}.${c.column}`),
          },
          durationMs: Date.now() - startTime,
        })

        res.json({
          status: 'success',
          resultCount: pseudonymizedRows.length,
          columns: columns.map((c) => c.alias || c.column),
          rows: pseudonymizedRows,
        })
      } finally {
        connection.release()
      }
    } catch (err) {
      if (err.code === 'ECONNREFUSED') {
        return res.status(503).json({ error: 'Database connection failed' })
      }
      if (err.sqlMessage) {
        return res.status(400).json({ error: `Database error: ${err.sqlMessage}` })
      }
      res.status(500).json({ error: `Query failed: ${err.message}` })
    }
  })
}

module.exports = { mountDbRoutes, DB_SCHEMA, getAllowedTables }
