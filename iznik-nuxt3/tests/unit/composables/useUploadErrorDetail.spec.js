import { describe, it, expect } from 'vitest'
import { describeUploadError } from '~/composables/useUploadErrorDetail'

describe('describeUploadError', () => {
  it('pulls the HTTP status from the upload-error response', () => {
    const d = describeUploadError(
      new Error('Failed to upload big.jpg'),
      { size: 5900000, type: 'image/jpeg' },
      { status: 413, body: 'Request Entity Too Large' }
    )
    expect(d).toMatchObject({
      reason: 'Failed to upload big.jpg',
      status: 413,
      is_network_error: false,
      file_size: 5900000,
      file_type: 'image/jpeg',
      response_body: 'Request Entity Too Large',
    })
  })

  it('reads status/body from a tus-js-client originalResponse getter', () => {
    const err = new Error('tus: unexpected response')
    err.originalResponse = {
      getStatus: () => 500,
      getBody: () => 'apiv1 hook failed',
    }
    const d = describeUploadError(err, { size: 100, type: 'image/png' })
    expect(d.status).toBe(500)
    expect(d.response_body).toBe('apiv1 hook failed')
    expect(d.is_network_error).toBe(false)
  })

  it('flags a network error when there is no status and the message looks network-ish', () => {
    const d = describeUploadError(new Error('Failed to fetch'), {
      size: 120000,
    })
    expect(d.status).toBeNull()
    expect(d.is_network_error).toBe(true)
  })

  it('honours an explicit isNetworkError flag even with a message', () => {
    const err = new Error('Upload errored')
    err.isNetworkError = true
    expect(describeUploadError(err, {}).is_network_error).toBe(true)
  })

  it('surfaces a wrapped causingError message', () => {
    const err = new Error('Failed to upload x.jpg')
    err.causingError = new Error('net::ERR_CONNECTION_RESET')
    expect(describeUploadError(err, {}).cause).toBe(
      'net::ERR_CONNECTION_RESET'
    )
  })

  it('caps an oversized response body to 200 chars', () => {
    const d = describeUploadError(new Error('x'), {}, {
      status: 502,
      body: 'e'.repeat(5000),
    })
    expect(d.response_body.length).toBe(200)
  })

  it('is null-safe when file/response are missing', () => {
    const d = describeUploadError(new Error('boom'))
    expect(d).toMatchObject({
      reason: 'boom',
      status: null,
      file_size: null,
      file_type: null,
      response_body: null,
      cause: null,
    })
  })
})
