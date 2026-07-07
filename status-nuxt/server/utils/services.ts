import type { ServiceConfig } from '~/types/service'

/**
 * Get the container name prefix from COMPOSE_PROJECT_NAME.
 * Defaults to 'freegle' for backward compatibility.
 */
function prefix(): string {
  return process.env.COMPOSE_PROJECT_NAME || 'freegle'
}

/**
 * Build a container name using the project prefix.
 * e.g. cn('traefik') => 'freegle-traefik'
 */
function cn(suffix: string): string {
  return `${prefix()}-${suffix}`
}

/**
 * Service definitions for all monitored services.
 * Container names are derived from COMPOSE_PROJECT_NAME env var.
 */
export const services: ServiceConfig[] = [
  // ============================================
  // Freegle Components
  // ============================================
  {
    id: 'freegle-dev-local',
    name: 'Freegle Dev (Local)',
    category: 'freegle',
    container: cn('dev-local'),
    url: 'http://freegle-dev-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Development mode with hot reload, local APIs',
  },
  {
    id: 'freegle-dev-live',
    name: 'Freegle Dev (Live)',
    category: 'production',
    container: cn('dev-live'),
    url: 'http://freegle-dev-live.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Development mode with PRODUCTION APIs - use with caution',
    production: true,
  },
  {
    id: 'freegle-prod-local',
    name: 'Freegle Prod (Local)',
    category: 'freegle',
    container: cn('prod-local'),
    url: 'http://freegle-prod-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Production build with local APIs',
  },

  // ============================================
  // ModTools Components
  // ============================================
  {
    id: 'modtools-dev-local',
    name: 'ModTools Dev (Local)',
    category: 'modtools',
    container: cn('modtools-dev-local'),
    url: 'http://modtools-dev-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Development mode with hot reload, local APIs',
  },
  {
    id: 'modtools-dev-live',
    name: 'ModTools Dev (Live)',
    category: 'production',
    container: cn('modtools-dev-live'),
    url: 'http://modtools-dev-live.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Development mode with PRODUCTION APIs - use with caution',
    production: true,
  },
  {
    id: 'apiv2-live',
    name: 'API v2 (Live DB)',
    category: 'production',
    container: cn('apiv2-live'),
    url: 'http://apiv2-live.localhost:8192/api/online',
    healthCheck: { type: 'http', path: '/api/online', timeout: 3000 },
    actions: ['restart', 'rebuild', 'logs'],
    description: 'Go API connected to production database via tunnel',
    production: true,
  },
  {
    id: 'modtools-prod-local',
    name: 'ModTools Prod (Local)',
    category: 'modtools',
    container: cn('modtools-prod-local'),
    url: 'http://modtools-prod-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Production build with local APIs',
  },

  // ============================================
  // Backend Services
  // ============================================
  {
    id: 'apiv1',
    name: 'API v1 (PHP)',
    category: 'backend',
    container: cn('apiv1'),
    url: 'http://apiv1.localhost/api/config',
    healthCheck: { type: 'http', path: '/api/config', timeout: 5000 },
    actions: ['restart', 'logs'],
    description: 'PHP API (iznik-server)',
  },
  {
    id: 'apiv2',
    name: 'API v2 (Go)',
    category: 'backend',
    container: cn('apiv2'),
    url: 'http://apiv2.localhost:8192/api/online',
    healthCheck: { type: 'http', path: '/api/online', timeout: 3000 },
    actions: ['restart', 'rebuild', 'logs'],
    description: 'Go API (iznik-server-go)',
  },
  {
    id: 'batch',
    name: 'Batch Processor',
    category: 'backend',
    container: cn('batch'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Laravel batch jobs (iznik-batch)',
  },

  // ============================================
  // MCP Tools (Privacy-preserving log analysis)
  // ============================================
  {
    id: 'mcp-sanitizer',
    name: 'MCP Sanitizer',
    category: 'mcp',
    container: cn('mcp-sanitizer'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Log sanitization service',
  },
  {
    id: 'mcp-interface',
    name: 'MCP Interface',
    category: 'mcp',
    container: cn('mcp-interface'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'MCP interface service',
  },
  {
    id: 'mcp-pseudonymizer',
    name: 'MCP Pseudonymizer',
    category: 'mcp',
    container: cn('mcp-pseudonymizer'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Data pseudonymization service',
  },

  // ============================================
  // Development Tools
  // ============================================
  {
    id: 'phpmyadmin',
    name: 'phpMyAdmin',
    category: 'devtools',
    container: cn('phpmyadmin'),
    url: 'http://phpmyadmin.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Database management interface',
    credentials: { username: 'root', password: 'iznik' },
  },
  {
    id: 'mailpit',
    name: 'Mailpit',
    category: 'devtools',
    container: cn('mailpit'),
    url: 'http://mailpit.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Email testing interface',
  },
  {
    id: 'playwright',
    name: 'Playwright',
    category: 'devtools',
    container: cn('playwright'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'E2E test runner container',
  },

  // ============================================
  // Infrastructure
  // ============================================
  {
    id: 'percona',
    name: 'Percona MySQL',
    category: 'infrastructure',
    container: cn('percona'),
    healthCheck: { type: 'command', command: 'mysqladmin ping -h localhost', timeout: 5000 },
    actions: ['restart', 'logs'],
    description: 'MySQL database server',
    credentials: { username: 'root', password: 'iznik' },
  },
  {
    id: 'postgres',
    name: 'PostgreSQL',
    category: 'infrastructure',
    container: cn('postgres'),
    healthCheck: { type: 'command', command: 'pg_isready', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'PostgreSQL database (for Loki)',
  },
  {
    id: 'redis',
    name: 'Redis',
    category: 'infrastructure',
    container: cn('redis'),
    healthCheck: { type: 'command', command: 'redis-cli ping', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Cache and session storage',
  },
  {
    id: 'beanstalkd',
    name: 'Beanstalkd',
    category: 'infrastructure',
    container: cn('beanstalkd'),
    healthCheck: { type: 'tcp', port: 11300, timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Job queue server',
  },
  {
    id: 'spamassassin',
    name: 'SpamAssassin',
    category: 'infrastructure',
    container: cn('spamassassin'),
    healthCheck: { type: 'tcp', port: 783, timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Email spam filtering',
  },
  {
    id: 'traefik',
    name: 'Traefik',
    category: 'infrastructure',
    container: cn('traefik'),
    url: 'http://traefik.localhost/dashboard/',
    healthCheck: { type: 'http', path: '/dashboard/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Reverse proxy and load balancer',
  },
  {
    id: 'tusd',
    name: 'tusd',
    category: 'infrastructure',
    container: cn('tusd'),
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Resumable file upload server',
  },
  {
    id: 'delivery',
    name: 'Image Delivery',
    category: 'infrastructure',
    container: cn('delivery'),
    url: 'http://delivery.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Image transformation service (weserv)',
  },
  {
    id: 'loki',
    name: 'Loki',
    category: 'infrastructure',
    container: cn('loki'),
    url: 'http://loki.localhost/ready',
    healthCheck: { type: 'http', path: '/ready', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Log aggregation service',
  },
  {
    id: 'mjml',
    name: 'MJML Server',
    category: 'infrastructure',
    container: cn('mjml'),
    healthCheck: { type: 'http', path: '/health', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Email template compilation server',
  },
  {
    id: 'loki-backup',
    name: 'Loki Backup',
    category: 'infrastructure',
    container: cn('loki-backup'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['logs'],
    description: 'On-demand backup: docker compose --profile backup run --rm loki-backup',
    profile: 'backup',
  },

  // ============================================
  // Support Services
  // ============================================
  {
    id: 'host-scripts',
    name: 'Host Scripts',
    category: 'backend',
    container: cn('host-scripts'),
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'File sync and rebuild handler',
  },
  {
    id: 'status',
    name: 'Status (Current)',
    category: 'devtools',
    container: cn('status'),
    url: 'http://status.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'This status page (being replaced)',
  },
]

/**
 * Get services by category
 */
export function getServicesByCategory(category: string): ServiceConfig[] {
  return services.filter(s => s.category === category)
}

/**
 * Get a service by ID
 */
export function getServiceById(id: string): ServiceConfig | undefined {
  return services.find(s => s.id === id)
}

/**
 * Get services that have a specific action
 */
export function getServicesWithAction(action: string): ServiceConfig[] {
  return services.filter(s => s.actions.includes(action as any))
}
