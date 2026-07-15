import BaseAPI from '@/api/BaseAPI'

// VoicePostAPI drives the streaming voice-post flow. Audio is streamed to the
// server in small chunks while the user is still talking (chunk), then finalised
// once they stop (finish). See iznik-server-go/voicepost.
export default class VoicePostAPI extends BaseAPI {
  // Send one chunk of recorded audio (a Blob). On the first call omit `session`
  // and the server allocates one, returned in the response. Returns
  // { session, transcript } where transcript is the best transcript so far.
  async chunk(blob, session) {
    const path =
      '/voicepost/chunk' +
      (session ? '?session=' + encodeURIComponent(session) : '')
    return await this.$postFormv2(path, blob)
  }

  // Finalise the post once the user stops talking. Does a final full
  // transcription and a tidy-up pass. Returns { id, transcript, title, description }.
  async finish(session) {
    return await this.$postv2(
      '/voicepost/finish?session=' + encodeURIComponent(session),
      {}
    )
  }
}
