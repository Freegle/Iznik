export type ServiceCategory =
  | 'freegle'
  | 'modtools'
  | 'backend'
  | 'devtools'
  | 'infrastructure'
  | 'mcp'
  | 'production'

export type ServiceStatus = 'online' | 'offline' | 'loading' | 'unknown'

export type ServiceAction = 'restart' | 'rebuild' | 'visit' | 'logs'

export interface HealthCheckConfig {
  type: 'http' | 'tcp' | 'docker' | 'command'
  path?: string // For HTTP checks
  port?: number // For TCP checks
  command?: string // For command checks
  timeout?: number // Milliseconds
}

export interface ServiceCredentials {
  username: string
  password: string
}

export interface ServiceConfig {
  id: string
  name: string
  category: ServiceCategory
  container?: string // Docker container name (if applicable)
  url?: string // Access URL
  healthCheck: HealthCheckConfig
  actions: ServiceAction[]
  description: string
  credentials?: ServiceCredentials
  production?: boolean // Is this a production service?
}

export interface ServiceState {
  id: string
  status: ServiceStatus
  cpu?: number
  memory?: number
  message?: string
  uptime?: string
  lastChecked: Date
}

// Combined service info for UI
export interface ServiceInfo extends ServiceConfig {
  state: ServiceState
}
