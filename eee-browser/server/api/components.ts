import Database from 'better-sqlite3'

export default defineEventHandler(async (event) => {
  const dbPath = process.env.EEE_SQLITE_PATH
  if (!dbPath) {
    throw createError({
      statusCode: 500,
      statusMessage: 'EEE_SQLITE_PATH environment variable not set',
    })
  }

  try {
    const db = new Database(dbPath, { readonly: true })

    // Get all components with usage counts
    const query = `
      SELECT
        ct.id,
        ct.canonical_name,
        ct.category,
        COUNT(DISTINCT CASE WHEN ec.electrical_components_description LIKE '%' || ct.canonical_name || '%' THEN ec.id END) as usage_count
      FROM eee_component_types ct
      LEFT JOIN eee_classifications ec ON ec.electrical_components_description LIKE '%' || ct.canonical_name || '%'
      GROUP BY ct.id, ct.canonical_name, ct.category
      ORDER BY ct.category, usage_count DESC, ct.canonical_name
    `

    const components = db.prepare(query).all() as any[]

    db.close()

    const grouped = {
      primary_eee: [] as any[],
      supplementary_eee: [] as any[],
      non_electrical: [] as any[],
      unknown: [] as any[],
    }

    components.forEach((comp) => {
      const normalized = comp.category || 'unknown'
      if (grouped[normalized as keyof typeof grouped]) {
        grouped[normalized as keyof typeof grouped].push({
          id: comp.id,
          name: comp.canonical_name,
          category: comp.category,
          usageCount: comp.usage_count || 0,
        })
      }
    })

    return grouped
  } catch (error) {
    console.error('Database error:', error)
    throw createError({
      statusCode: 500,
      statusMessage: `Database error: ${(error as Error).message}`,
    })
  }
})
