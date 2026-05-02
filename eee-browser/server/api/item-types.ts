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

    // Get all item types grouped by name, with counts per model
    const query = `
      SELECT
        it.item_name,
        it.weee_category_name,
        it.is_eee_agree_rate,
        it.sample_size,
        it.images_analysed,
        COUNT(DISTINCT CASE WHEN c.model = 'claude-sonnet-4-6' AND c.is_eee = 1 THEN 1 END) as claude_eee,
        COUNT(DISTINCT CASE WHEN c.model = 'gemini-2.0-flash-lite' AND c.is_eee = 1 THEN 1 END) as gemini_eee,
        COUNT(DISTINCT CASE WHEN c.model = 'claude-sonnet-4-6' THEN 1 END) as claude_total,
        COUNT(DISTINCT CASE WHEN c.model = 'gemini-2.0-flash-lite' THEN 1 END) as gemini_total
      FROM eee_item_types it
      LEFT JOIN eee_item_type_samples s ON it.item_name = s.item_name
      LEFT JOIN eee_classifications c ON s.messageid = c.messageid AND s.attid = c.attid
      GROUP BY it.item_name
      ORDER BY it.item_name
    `

    const items = db.prepare(query).all()

    db.close()

    return {
      items: items.map((item: any) => ({
        itemName: item.item_name,
        weeCategory: item.weee_category_name,
        agreeRate: item.is_eee_agree_rate,
        sampleSize: item.sample_size,
        imagesAnalysed: item.images_analysed,
        claudeEeeCount: item.claude_eee || 0,
        geminiEeeCount: item.gemini_eee || 0,
        claudeTotal: item.claude_total || 0,
        geminiTotal: item.gemini_total || 0,
      })),
    }
  } catch (error) {
    console.error('Database error:', error)
    throw createError({
      statusCode: 500,
      statusMessage: `Database error: ${(error as Error).message}`,
    })
  }
})
