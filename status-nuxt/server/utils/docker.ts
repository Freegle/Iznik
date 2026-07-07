import { execSync } from 'child_process'

/**
 * Detect which docker compose command is available.
 * Docker Compose v2 uses 'docker compose', v1 uses 'docker-compose'.
 */
export function getDockerComposeCommand(): string {
  try {
    execSync('docker compose version', { stdio: 'ignore' })
    return 'docker compose'
  } catch {
    try {
      execSync('docker-compose version', { stdio: 'ignore' })
      return 'docker-compose'
    } catch {
      return 'docker compose' // Default to v2 syntax
    }
  }
}

/**
 * Check if a Docker container is running.
 */
export function isContainerRunning(container: string): boolean {
  try {
    const result = execSync(
      `docker inspect -f '{{.State.Running}}' ${container}`,
      { encoding: 'utf8', timeout: 5000 }
    ).trim()
    return result === 'true'
  } catch {
    return false
  }
}

/**
 * Get container CPU usage percentage.
 */
export function getContainerCpu(container: string): number {
  try {
    const stats = execSync(
      `docker stats ${container} --no-stream --format "{{.CPUPerc}}"`,
      { encoding: 'utf8', timeout: 5000 }
    ).trim()
    return parseFloat(stats.replace('%', '')) || 0
  } catch {
    return 0
  }
}

/**
 * Get logs from a container.
 */
export function getContainerLogs(container: string, lines: number = 100): string {
  try {
    return execSync(
      `docker logs ${container} --tail ${lines} 2>&1`,
      { encoding: 'utf8', timeout: 10000 }
    )
  } catch (error: any) {
    return `Error getting logs: ${error.message}`
  }
}

/**
 * Execute a command inside a container.
 */
export function execInContainer(container: string, command: string, timeout: number = 30000): string {
  return execSync(
    `docker exec ${container} ${command}`,
    { encoding: 'utf8', timeout }
  )
}
