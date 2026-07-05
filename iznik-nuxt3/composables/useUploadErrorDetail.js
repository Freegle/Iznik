// Extract diagnosable detail from an Uppy/tus upload failure.
//
// `uppy.on('error')` gives us only `error.message` ("Failed to upload <name>"),
// which can't tell a tusd size-reject (413) from an apiv1 post-finish hook 500
// from a plain network timeout — the three look identical in Loki. The richer
// signal lives on the `upload-error` event's (file, error, response) and on the
// underlying tus-js-client error (originalResponse.getStatus()/getBody(),
// causingError). Shapes vary across Uppy/tus versions, so dig defensively.
export function describeUploadError(error, file, response) {
  const originalResponse = error?.originalResponse

  // HTTP status of the failed request, if the server answered at all.
  const status =
    response?.status ??
    (typeof originalResponse?.getStatus === 'function'
      ? originalResponse.getStatus()
      : undefined) ??
    error?.status ??
    null

  // The wrapped root cause, if tus attached one.
  const cause =
    error?.cause?.message ??
    error?.causingError?.message ??
    (typeof error?.cause === 'string' ? error.cause : null)

  // Network error = the request never got an HTTP response (offline, DNS, TLS,
  // CORS, connection reset) — distinct from the server rejecting it. No status
  // + a fetch-style message is the tell.
  const isNetworkError =
    error?.isNetworkError === true ||
    (status == null &&
      /network|failed to fetch|load failed|connection|timed? ?out/i.test(
        error?.message || ''
      ))

  // A snippet of the server body often carries the real reason (e.g. the apiv1
  // hook's error). Cap it so we never ship a large/PII-laden blob to Loki.
  let body =
    response?.body ??
    (typeof originalResponse?.getBody === 'function'
      ? originalResponse.getBody()
      : undefined)
  body = typeof body === 'string' && body ? body.slice(0, 200) : null

  return {
    reason: error?.message ?? null,
    status,
    is_network_error: isNetworkError,
    cause: cause ?? null,
    response_body: body,
    file_size: file?.size ?? null,
    file_type: file?.type ?? null,
  }
}
