// Base API class following iznik-nuxt3 patterns
export default class BaseAPI {
  protected config: any

  constructor(config?: any) {
    this.config = config || useRuntimeConfig()
  }

  async $get<T = any>(path: string, params: Record<string, any> = {}): Promise<T> {
    const queryString = new URLSearchParams(params).toString()
    const url = queryString ? `${path}?${queryString}` : path
    return await $fetch<T>(url, { method: 'GET' })
  }

  async $post<T = any>(path: string, data: Record<string, any> = {}): Promise<T> {
    return await $fetch<T>(path, {
      method: 'POST',
      body: data,
    })
  }

  async $put<T = any>(path: string, data: Record<string, any> = {}): Promise<T> {
    return await $fetch<T>(path, {
      method: 'PUT',
      body: data,
    })
  }

  async $del<T = any>(path: string, data: Record<string, any> = {}): Promise<T> {
    return await $fetch<T>(path, {
      method: 'DELETE',
      body: data,
    })
  }
}
