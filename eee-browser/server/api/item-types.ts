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

    const QWEN_MODEL = 'geeks_c87e/Qwen/Qwen2.5-VL-72B-Instruct-e07a2308'
    const ANNUAL_OFFERS = 292588

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
        COUNT(DISTINCT CASE WHEN c.model = '${QWEN_MODEL}' AND c.is_eee = 1 THEN 1 END) as qwen_eee,
        COUNT(DISTINCT CASE WHEN c.model = 'claude-sonnet-4-6' THEN 1 END) as claude_total,
        COUNT(DISTINCT CASE WHEN c.model = 'gemini-2.0-flash-lite' THEN 1 END) as gemini_total,
        COUNT(DISTINCT CASE WHEN c.model = '${QWEN_MODEL}' THEN 1 END) as qwen_total
      FROM eee_item_types it
      LEFT JOIN eee_item_type_samples s ON it.item_name = s.item_name
      LEFT JOIN eee_classifications c ON s.messageid = c.messageid AND s.attid = c.attid
      GROUP BY it.item_name
      ORDER BY it.item_name
    `

    const items = db.prepare(query).all()

    const costRow = db.prepare(`
      SELECT
        SUM(cost_usd) AS total,
        COUNT(*) AS total_images
      FROM eee_classifications
      WHERE cost_usd IS NOT NULL
    `).get() as any

    // Per-model cost per image → annual estimate at 292,588 Freegle offers/year
    const modelCosts = db.prepare(`
      SELECT model, SUM(cost_usd) AS total_cost, COUNT(*) AS total_images
      FROM eee_classifications
      WHERE cost_usd IS NOT NULL AND prompt_version = '1.4.1'
      GROUP BY model
    `).all() as any[]

    const annualCosts = modelCosts.map((r) => {
      const perImage = r.total_images > 0 ? r.total_cost / r.total_images : 0
      return {
        model: r.model,
        perImageUsd: perImage,
        annualUsd: perImage * ANNUAL_OFFERS,
      }
    }).sort((a, b) => a.annualUsd - b.annualUsd)

    const costByMonth = db.prepare(`
      SELECT
        strftime('%Y-%m', run_at) AS month,
        model,
        SUM(cost_usd) AS cost
      FROM eee_classifications
      WHERE cost_usd IS NOT NULL
      GROUP BY month, model
      ORDER BY month, cost DESC
    `).all() as any[]

    db.close()

    // Group by month, with per-model breakdown and monthly total
    const monthMap: Record<string, { month: string, total: number, models: { model: string, cost: number }[] }> = {}
    for (const r of costByMonth) {
      if (!monthMap[r.month]) monthMap[r.month] = { month: r.month, total: 0, models: [] }
      monthMap[r.month].total += r.cost
      monthMap[r.month].models.push({ model: r.model, cost: r.cost })
    }

    return {
      totalCostUsd: costRow.total ?? 0,
      totalImages: costRow.total_images ?? 0,
      costByMonth: Object.values(monthMap),
      annualCosts,
      annualOffers: ANNUAL_OFFERS,
      items: items.map((item: any) => ({
        itemName: item.item_name,
        weeCategory: item.weee_category_name,
        agreeRate: item.is_eee_agree_rate,
        sampleSize: item.sample_size,
        imagesAnalysed: item.images_analysed,
        claudeEeeCount: item.claude_eee || 0,
        geminiEeeCount: item.gemini_eee || 0,
        qwenEeeCount: item.qwen_eee || 0,
        claudeTotal: item.claude_total || 0,
        geminiTotal: item.gemini_total || 0,
        qwenTotal: item.qwen_total || 0,
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
