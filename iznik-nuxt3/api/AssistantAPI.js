import BaseAPI from '@/api/BaseAPI'
import { useAuthStore } from '~/stores/auth'

export const ANON_KEY = 'freegle-assistant-anon'

// The Freegle chat assistant answers with server-sent events so the words arrive as
// they are composed. fetch with a streamed body is used rather than EventSource, which
// cannot POST or send headers.
export default class AssistantAPI extends BaseAPI {
  anonToken() {
    try {
      return localStorage.getItem(ANON_KEY) || ''
    } catch (e) {
      return ''
    }
  }

  setAnonToken(token) {
    try {
      if (token) localStorage.setItem(ANON_KEY, token)
    } catch (e) {
      // ignore
    }
  }

  // On sign-out: the next person on this device starts as a stranger.
  clearAnonToken() {
    try {
      localStorage.removeItem(ANON_KEY)
    } catch (e) {
      // ignore
    }
  }

  headers() {
    const headers = { 'Content-Type': 'application/json' }
    try {
      const authStore = useAuthStore()
      if (authStore?.auth?.jwt)
        headers.Authorization = JSON.stringify(authStore.auth.jwt)
      if (authStore?.auth?.persistent)
        headers.Authorization2 = JSON.stringify(authStore.auth.persistent)
    } catch (e) {
      // no store on the server
    }
    const anon = this.anonToken()
    if (anon) headers['X-Assistant-Anon'] = anon
    return headers
  }

  // One turn. body: { conversation?, text?, tap?, event? }. onDelta gets fragments of
  // Freegle's reply. Resolves to the turn record. A stream that breaks before the turn
  // arrives (a phone changing network, a proxy giving up) is followed once by a resume,
  // which asks where things stand without saying or moving anything.
  async turn(body, { onDelta, signal, resumed } = {}) {
    try {
      return await this.stream(body, { onDelta, signal })
    } catch (e) {
      const conversation = body?.conversation
      if (resumed || !conversation || signal?.aborted || !e?.streamBroke)
        throw e
      return this.turn(
        { conversation, event: { type: 'resume' } },
        { onDelta, signal, resumed: true }
      )
    }
  }

  async stream(body, { onDelta, signal } = {}) {
    const res = await fetch(`${this.config.public.APIv2}/assistant/turn`, {
      method: 'POST',
      headers: this.headers(),
      body: JSON.stringify(body || {}),
      signal,
    })
    const anon = res.headers.get('X-Assistant-Anon')
    if (anon) this.setAnonToken(anon)
    if (!res.ok || !res.body) {
      throw new Error(`assistant: ${res.status}`)
    }
    const reader = res.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let turn = null
    let error = null
    const handle = (event, data) => {
      if (event === 'delta' && onDelta && data?.text) onDelta(data.text)
      else if (event === 'identity' && data?.anon) this.setAnonToken(data.anon)
      else if (event === 'turn') turn = data
      else if (event === 'error') error = data?.message || 'error'
    }

    try {
      while (true) {
        const { value, done } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })
        let idx
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
          const chunk = buffer.slice(0, idx)
          buffer = buffer.slice(idx + 2)
          parseSse(chunk, handle)
        }
      }
    } catch (e) {
      const broke = new Error('assistant: stream broke')
      broke.streamBroke = true
      throw broke
    }
    if (buffer.trim()) parseSse(buffer, handle)
    if (error) throw new Error(error)
    if (!turn) {
      const broke = new Error('assistant: no turn')
      broke.streamBroke = true
      throw broke
    }
    return turn
  }

  workflow() {
    return this.$getv2('/assistant/workflow')
  }

  widgets(ids) {
    return this.$getv2('/assistant/widgets', { ids: ids.join(',') })
  }
}

export function parseSse(chunk, handle) {
  let event = 'message'
  const dataLines = []
  for (const line of chunk.split('\n')) {
    if (line.startsWith('event:')) event = line.slice(6).trim()
    else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim())
  }
  if (!dataLines.length) return
  let data
  try {
    data = JSON.parse(dataLines.join('\n'))
  } catch (e) {
    data = { text: dataLines.join('\n') }
  }
  handle(event, data)
}
