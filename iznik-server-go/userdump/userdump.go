// Package userdump builds and streams a per-user SQLite database containing
// everything we hold about one user — all user-linked database tables, their
// Loki logs and their Sentry issues — for support/admin investigations. It is a
// pure data export; it does not interpret anything.
package userdump

import (
	"bufio"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/hex"
	"fmt"
	"io"
	"log"
	"math"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// section is one unit of collection work. weight feeds the ETA estimate (Loki
// and Sentry are weighted higher because they are slow external calls).
type section struct {
	name   string
	weight int
	run    func(b *Builder) (int, error)
}

// GetUserDump streams a SQLite dump of everything we hold about a user.
//
// @Summary Support data dump for a user
// @Description Streams a per-user SQLite database (all user-linked tables, Loki logs, Sentry issues). Support/Admin only.
// @Tags admin
// @Produce application/octet-stream
// @Param id path int true "User ID"
// @Param format query string false "framed (default, progress+ETA) or raw (plain sqlite)"
// @Param include query string false "CSV of db,loki,sentry (default all)"
// @Security BearerAuth
// @Success 200 {file} binary
// @Router /modtools/user/{id}/dump [get]
func GetUserDump(c *fiber.Ctx) error {
	// Two ways in: a logged-in Support/Admin user, or a configured third-party
	// API key (USERDUMP_API_KEY) presented via the X-Dump-Key header or ?key=.
	viaKey := validDumpKey(c)
	var myid uint64
	if !viaKey {
		myid = user.WhoAmI(c)
		if myid == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
		}
		if !user.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Must be Support or Admin")
		}
	}

	targetID, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || targetID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid user id")
	}

	db := database.DBConn
	var cnt int64
	db.Raw("SELECT COUNT(*) FROM users WHERE id = ?", targetID).Scan(&cnt)
	if cnt == 0 {
		return fiber.NewError(fiber.StatusNotFound, "User not found")
	}

	include := parseInclude(c.Query("include", "db,loki,sentry"))
	startNs, endNs := dumpTimeRange(c)

	// Audit trail: who dumped whom.
	if viaKey {
		log.Printf("userdump: API-key client generated a data dump for user %d (include=%s)",
			targetID, includeString(include))
	} else {
		log.Printf("userdump: support user %d generated a data dump for user %d (include=%s)",
			myid, targetID, includeString(include))
	}

	if c.Query("format", "framed") == "raw" {
		return streamRaw(c, targetID, include, startNs, endNs)
	}

	c.Set("Content-Type", "application/octet-stream")
	c.Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"user-%d-dump.sqlite\"", targetID))
	c.Set("X-Content-Type-Options", "nosniff")
	c.Context().SetBodyStreamWriter(func(w *bufio.Writer) {
		runFramedDump(w, targetID, include, startNs, endNs)
	})
	return nil
}

// validDumpKey reports whether the request carries the configured third-party
// API key (USERDUMP_API_KEY), which authorises a dump without a logged-in
// Support/Admin session. Presented via the X-Dump-Key header or ?key=. The
// feature is disabled when the env var is empty. Comparison is constant-time.
func validDumpKey(c *fiber.Ctx) bool {
	want := os.Getenv("USERDUMP_API_KEY")
	if want == "" {
		return false
	}
	got := c.Get("X-Dump-Key")
	if got == "" {
		got = c.Query("key")
	}
	if got == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(got), []byte(want)) == 1
}

func parseInclude(s string) map[string]bool {
	m := map[string]bool{}
	for _, p := range strings.Split(s, ",") {
		p = strings.TrimSpace(strings.ToLower(p))
		if p != "" {
			m[p] = true
		}
	}
	if len(m) == 0 {
		m["db"] = true
	}
	return m
}

func includeString(m map[string]bool) string {
	var parts []string
	for k := range m {
		parts = append(parts, k)
	}
	sort.Strings(parts)
	return strings.Join(parts, ",")
}

