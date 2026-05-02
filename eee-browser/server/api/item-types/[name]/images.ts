import Database from 'better-sqlite3'

export default defineEventHandler(async (event) => {
  const itemName = getRouterParam(event, 'name')
  if (!itemName) {
    throw createError({
      statusCode: 400,
      statusMessage: 'Item name required',
    })
  }

  const dbPath = process.env.EEE_SQLITE_PATH
  if (!dbPath) {
    throw createError({
      statusCode: 500,
      statusMessage: 'EEE_SQLITE_PATH environment variable not set',
    })
  }

  try {
    const db = new Database(dbPath, { readonly: true })

    // Get sample images for this item type
    const samplesQuery = `
      SELECT
        item_name,
        messageid,
        attid,
        externaluid,
        subject,
        textbody
      FROM eee_item_type_samples
      WHERE item_name = ?
      ORDER BY sampled_at DESC
      LIMIT 10
    `

    const samples = db
      .prepare(samplesQuery)
      .all(itemName) as any[]

    // For each sample, get classifications from all models
    const result = samples.map((sample) => {
      const classificationsQuery = `
        SELECT
          model,
          is_eee,
          is_eee_confidence,
          is_eee_reasoning,
          contains_eee_components,
          electrical_components_description,
          condition,
          brand,
          model_number,
          photo_quality,
          value_band_gbp,
          weight_kg_min,
          weight_kg_max
        FROM eee_classifications
        WHERE messageid = ? AND attid = ?
        ORDER BY model
      `

      const classifications = db
        .prepare(classificationsQuery)
        .all(sample.messageid, sample.attid) as any[]

      const tusBase = process.env.TUS_BASE_URL || 'https://images.ilovefreegle.org/'
      const imageUrl = `${tusBase}${sample.externaluid}`

      return {
        messageid: sample.messageid,
        attid: sample.attid,
        externaluid: sample.externaluid,
        imageUrl,
        subject: sample.subject,
        textbody: sample.textbody,
        classifications: classifications.map((c) => ({
          model: c.model,
          isEee: c.is_eee,
          isEeeConfidence: c.is_eee_confidence,
          isEeeReasoning: c.is_eee_reasoning,
          containsEeeComponents: c.contains_eee_components,
          electricalComponentsDescription: c.electrical_components_description,
          condition: c.condition,
          brand: c.brand,
          modelNumber: c.model_number,
          photoQuality: c.photo_quality,
          valueBandGbp: c.value_band_gbp,
          weightKgMin: c.weight_kg_min,
          weightKgMax: c.weight_kg_max,
        })),
      }
    })

    db.close()

    return {
      itemName,
      images: result,
    }
  } catch (error) {
    console.error('Database error:', error)
    throw createError({
      statusCode: 500,
      statusMessage: `Database error: ${(error as Error).message}`,
    })
  }
})
