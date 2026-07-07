import type { ServiceConfig, ServiceState } from './service'

/**
 * Health provider interface - abstracts how we check service health.
 *
 * This allows the status page to work in different environments:
 * - Local dev: DockerSocketProvider (full control via Docker socket)
 * - Production: HttpHealthProvider (read-only, polls /health endpoints)
 * - Central dashboard: ComposedProvider (aggregates multiple sources)
 */
export interface HealthProvider {
  /** Get status of a single service */
  getServiceStatus(service: ServiceConfig): Promise<ServiceState>

  /** Get status of all services */
  getAllServiceStatus(services: ServiceConfig[]): Promise<Map<string, ServiceState>>

  /** Get CPU stats for all containers */
  getContainerStats(): Promise<Map<string, number>>

  /** Can this provider manage containers (restart/rebuild)? */
  canManageContainers(): boolean

  /** Can this provider execute tests? */
  canExecuteTests(): boolean

  /** Restart a container (if supported) */
  restartContainer?(containerId: string): Promise<void>

  /** Rebuild a container (if supported) */
  rebuildContainer?(containerId: string): Promise<void>

  /** Get container logs */
  getContainerLogs?(containerId: string, lines?: number): Promise<string>
}

/**
 * Provider type identifier
 */
export type ProviderType = 'docker-socket' | 'http' | 'composed'

/**
 * Provider configuration
 */
export interface ProviderConfig {
  type: ProviderType
  // For docker-socket provider
  dockerHost?: string
  // For http provider
  healthEndpoints?: Map<string, string>
  // For composed provider
  providers?: Map<string, ProviderConfig>
}