func dumpTimeRange(c *fiber.Ctx) (int64, int64) {
	now := time.Now()
	end := now
	start := now.AddDate(0, 0, -90)
	if v := c.Query("until"); v != "" {
		if t, err := time.Parse(time.RFC3339, v); err == nil {
			end = t
		}
	}
	if v := c.Query("since"); v != "" {
		if strings.HasSuffix(v, "d") {
			if n, err := strconv.Atoi(strings.TrimSuffix(v, "d")); err == nil {
				start = now.AddDate(0, 0, -n)
			}
		} else if t, err := time.Parse(time.RFC3339, v); err == nil {
			start = t
		}
	}
	return start.UnixNano(), end.UnixNano()
}

func gatherEmails(sqlDB *sql.DB, userID int64) []string {
	rows, err := sqlDB.Query("SELECT email FROM users_emails WHERE userid = ?", userID)
	if err != nil {
		return nil
	}
	defer rows.Close()
	var emails []string
	for rows.Next() {
		var e string
		if rows.Scan(&e) == nil && strings.TrimSpace(e) != "" {
			emails = append(emails, e)
		}
	}
	return emails
}

// buildPlan assembles the ordered list of collection sections for this dump.
func buildPlan(sqlDB *sql.DB, targetID int64, emails []string, include map[string]bool, startNs, endNs int64) []section {
	tables := existingTables(sqlDB)
	var plan []section

	if include["db"] {
		chatIDs := scanIDs(sqlDB,
			"SELECT id FROM chat_rooms WHERE user1 = ? OR user2 = ? UNION SELECT chatid FROM chat_roster WHERE userid = ?",
			targetID, targetID, targetID)
		msgIDs := scanIDs(sqlDB, "SELECT id FROM messages WHERE fromuser = ?", targetID)
		trackIDs := scanIDs(sqlDB, "SELECT id FROM email_tracking WHERE userid = ?", targetID)

		for _, spec := range buildDBSpecs(targetID, chatIDs, msgIDs, trackIDs) {
			if !tables[strings.ToLower(spec.table)] {
				continue
			}
			s := spec
			plan = append(plan, section{
				name:   s.table,
				weight: 1,
				run:    func(b *Builder) (int, error) { return runDBSpec(b, sqlDB, s) },
			})
		}
	}

	if include["loki"] {
		lokiURL := os.Getenv("LOKI_URL")
		if lokiURL == "" {
			lokiURL = "http://loki:3100"
		}
		uid, em := uint64(targetID), emails
		plan = append(plan, section{
			name:   "loki_logs",
			weight: 8,
			run:    func(b *Builder) (int, error) { return collectLoki(b, newHTTPLoki(lokiURL), uid, em, startNs, endNs) },
		})
	}

	if include["sentry"] {
		uid, em := uint64(targetID), emails
		plan = append(plan, section{
			name:   "sentry_issues",
			weight: 8,
			run:    func(b *Builder) (int, error) { return collectSentry(b, newSentryClient(), uid, em) },
		})
	}

	return plan
}

// onSection is called after each section completes, for progress reporting.
type onSection func(done, total, totalWeight, doneWeight int, sec section, rows int, secErr error)

// buildDump runs every section into the builder, recording outcomes in
// _sections and returning the list of warnings (non-fatal section failures).
func buildDump(b *Builder, targetID int64, include map[string]bool, startNs, endNs int64, cb onSection) []string {
	sqlDB, err := database.DBConn.DB()
	if err != nil {
		return []string{"db handle: " + err.Error()}
	}
	emails := gatherEmails(sqlDB, targetID)
	plan := buildPlan(sqlDB, targetID, emails, include, startNs, endNs)

	totalWeight := 0
	for _, s := range plan {
		totalWeight += s.weight
	}

	var warnings []string
	doneWeight := 0
	for i, sec := range plan {
		t0 := time.Now()
		rows, secErr := sec.run(b)
		ms := time.Since(t0).Milliseconds()
		doneWeight += sec.weight

		status, note := "done", ""
		if secErr != nil {
			status, note = "warning", secErr.Error()
			warnings = append(warnings, sec.name+": "+secErr.Error())
		}
		b.AddSection(sec.name, status, rows, note, ms)

		if cb != nil {
			cb(i+1, len(plan), totalWeight, doneWeight, sec, rows, secErr)
		}
	}
	return warnings
}

