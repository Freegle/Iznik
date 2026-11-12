package chat

import (
	"strings"
	"testing"
	"unicode/utf8"

	"github.com/freegle/iznik-server-go/utils"
)

// The snippet in the chat list is at most 100 characters and is always valid text. Cutting it at byte 100 splits a
// multi-byte character that straddles the cut, and the member reads a replacement character at the end.
func TestSnippetIsCutOnACharacterBoundary(t *testing.T) {
	long := map[string]string{
		"accented letter": strings.Repeat("a", 99) + "é" + strings.Repeat("b", 20),
		"emoji":           strings.Repeat("a", 98) + "😀" + strings.Repeat("b", 20),
		"non-latin text":  strings.Repeat("日本語", 60),
	}

	kinds := map[string]func(string) string{
		"default message":   func(m string) string { return getSnippet(utils.CHAT_MESSAGE_DEFAULT, m, "") },
		"completed message": func(m string) string { return getSnippet(utils.CHAT_MESSAGE_COMPLETED, m, "") },
		"interested reply":  func(m string) string { return getSnippet(utils.CHAT_MESSAGE_INTERESTED, m, "") },
		"report comment": func(m string) string {
			return reportSnippet("Reported post.\n" + reportAdditionalDetailsMarker + m + "\"")
		},
	}

	for kind, snippet := range kinds {
		for name, message := range long {
			got := snippet(message)
			if !utf8.ValidString(got) {
				t.Errorf("%s with %s: the snippet %q is not valid text", kind, name, got)
			}
			if n := utf8.RuneCountInString(strings.TrimPrefix(got, "Reported: ")); n > 100 {
				t.Errorf("%s with %s: the snippet has %d characters, more than 100", kind, name, n)
			}
		}
	}
}
