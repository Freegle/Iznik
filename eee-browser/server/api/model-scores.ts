import Database from 'better-sqlite3'

const MODEL_DISPLAY: Record<string, string> = {
  'claude-opus-4-7': 'Claude Opus',
  'claude-sonnet-4-6': 'Claude Sonnet',
  'gemini-2.0-flash-lite': 'Gemini Flash Lite',
  'gpt-4o': 'GPT-4o',
  'geeks_c87e/Qwen/Qwen2.5-VL-72B-Instruct-e07a2308': 'Qwen2.5-VL 72B',
}

// User-assigned importance weights (0 = excluded)
const COMPLETENESS_WEIGHTS: Record<string, number> = {
  electrical_components_description: 4,
  photo_quality: 4,
  condition: 3,
  weight_kg_min: 3,
  value_band_gbp: 2,
  material_primary: 2,
  size_cm: 2,
  model_number: 2,
  brand: 1,
  // excluded (weight 0):
  // weee_category, item_complete
}

export default defineEventHandler(async () => {
  const dbPath = process.env.EEE_SQLITE_PATH
  if (!dbPath) throw createError({ statusCode: 500, statusMessage: 'EEE_SQLITE_PATH not set' })

  try {
    const db = new Database(dbPath, { readonly: true })
    const PV = '1.4.1'

    // Opus was a one-off research run — exclude from the main comparison
    const EXCLUDE_MODELS = ['claude-opus-4-7']

    const basicStats = db.prepare(`
      SELECT
        model,
        COUNT(*) AS n,
        ROUND(SUM(CASE WHEN photo_quality IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS img_ok_pct,
        ROUND(SUM(COALESCE(cost_usd, 0)), 4) AS total_cost
      FROM eee_classifications
      WHERE prompt_version = ?
        AND model NOT IN (${EXCLUDE_MODELS.map(() => '?').join(',')})
      GROUP BY model
    `).all(PV, ...EXCLUDE_MODELS) as any[]

    // Fields included in cross-model agreement (WEEE category and Item complete removed)
    const fields = [
      { name: 'EEE', col: 'is_eee', type: 'binary' },
      { name: 'Condition', col: 'condition', type: 'exact' },
      { name: 'Photo quality', col: 'photo_quality', type: 'within1' },
      { name: 'Value band', col: 'value_band_gbp', type: 'exact' },
      { name: 'Brand', col: 'brand', type: 'exact_ci' },
      { name: 'Weight (kg)', col: 'weight_kg_min', type: 'within20pct' },
    ]

    const models = basicStats.map((r: any) => r.model)

    const fieldAgreement: Record<string, Record<string, number | null>> = {}

    for (const field of fields) {
      fieldAgreement[field.name] = {}

      let majority_expr: string
      if (field.type === 'binary') {
        majority_expr = `CASE WHEN AVG(CAST(${field.col} AS FLOAT)) >= 0.5 THEN 1 ELSE 0 END`
      } else if (field.type === 'within1') {
        majority_expr = `CAST(ROUND(AVG(CAST(${field.col} AS FLOAT))) AS INTEGER)`
      } else if (field.type === 'within20pct') {
        majority_expr = `AVG(CAST(${field.col} AS FLOAT))`
      } else {
        majority_expr = `(SELECT ${field.col} FROM eee_classifications c2
          WHERE c2.messageid = grp.messageid AND c2.attid = grp.attid AND c2.prompt_version = '${PV}'
            AND c2.${field.col} IS NOT NULL
          GROUP BY ${field.col} ORDER BY COUNT(*) DESC LIMIT 1)`
      }

      let match_expr: string
      if (field.type === 'binary') {
        match_expr = `c.${field.col} = m.majority`
      } else if (field.type === 'within1') {
        match_expr = `ABS(CAST(c.${field.col} AS INTEGER) - m.majority) <= 1`
      } else if (field.type === 'within20pct') {
        match_expr = `m.majority > 0 AND ABS(CAST(c.${field.col} AS FLOAT) - m.majority) / m.majority <= 0.20`
      } else if (field.type === 'exact_ci') {
        match_expr = `LOWER(c.${field.col}) = LOWER(m.majority)`
      } else {
        match_expr = `c.${field.col} = m.majority`
      }

      const agreeRows = db.prepare(`
        WITH grp AS (
          SELECT messageid, attid
          FROM eee_classifications
          WHERE prompt_version = ? AND ${field.col} IS NOT NULL
          GROUP BY messageid, attid
          HAVING COUNT(DISTINCT model) >= 2
        ),
        majority_cte AS (
          SELECT grp.messageid, grp.attid,
            ${majority_expr} AS majority
          FROM grp
          JOIN eee_classifications base ON base.messageid = grp.messageid AND base.attid = grp.attid
            AND base.prompt_version = ?
          GROUP BY grp.messageid, grp.attid
        )
        SELECT c.model,
          COUNT(*) AS n,
          SUM(CASE WHEN ${match_expr} THEN 1 ELSE 0 END) AS matches
        FROM eee_classifications c
        JOIN majority_cte m ON c.messageid = m.messageid AND c.attid = m.attid
        WHERE c.prompt_version = ? AND c.${field.col} IS NOT NULL
        GROUP BY c.model
      `).all(PV, PV, PV) as any[]

      for (const row of agreeRows) {
        fieldAgreement[field.name][row.model] = row.n > 0 ? Math.round(row.matches * 100 / row.n) : null
      }
    }

    // Consensus per field = average agreement across models, then sort fields by consensus desc
    const fieldConsensus: Record<string, number> = {}
    for (const field of fields) {
      const vals = models
        .map((m: string) => fieldAgreement[field.name][m])
        .filter((v): v is number => v !== null && v !== undefined)
      fieldConsensus[field.name] = vals.length > 0
        ? Math.round(vals.reduce((a, b) => a + b, 0) / vals.length)
        : 0
    }
    const sortedFields = [...fields].sort((a, b) => fieldConsensus[b.name] - fieldConsensus[a.name])

    // Field completeness (weighted by importance)
    const completenessFields = Object.keys(COMPLETENESS_WEIGHTS)
    const completenessRows = db.prepare(`
      SELECT model, ${completenessFields.map(f =>
        `ROUND(SUM(CASE WHEN ${f} IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS ${f}`
      ).join(', ')}
      FROM eee_classifications
      WHERE prompt_version = ?
      GROUP BY model
    `).all(PV) as any[]

    const completeness: Record<string, Record<string, number>> = {}
    for (const row of completenessRows) {
      completeness[row.model] = {}
      for (const f of completenessFields) {
        completeness[row.model][f] = row[f]
      }
    }

    const totalWeight = Object.values(COMPLETENESS_WEIGHTS).reduce((a, b) => a + b, 0)

    // Agreement with Sonnet on is_eee — used as accuracy proxy until human labels are available
    const SONNET = 'claude-sonnet-4-6'
    const sonnetAgreeRows = db.prepare(`
      SELECT c.model,
        COUNT(*) AS n,
        ROUND(SUM(CASE WHEN c.is_eee = s.is_eee THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS sonnet_agree
      FROM eee_classifications c
      JOIN eee_classifications s
        ON s.messageid = c.messageid AND s.attid = c.attid
        AND s.model = ? AND s.prompt_version = ?
      WHERE c.prompt_version = ?
        AND c.is_eee IS NOT NULL AND s.is_eee IS NOT NULL
        AND c.model NOT IN (${EXCLUDE_MODELS.map(() => '?').join(',')})
      GROUP BY c.model
    `).all(SONNET, PV, PV, ...EXCLUDE_MODELS) as any[]

    const sonnetAgreement: Record<string, number | null> = {}
    for (const row of sonnetAgreeRows) {
      sonnetAgreement[row.model] = row.sonnet_agree
    }

    const scored = basicStats.map((r: any) => {
      const modelCompleteness = completeness[r.model] ?? {}
      const weightedCompleteness = completenessFields.reduce((sum, f) => {
        return sum + (modelCompleteness[f] ?? 0) * COMPLETENESS_WEIGHTS[f]
      }, 0) / totalWeight
      const isSonnet = r.model === SONNET

      return {
        model: r.model,
        displayName: MODEL_DISPLAY[r.model] ?? r.model,
        n: r.n,
        imgOkPct: r.img_ok_pct,
        imgPartial: r.img_ok_pct < 99,
        weightedCompleteness: Math.round(weightedCompleteness * 10) / 10,
        isReference: isSonnet,
        sonnetAgreement: isSonnet ? null : (sonnetAgreement[r.model] ?? null),
        costPerImage: r.n > 0 ? r.total_cost / r.n : 0,
        totalCost: r.total_cost,
      }
    }).sort((a: any, b: any) => {
      if (a.isReference) return -1
      if (b.isReference) return 1
      return (b.sonnetAgreement ?? 0) - (a.sonnetAgreement ?? 0)
    })

    db.close()

    return {
      models: scored,
      fields: sortedFields.map(f => f.name),
      fieldAgreement,
      fieldConsensus,
      completenessFields,
      completeness,
      completenessWeights: COMPLETENESS_WEIGHTS,
    }
  } catch (error) {
    throw createError({ statusCode: 500, statusMessage: `Database error: ${(error as Error).message}` })
  }
})
