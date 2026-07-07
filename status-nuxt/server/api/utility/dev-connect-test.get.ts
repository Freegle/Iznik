export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const ip = query.ip as string
  const port = (query.port as string) || '3002'

  if (!ip) {
    throw createError({
      statusCode: 400,
      message: 'Missing ip parameter'
    })
  }

  const devUrl = `http://${ip}:${port}`

  try {
    const response = await fetch(devUrl)
    return {
      success: true,
      url: devUrl,
      status: response.status,
    }
  } catch (error: any) {
    return {
      success: false,
      url: devUrl,
      error: error.message,
    }
  }
})
