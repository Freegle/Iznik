import StatusAPI from './StatusAPI'

// API factory function following iznik-nuxt3 pattern
export function api(config?: any) {
  return {
    status: new StatusAPI(config),
  }
}
