import { execSync } from 'child_process'
import { services } from '~/server/utils/services'
import type { ServiceStatus } from '~/types/service'

interface ContainerInfo {
  name: string
  status: string
  state: string
  uptime?: string
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
          uptime: extractUptime(status),
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
 * Extract uptime from Docker status string (e.g., "Up 2 hours")
 */
function extractUptime(status: string): string | undefined {
  if (!status) return undefined
  const match = status.match(/Up (.+)/)
  return match ? match[1] : undefined
}

/**
 * Map Docker state to our status
 */
function mapDockerStateToStatus(state: string, status: string): ServiceStatus {
  if (state === 'running') {
    // Check for health status in the status string
    if (status.includes('(healthy)')) return 'online'
    if (status.includes('(unhealthy)')) return 'offline'
    if (status.includes('(health: starting)')) return 'loading'
    // No health check, assume online if running
    return 'online'
  }
  if (state === 'restarting') return 'loading'
  if (state === 'created' || state === 'paused') return 'loading'
  return 'offline'
}

export default defineEventHandler(async () => {
  const containerStatuses = getContainerStatuses()

  const serviceStatuses: Record<string, any> = {}

  for (const service of services) {
    const containerId = service.container || service.id
    const container = containerStatuses.get(containerId)

    if (container) {
      serviceStatuses[service.id] = {
        status: mapDockerStateToStatus(container.state, container.status),
        message: container.status,
        uptime: container.uptime,
      }
    }
    else {
      // Container not found
      serviceStatuses[service.id] = {
        status: 'offline',
        message: 'Container not found',
      }
    }
  }

  // Include project identity info so users can tell worktrees apart.
  let branch = ''
  let worktree = ''
  try {
    branch = execSync('git -c safe.directory=/project -C /project rev-parse --abbrev-ref HEAD', { encoding: 'utf8', timeout: 3000 }).trim()
    worktree = execSync('git -c safe.directory=/project -C /project rev-parse --show-toplevel', { encoding: 'utf8', timeout: 3000 }).trim()
  } catch { /* not a git repo or git not available */ }

  return {
    services: serviceStatuses,
    project: process.env.COMPOSE_PROJECT_NAME || 'freegle',
    branch,
    worktree,
    timestamp: new Date().toISOString(),
  }
})
