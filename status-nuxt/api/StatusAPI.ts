import BaseAPI from './BaseAPI'
import type { ServiceStatus, TestStatus, ContainerInfo } from '~/types/service'

export default class StatusAPI extends BaseAPI {
  // Service status
  async fetchStatus(): Promise<{
    services: Record<string, { status: ServiceStatus; message?: string; uptime?: number }>
    cpu?: Record<string, number>
  }> {
    return await this.$get('/api/status')
  }

  async fetchCpu(): Promise<Record<string, number>> {
    return await this.$get('/api/cpu')
  }

  // Container operations
  async restartContainer(container: string): Promise<{ success: boolean }> {
    return await this.$post('/api/container/restart', { container })
  }

  async rebuildContainer(container: string, service?: string): Promise<{ success: boolean }> {
    return await this.$post('/api/container/rebuild', {
      container,
      service: service || container,
    })
  }

  async startLive(): Promise<{ success: boolean }> {
    return await this.$post('/api/container/start-live')
  }

  async startModToolsLive(): Promise<{ success: boolean }> {
    return await this.$post('/api/container/start-modtools-live')
  }

  async toggleLiveV2(target: 'freegle' | 'modtools', enable: boolean): Promise<{ success: boolean; message: string }> {
    return await this.$post('/api/container/toggle-live-v2', { target, enable })
  }

  async getLiveV2Status(): Promise<{ freegle: boolean; modtools: boolean; apiv2LiveRunning: boolean; liveDbPort: string }> {
    return await this.$get('/api/container/live-v2-status')
  }

  // Test operations
  async startGoTests(withCoverage?: boolean): Promise<{ status: string }> {
    return await this.$post('/api/tests/go', { coverage: withCoverage })
  }

  async getGoTestStatus(): Promise<TestStatus> {
    return await this.$get('/api/tests/go/status')
  }

  async startPhpTests(withCoverage?: boolean): Promise<{ status: string }> {
    return await this.$post('/api/tests/php', { coverage: withCoverage })
  }

  async getPhpTestStatus(): Promise<TestStatus> {
    return await this.$get('/api/tests/php/status')
  }

  async startLaravelTests(withCoverage?: boolean): Promise<{ status: string }> {
    return await this.$post('/api/tests/laravel', { coverage: withCoverage })
  }

  async getLaravelTestStatus(): Promise<TestStatus> {
    return await this.$get('/api/tests/laravel/status')
  }

  async startPlaywrightTests(): Promise<{ status: string }> {
    return await this.$post('/api/tests/playwright')
  }

  async getPlaywrightTestStatus(): Promise<TestStatus> {
    return await this.$get('/api/tests/playwright/status')
  }

  async getPlaywrightReport(): Promise<string> {
    return await this.$get('/api/tests/playwright/report')
  }

  // Container logs
  async fetchLogs(container: string, lines: number = 100): Promise<{ logs: string }> {
    return await this.$get('/api/logs', { container, lines })
  }

  // Utility operations
  async recreateTestUsers(): Promise<{ success: boolean }> {
    return await this.$post('/api/utility/recreate-test-users')
  }

  async devConnectTest(): Promise<{ success: boolean; message?: string }> {
    return await this.$get('/api/utility/dev-connect-test')
  }
}