func newBuilderWithMeta(targetID uint64, include map[string]bool, start time.Time) (*Builder, error) {
	b, err := NewBuilder()
	if err != nil {
		return nil, err
	}
	_ = b.InitMeta()
	b.SetMeta("userid", strconv.FormatUint(targetID, 10))
	b.SetMeta("generated_at", start.UTC().Format(time.RFC3339))
	b.SetMeta("include", includeString(include))
	b.SetMeta("generator", "userdump/1")
	return b, nil
}

// runFramedDump builds the dump while emitting progress+ETA frames, then streams
// the finished SQLite file as data frames and a terminating end frame.
func runFramedDump(w *bufio.Writer, targetID uint64, include map[string]bool, startNs, endNs int64) {
	start := time.Now()
	b, err := newBuilderWithMeta(targetID, include, start)
	if err != nil {
		_ = writeEnd(w, endFrame{Warnings: []string{"sqlite init: " + err.Error()}})
		return
	}
	defer b.Remove()

	warnings := buildDump(b, int64(targetID), include, startNs, endNs,
		func(done, total, totalWeight, doneWeight int, sec section, rows int, secErr error) {
			elapsed := time.Since(start).Milliseconds()
			var eta int64
			if doneWeight > 0 && doneWeight < totalWeight {
				eta = elapsed * int64(totalWeight-doneWeight) / int64(doneWeight)
			}
			pct := 0.0
			if totalWeight > 0 {
				pct = math.Round(float64(doneWeight)/float64(totalWeight)*1000) / 10
			}
			status, msg := "done", ""
			if secErr != nil {
				status, msg = "warning", secErr.Error()
			}
			_ = writeProgress(w, progressFrame{
				Phase: "collect", Section: sec.name, Status: status,
				Done: done, Total: total, Percent: pct,
				ElapsedMs: elapsed, EtaMs: eta, Rows: rows, Message: msg,
			})
		})

	b.SetMeta("warnings", strconv.Itoa(len(warnings)))
	if err := b.Finalize(); err != nil {
		_ = writeEnd(w, endFrame{Warnings: append(warnings, "finalize: "+err.Error())})
		return
	}

	f, err := os.Open(b.Path())
	if err != nil {
		_ = writeEnd(w, endFrame{Warnings: append(warnings, "open: "+err.Error())})
		return
	}
	defer f.Close()

	h := sha256.New()
	buf := make([]byte, 64*1024)
	var total int64
	for {
		n, rerr := f.Read(buf)
		if n > 0 {
			h.Write(buf[:n])
			total += int64(n)
			if werr := writeFrame(w, frameData, buf[:n]); werr != nil {
				return
			}
		}
		if rerr == io.EOF {
			break
		}
		if rerr != nil {
			_ = writeEnd(w, endFrame{Bytes: total, Warnings: append(warnings, "read: "+rerr.Error())})
			return
		}
	}
	_ = writeEnd(w, endFrame{Bytes: total, SHA256: hex.EncodeToString(h.Sum(nil)), Warnings: warnings})
}

// streamRaw builds the dump and streams the plain SQLite file (no framing/progress).
func streamRaw(c *fiber.Ctx, targetID uint64, include map[string]bool, startNs, endNs int64) error {
	start := time.Now()
	b, err := newBuilderWithMeta(targetID, include, start)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "sqlite init: "+err.Error())
	}
	warnings := buildDump(b, int64(targetID), include, startNs, endNs, nil)
	b.SetMeta("warnings", strconv.Itoa(len(warnings)))
	if err := b.Finalize(); err != nil {
		b.Remove()
		return fiber.NewError(fiber.StatusInternalServerError, "finalize: "+err.Error())
	}
	f, err := os.Open(b.Path())
	if err != nil {
		b.Remove()
		return fiber.NewError(fiber.StatusInternalServerError, "open: "+err.Error())
	}

	c.Set("Content-Type", "application/octet-stream")
	c.Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"user-%d-dump.sqlite\"", targetID))
	c.Context().SetBodyStreamWriter(func(w *bufio.Writer) {
		defer b.Remove()
		defer f.Close()
		_, _ = io.Copy(w, f)
		_ = w.Flush()
	})
	return nil
}
