import { defineStore } from 'pinia'
import { api } from '~/api'
import type { ServiceState, ServiceStatus } from '~/types/service'

interface LiveV2State {
  freegle: boolean
  modtools: boolean
  apiv2LiveRunning: boolean
  liveDbPort: string
}

interface StatusStoreState {
  serviceStates: Map<string, ServiceState>
  cpuStats: Map<string, number>
  isLoading: boolean
  lastUpdated: Date | null
  error: string | null
  liveV2: LiveV2State
  project: string
  branch: string
}

export const useStatusStore = defineStore('status', {
  state: (): StatusStoreState => ({
    serviceStates: new Map(),
    cpuStats: new Map(),
    isLoading: false,
    lastUpdated: null,
    error: null,
    liveV2: { freegle: false, modtools: false, apiv2LiveRunning: false, liveDbPort: '1234' },
    project: '',
    branch: '',
  }),

  getters: {
    getServiceState: (state) => (serviceId: string): ServiceState | undefined => {
      return state.serviceStates.get(serviceId)
    },

    getServiceStatus: (state) => (serviceId: string): ServiceStatus => {
      return state.serviceStates.get(serviceId)?.status || 'unknown'
    },

    getCpuUsage: (state) => (serviceId: string): number | undefined => {
      return state.cpuStats.get(serviceId)
    },

    onlineCount: (state): number => {
      let count = 0
      for (const s of state.serviceStates.values()) {
        if (s.status === 'online') count++
      }
      return count
    },

    offlineCount: (state): number => {
      let count = 0
      for (const s of state.serviceStates.values()) {
        if (s.status === 'offline') count++
      }
      return count
    },

    totalCount: (state): number => {
      return state.serviceStates.size
    },
  },

  actions: {
    async refreshStatus() {
      this.isLoading = true
      this.error = null

      try {
        const response = await api().status.fetchStatus()

        // Update service states
        this.serviceStates.clear()
        for (const [id, data] of Object.entries(response.services || {})) {
          this.serviceStates.set(id, {
            id,
            status: data.status as ServiceStatus,
            message: data.message,
            uptime: data.uptime,
            lastChecked: new Date(),
          })
        }

        // Update CPU stats if available
        if (response.cpu) {
          this.cpuStats.clear()
          for (const [id, cpu] of Object.entries(response.cpu)) {
            this.cpuStats.set(id, cpu as number)
          }
        }

        this.project = response.project || ''
        this.branch = response.branch || ''
        this.lastUpdated = new Date()
      }
      catch (err) {
        this.error = err instanceof Error ? err.message : 'Failed to fetch status'
        console.error('Status refresh error:', err)
      }
      finally {
        this.isLoading = false
      }
    },

    async restartContainer(containerId: string) {
      try {
        await api().status.restartContainer(containerId)
        // Refresh status after restart
        setTimeout(() => this.refreshStatus(), 2000)
      }
      catch (err) {
        console.error('Restart error:', err)
        throw err
      }
    },

    async rebuildContainer(containerId: string, serviceId?: string) {
      try {
        await api().status.rebuildContainer(containerId, serviceId)
        // Refresh status periodically while rebuild is in progress
        setTimeout(() => this.refreshStatus(), 30000)
      }
      catch (err) {
        console.error('Rebuild error:', err)
        throw err
      }
    },

    async startLive() {
      try {
        await api().status.startLive()
        setTimeout(() => this.refreshStatus(), 5000)
      }
      catch (err) {
        console.error('Start live error:', err)
        throw err
      }
    },

    async startModToolsLive() {
      try {
        await api().status.startModToolsLive()
        setTimeout(() => this.refreshStatus(), 5000)
      }
      catch (err) {
        console.error('Start ModTools live error:', err)
        throw err
      }
    },

    async refreshLiveV2Status() {
      try {
        this.liveV2 = await api().status.getLiveV2Status()
      }
      catch (err) {
        console.error('Live V2 status error:', err)
      }
    },

    async toggleLiveV2(target: 'freegle' | 'modtools', enable: boolean) {
      try {
        await api().status.toggleLiveV2(target, enable)
        // Refresh both live V2 status and container status
        await this.refreshLiveV2Status()
        setTimeout(() => this.refreshStatus(), 5000)
      }
      catch (err) {
        console.error('Toggle live V2 error:', err)
        throw err
      }
    },
  },
})
