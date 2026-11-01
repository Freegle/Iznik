package dashboard

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"sort"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// popularPost is one candidate for the Popular Posts list.
type popularPost struct {
	Views   int
	ID      uint64
	Subject string
}

// popularStoredCandidates is how many of a range's most-viewed stored posts are checked against
// messages (still there, not deleted, not since reposted out of the range) before the top 5 are
// taken. Far more than the few that a deletion or repost could knock out of the top 5.
const popularStoredCandidates = 200

// getPopularPosts lists the five most-viewed posts that arrived in the range.
//
// Any failed query, including the dashboardDeadline running out, gives an empty list rather than
// the top five of whatever was counted before it stopped. A partial answer looks complete, and the
// cache would keep it for half an hour; an empty one is kept for componentNegativeTTL.
func getPopularPosts(groupIDs []uint64, startQ, endQ string, systemwide bool) []map[string]interface{} {
	empty := []map[string]interface{}{}
	if len(groupIDs) == 0 {
		return empty
	}
	ctx, cancel := context.WithTimeout(context.Background(), dashboardDeadline)
	defer cancel()
	db := database.DBConn.WithContext(ctx)

	var posts []popularPost
	var err error
	if systemwide {
		posts, err = popularSystemwide(db, systemwideStart(startQ, endQ), endQ)
	} else {
		posts, err = popularInGroups(db, groupIDs, startQ, endQ)
	}
	if err != nil {
		return empty
	}
	posts = topPopular(posts, 5)

	userSite := os.Getenv("USER_SITE")
	if userSite == "" {
		userSite = "www.ilovefreegle.org"
	}

	result := make([]map[string]interface{}, len(posts))
	for i, p := range posts {
		var replies int
		if err := db.Table("chat_messages").Select("COUNT(*)").Where("refmsgid = ?", p.ID).Scan(&replies).Error; err != nil {
			return empty
		}

		result[i] = map[string]interface{}{
			"views":   p.Views,
			"id":      p.ID,
			"subject": p.Subject,
			"replies": replies,
			"url":     fmt.Sprintf("https://%s/message/%d", userSite, p.ID),
		}
	}
	return result
}

// systemwideStart keeps the network-wide list's historical cap: at most the 90 days before the end.
func systemwideStart(startQ, endQ string) string {
	start, err1 := time.Parse(dayLayout, startQ)
	end, err2 := time.Parse(dayLayout, endQ)
	if err1 != nil || err2 != nil {
		return startQ
	}
	maxWindow := end.AddDate(0, 0, -90)
	if start.Before(maxWindow) {
		return maxWindow.Format(dayLayout)
	}
	return startQ
}

const dayLayout = "2006-01-02"

// popularSpan is one part of a date range and where its view counts come from.
type popularSpan struct {
	From, To  string // days, "2006-01-02"
	Inclusive bool   // arrival <= To rather than < To
	Stored    bool   // read messages_viewcounts rather than counting messages_likes
}

// popularSpans splits [startQ, endQ] by where its view counts can come from.
//
// messages:count-views stores a count for every viewed post from coveredFrom (the oldest day it
// has counted) up to the start of the day it last ran (tailDay). Before coveredFrom, and from
// tailDay on, the counts are made live from messages_likes. Both bounds are whole days, which is how
// the batch walks, so the spans meet without a gap or an overlap. An empty coveredFrom means
// nothing has been stored yet, and the whole range is counted live as before.
func popularSpans(startQ, endQ, coveredFrom, tailDay string) []popularSpan {
	if coveredFrom == "" || tailDay == "" {
		return []popularSpan{{From: startQ, To: endQ, Inclusive: true}}
	}
	clamp := func(d, lo, hi string) string {
		if d < lo {
			return lo
		}
		if d > hi {
			return hi
		}
		return d
	}
	lo := clamp(coveredFrom, startQ, endQ)
	hi := clamp(tailDay, lo, endQ)

	var spans []popularSpan
	if startQ < lo {
		spans = append(spans, popularSpan{From: startQ, To: lo, Inclusive: lo == endQ})
	}
	if lo < hi {
		spans = append(spans, popularSpan{From: lo, To: hi, Inclusive: hi == endQ, Stored: true})
	}
	if hi < endQ {
		spans = append(spans, popularSpan{From: hi, To: endQ, Inclusive: true})
	}
	return spans
}

// popularSystemwide ranks every post in the range, reading stored counts where messages:count-views
// has made them.
//
// Counting the whole network's 90 days live means reading ~63M messages_likes rows: 54s on
// production even as one index scan, so it never fitted inside dashboardDeadline (Sentry
// 7821578277). The stored part is one range read on messages_viewcounts' (arrival, views, msgid)
// index; the live part is normally just the day or so since the batch last ran.
func popularSystemwide(db *gorm.DB, startQ, endQ string) ([]popularPost, error) {
	var coveredFrom, tailDay sql.NullString
	if err := db.Table("messages_viewcounts").
		Select("DATE_FORMAT(MIN(arrival), '%Y-%m-%d'), DATE_FORMAT(MAX(counted_at), '%Y-%m-%d')").
		Row().Scan(&coveredFrom, &tailDay); err != nil {
		return nil, err
	}

	var posts []popularPost
	for _, span := range popularSpans(startQ, endQ, coveredFrom.String, tailDay.String) {
		var got []popularPost
		var err error
		if span.Stored {
			got, err = popularStored(db, span)
		} else {
			got, err = popularLiveSystemwide(db, span)
		}
		if err != nil {
			return nil, err
		}
		posts = append(posts, got...)
	}
	return posts, nil
}

