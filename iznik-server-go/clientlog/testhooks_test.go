package clientlog

import "github.com/freegle/iznik-server-go/misc"

// useLoki points the handler at a specific client (nil restores the singleton). Test hook.
func useLoki(l *misc.LokiClient) { lokiOverride = l }

// waitForHandlers blocks until the async writers started by the handler have finished. Test hook.
func waitForHandlers() { inflight.Wait() }
