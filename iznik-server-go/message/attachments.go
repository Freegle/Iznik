package message

import (
	"bytes"
	"encoding/json"
	"strconv"
)

// AttachmentIDs is a list of server-side attachment ids parsed tolerantly from a
// JSON array.
//
// Pre-#650 mobile builds posted a synthetic "ai-<ms>" string id for an AI
// illustration that had not yet been materialised server-side. Because the request
// structs declared []uint64, fiber's BodyParser rejected the whole body with HTTP
// 400 ("Invalid request body"), trapping members at the final "Freegle it" step
// (Sentry CAPACITOR-44Z). #650 fixed the client, but old published app builds keep
// sending the string, so the server must tolerate it.
//
// UnmarshalJSON accepts a mixed-type array, keeps numeric ids (JSON numbers and plain
// numeric strings) and silently drops anything that is not a plain id — e.g. the
// "ai-..." strings, objects, booleans and null elements. A JSON null or a
// non-array value yields a nil slice (fail open) rather than an error.
type AttachmentIDs []uint64

// UnmarshalJSON implements json.Unmarshaler. See the type doc for the tolerance rules.
//
// nil vs empty matters to callers: patchMessage treats nil as "field absent — don't
// touch attachments" and [] as "remove all attachments" (#338). We preserve that:
// JSON null and a non-array value give nil; an empty array gives an empty, non-nil
// slice.
func (a *AttachmentIDs) UnmarshalJSON(data []byte) error {
	// Use a number-preserving decoder so large ids beyond float64's exact-integer
	// range survive intact.
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()

	var raw []any
	if err := dec.Decode(&raw); err != nil {
		// Not a JSON array (e.g. a stray string/number/object). Fail open rather
		// than 400 the whole request: treat as "no attachments specified".
		*a = nil
		return nil
	}
	if raw == nil {
		// JSON null — preserve nil (see method doc).
		*a = nil
		return nil
	}

	out := make(AttachmentIDs, 0, len(raw))
	for _, v := range raw {
		switch n := v.(type) {
		case json.Number:
			if id, err := strconv.ParseUint(n.String(), 10, 64); err == nil {
				out = append(out, id)
			}
		case string:
			// Keep plain numeric strings; drop synthetic ids like "ai-1749...".
			if id, err := strconv.ParseUint(n, 10, 64); err == nil {
				out = append(out, id)
			}
		}
		// Any other element type (object, bool, nested array, null) is ignored.
	}
	*a = out
	return nil
}
