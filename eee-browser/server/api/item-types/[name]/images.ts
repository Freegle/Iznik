import Database from 'better-sqlite3'

function buildImageUrl(externaluid: string): string {
  if (externaluid.startsWith('freegletusd-')) {
    const fileId = externaluid.slice('freegletusd-'.length)
    return `https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/${fileId}&w=768&h=768&fit=inside&output=jpg`
  }
  // Legacy Uploadcare UUID
  return `https://ucarecdn.com/${externaluid}/-/preview/768x768/-/format/jpeg/`
}

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
          c.model,
          c.prompt_version,
          c.is_eee,
          c.is_eee_confidence,
          c.is_eee_reasoning,
          c.is_eee_from_components,
          c.contains_eee_components,
          c.electrical_components_description,
          c.weee_category,
          c.condition,
          c.brand,
          c.model_number,
          c.photo_quality,
          c.value_band_gbp,
          c.weight_kg_min,
          c.weight_kg_max,
          c.size_cm,
          c.size_confidence,
          c.material_primary,
          c.material_secondary,
          c.item_complete,
          c.item_complete_notes,
          c.accessories_visible
        FROM eee_classifications c
        INNER JOIN (
          SELECT model, MAX(prompt_version) AS max_pv
          FROM eee_classifications
          WHERE messageid = ? AND attid = ?
          GROUP BY model
        ) latest ON c.model = latest.model AND c.prompt_version = latest.max_pv
        WHERE c.messageid = ? AND c.attid = ?
        ORDER BY c.model
      `

      const classifications = db
        .prepare(classificationsQuery)
        .all(sample.messageid, sample.attid, sample.messageid, sample.attid) as any[]

      const imageUrl = buildImageUrl(sample.externaluid)

      return {
        messageid: sample.messageid,
        attid: sample.attid,
        externaluid: sample.externaluid,
        imageUrl,
        subject: sample.subject,
        textbody: sample.textbody,
        classifications: classifications.map((c) => ({
          model: c.model,
          promptVersion: c.prompt_version,
          isEee: c.is_eee,
          isEeeConfidence: c.is_eee_confidence,
          isEeeReasoning: c.is_eee_reasoning,
          isEeeFromComponents: c.is_eee_from_components,
          containsEeeComponents: c.contains_eee_components,
          electricalComponentsDescription: c.electrical_components_description,
          weeeCategory: c.weee_category,
          condition: c.condition,
          brand: c.brand,
          modelNumber: c.model_number,
          photoQuality: c.photo_quality,
          valueBandGbp: c.value_band_gbp,
          weightKgMin: c.weight_kg_min,
          weightKgMax: c.weight_kg_max,
          sizeCm: c.size_cm ? JSON.parse(c.size_cm) : null,
          sizeConfidence: c.size_confidence,
          materialPrimary: c.material_primary,
          materialSecondary: c.material_secondary,
          itemComplete: c.item_complete,
          itemCompleteNotes: c.item_complete_notes,
          accessoriesVisible: c.accessories_visible ? JSON.parse(c.accessories_visible) : [],
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
