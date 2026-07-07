import { defineStore } from 'pinia'
import api from '~/api'

export const useSystemConfigStore = defineStore({
  id: 'systemconfig',
  state: () => ({
    concern_keywords: [],
    loading: false,
    error: null,
  }),
  actions: {
    init(config) {
      this.config = config
    },

    async fetchConcernKeywords(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await api(this.config).config.fetchConcernKeywordsv2(params)
        this.concern_keywords = response || []
      } catch (error) {
        this.error = error.message
        this.concern_keywords = []
      } finally {
        this.loading = false
      }
    },

    async addConcernKeyword(data) {
      if (!data.keyword || !data.keyword.trim()) return
      this.loading = true
      this.error = null
      try {
        await api(this.config).config.addConcernKeywordv2(data)
        await this.fetchConcernKeywords()
      } catch (error) {
        this.error = error.message
      } finally {
        this.loading = false
      }
    },

    async deleteConcernKeyword(id) {
      this.loading = true
      this.error = null
      try {
        await api(this.config).config.deleteConcernKeywordv2(id)
        this.concern_keywords = this.concern_keywords.filter((k) => k.id !== id)
      } catch (error) {
        this.error = error.message
      } finally {
        this.loading = false
      }
    },
  },
  getters: {
    getConcernKeywords: (state) => state.concern_keywords,
    isLoading: (state) => state.loading,
    hasError: (state) => !!state.error,
    getError: (state) => state.error,
  },
})