func arrivalUpper(inclusive bool) string {
	if inclusive {
		return "<="
	}
	return "<"
}

// popularStored takes the span's most-viewed posts from messages_viewcounts. The inner query reads
// only the index; the join then drops posts deleted since they were counted, and any reposted out
// of the span (a repost moves arrival on, so it is counted live in the span it moved to).
func popularStored(db *gorm.DB, span popularSpan) ([]popularPost, error) {
	cmp := arrivalUpper(span.Inclusive)
	candidates := db.Table("messages_viewcounts").
		Select("msgid, views").
		Where("arrival >= ? AND arrival "+cmp+" ?", span.From, span.To).
		Order("views DESC").
		Limit(popularStoredCandidates)

	var posts []popularPost
	err := db.Table("(?) c", candidates).
		Select("c.views, c.msgid AS id, m.subject").
		Joins("INNER JOIN messages m ON m.id = c.msgid").
		Where("m.deleted IS NULL AND m.arrival >= ? AND m.arrival "+cmp+" ?", span.From, span.To).
		Order("c.views DESC").
		Limit(5).
		Scan(&posts).Error
	return posts, err
}

// popularLiveSystemwide counts views live over a span, a week at a time (see dashboardWindowDays).
// A post has exactly one arrival, so each window's top 5 is a superset candidate of the span's.
// The network-wide count skips the messages_groups join: every group is included.
func popularLiveSystemwide(db *gorm.DB, span popularSpan) ([]popularPost, error) {
	var posts []popularPost
	for _, win := range spanWindows(span) {
		var got []popularPost
		// Correlated subquery on messages_likes rather than a JOIN, to avoid scanning the
		// 138M-row table.
		err := db.Table("messages m").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = m.id AND type = ?) AS views, m.id, m.subject", utils.MESSAGE_LIKES_VIEW).
			Where("m.arrival >= ? AND m.arrival "+arrivalUpper(win.LastInclusive)+" ? AND m.deleted IS NULL", win.Start, win.End).
			Order("views DESC").
			Limit(5).
			Scan(&got).Error
		if err != nil {
			return nil, err
		}
		posts = append(posts, got...)
	}
	return posts, nil
}

// spanWindows is arrivalWindows over the span, with the last window's upper bound following the
// span's: a span that stops where the stored counts start must not count that day as well.
func spanWindows(span popularSpan) []arrivalWindow {
	wins := arrivalWindows(span.From, span.To)
	if len(wins) > 0 {
		wins[len(wins)-1].LastInclusive = span.Inclusive
	}
	return wins
}

// popularInGroups counts views live for the posts that originated in the given groups.
//
// rippled_in = 0 restricts to each post's ORIGIN group row. Rippling-out adds an Approved
// messages_groups row (rippled_in = 1) per group a post reaches, so without this filter a post
// rippled into several of a moderator's groups would appear once per group (duplicates under
// allgroups) and posts merely rippled INTO a group would pollute that group's own popular list.
// GROUP BY mg.msgid additionally collapses genuine multi-group (crossposted) origin rows so each
// post is listed once. A crossposted origin can straddle two windows and surface in both;
// topPopular dedups by id.
func popularInGroups(db *gorm.DB, groupIDs []uint64, startQ, endQ string) ([]popularPost, error) {
	var posts []popularPost
	for _, win := range arrivalWindows(startQ, endQ) {
		var got []popularPost
		err := db.Table("messages_groups mg").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = mg.msgid AND type = ?) AS views, mg.msgid AS id, MIN(m.subject) AS subject", utils.MESSAGE_LIKES_VIEW).
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.arrival >= ? AND mg.arrival "+arrivalUpper(win.LastInclusive)+" ? AND mg.groupid IN (?) AND mg.collection = ? AND mg.rippled_in = 0",
				win.Start, win.End, groupIDs, utils.COLLECTION_APPROVED).
			Group("mg.msgid").
			Order("views DESC").
			Limit(5).
			Scan(&got).Error
		if err != nil {
			return nil, err
		}
		posts = append(posts, got...)
	}
	return posts, nil
}

// topPopular merges per-window or per-span candidates into the overall top n: one entry per post,
// keeping its highest count (a live count is never lower than a stored one), most views first and
// id as a deterministic tie-break.
func topPopular(posts []popularPost, n int) []popularPost {
	best := make(map[uint64]popularPost, len(posts))
	for _, p := range posts {
		if cur, ok := best[p.ID]; !ok || p.Views > cur.Views {
			best[p.ID] = p
		}
	}
	merged := make([]popularPost, 0, len(best))
	for _, p := range best {
		merged = append(merged, p)
	}
	sort.Slice(merged, func(i, j int) bool {
		if merged[i].Views != merged[j].Views {
			return merged[i].Views > merged[j].Views
		}
		return merged[i].ID < merged[j].ID
	})
	if len(merged) > n {
		merged = merged[:n]
	}
	return merged
}
