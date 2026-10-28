package utils

import (
	"strings"
	"testing"
)

func TestReplyHeldByQuestionSQL(t *testing.T) {
	for _, alias := range []string{"m", "cm", "chat_messages"} {
		sql := ReplyHeldByQuestionSQL(alias)

		if strings.Contains(sql, "?") {
			t.Errorf("%s: must have no placeholders: %s", alias, sql)
		}
		if strings.Count(sql, "(") != strings.Count(sql, ")") {
			t.Errorf("%s: unbalanced brackets: %s", alias, sql)
		}
		if strings.Count(sql, "EXISTS") != 2 {
			t.Errorf("%s: want two EXISTS clauses: %s", alias, sql)
		}
		if strings.Count(sql, "kind = '"+PROMPT_KIND_CONFIRM_REPLY+"'") != 2 {
			t.Errorf("%s: both clauses must filter on the confirm_reply kind: %s", alias, sql)
		}
		for _, ref := range []string{alias + ".id", alias + ".userid", alias + ".chatid", alias + ".date"} {
			if !strings.Contains(sql, ref) {
				t.Errorf("%s: missing reference %s", alias, ref)
			}
		}
		if !strings.Contains(sql, "(cpr.answered_at IS NULL OR cpr.answer <> 'yes')") {
			t.Errorf("%s: a yes must release the held messages", alias)
		}
		if !strings.Contains(sql, "INTERVAL 2 SECOND") {
			t.Errorf("%s: missing rounding slack", alias)
		}
	}
}
