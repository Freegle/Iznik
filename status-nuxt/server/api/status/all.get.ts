import { execSync } from 'child_process'
import { services } from '~/server/utils/services'

interface ContainerInfo {
  name: string
  status: string
  state: string
}

/**
 * Get all container statuses from Docker
 */
function getContainerStatuses(): Map<string, ContainerInfo> {
  const containers = new Map<string, ContainerInfo>()

  try {
    const output = execSync(
      'docker ps -a --format "{{.Names}}\t{{.Status}}\t{{.State}}"',
      { encoding: 'utf8', timeout: 5000 }
    )

    for (const line of output.split('\n')) {
      if (!line.trim()) continue
      const [name, status, state] = line.split('\t')
      const prefix = process.env.COMPOSE_PROJECT_NAME || 'freegle'
      if (name && name.startsWith(`${prefix}-`)) {
        containers.set(name, {
          name,
          status: status?.trim() || '',
          state: state?.trim() || '',
        })
      }
    }
  }
  catch (err) {
    console.error('Failed to get container statuses:', err)
  }

  return containers
}

/**
 * Map Docker state to CircleCI-compatible status
 * Returns 'success' for running/healthy, 'failed' for offline/unhealthy
 */
function mapDockerStateToStatus(state: string, statusStr: string): string {
  if (state === 'running') {
    if (statusStr.includes('(unhealthy)')) return 'failed'
    if (statusStr.includes('(health: starting)')) return 'loading'
    return 'success'
  }
  if (state === 'restarting') return 'loading'
  if (state === 'created' || state === 'paused') return 'loading'
  return 'failed'
}

/**
 * GET /api/status/all
 * Returns flat object keyed by service ID with status for CircleCI compatibility
 * Format: { "apiv1": { "status": "success" }, "apiv2": { "status": "success" }, ... }
 */
export default defineEventHandler(async () => {
  const containerStatuses = getContainerStatuses()
  const result: Record<string, { status: string; message?: string }> = {}

  for (const service of services) {
    const containerId = service.container || service.id
    const container = containerStatuses.get(containerId)

    if (container) {
      result[service.id] = {
        status: mapDockerStateToStatus(container.state, container.status),
        message: container.status,
      }
    }
    else {
      result[service.id] = {
        status: 'unknown',
        message: 'Container not found',
      }
    }
  }

  return result
})
