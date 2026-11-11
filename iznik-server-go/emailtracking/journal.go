package emailtracking

import (
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
)

// The tracking journal.
//
// An email's images load together, and each load used to INSERT an email_tracking_images row and
// UPDATE the parent email_tracking row (opened_at, scroll_depth_percent). Those updates queued on
// the parent row's lock, and the image insert's foreign key takes a shared lock on the same row:
// 40.7% of db3's statement time, 99% of it lock wait.
//
// Nothing reads tracking the minute it happens (the readers are daily/weekly dashboards, a digest
// "seen" marker and a delivery-health check, all of which tolerate the nightly fold; see
// mail:tracking:fold in iznik-batch). So a request now only notes the event in memory. A flusher
// appends the buffer to email_tracking_journal in one multi-row INSERT about once a second: a bare
// append-only table with no foreign key, no secondary index and no parent lookup, so one Galera
// write set carries hundreds of events instead of three transactions each. The fold applies the
// journal to email_tracking and email_tracking_images overnight.
//
// What is lost if the API process dies: up to a second of events not yet flushed. For open/scroll
// analytics that is noise. If the journal cannot be written at all (table missing, database
// refusing), the buffered events are applied the old direct way instead, so a deploy that gets
// ahead of the migration costs load, not data.

const (
	// JournalKindImage is an image load (position, optional scroll estimate).
	JournalKindImage uint8 = 1
	// JournalKindPixel is an open-pixel hit.
	JournalKindPixel uint8 = 2

	defaultJournalTable = "email_tracking_journal"
	journalFlushEvery   = time.Second
	journalFlushAt      = 500
	// If the database stalls and the buffer reaches this, new events are dropped rather than
	// letting memory grow without bound or making the tracking endpoint wait.
	journalBufferCap = 100000
	// Longest ref we keep: a full tracking id is 32 characters.
	journalRefMax = 32
	// email_tracking_images.image_position is varchar(50).
	journalPositionMax = 50
)

// journalRow is one buffered event, shaped like the email_tracking_journal table.
type journalRow struct {
	Ref      string    `gorm:"column:ref"`
	Kind     uint8     `gorm:"column:kind"`
	Position *string   `gorm:"column:position"`
	Scroll   *uint8    `gorm:"column:scroll"`
	LoadedAt time.Time `gorm:"column:loaded_at"`
}

var (
	journalMu       sync.Mutex
	journalBuf      []journalRow
	journalEnabled  = JournalEnabledFromEnv(os.Getenv("EMAIL_TRACKING_JOURNAL"))
	journalTable    = defaultJournalTable
	journalDropped  uint64
	journalOnce     sync.Once
	journalStopOnce sync.Once
	journalRunning  bool
	journalKick     = make(chan struct{}, 1)
	journalStop     = make(chan struct{})
	journalDone     = make(chan struct{})
	journalLastLog  time.Time
	// Serialises flushes so the ticker, the size trigger, tests and shutdown cannot interleave.
	journalFlushMu sync.Mutex
)

// JournalEnabledFromEnv reads the EMAIL_TRACKING_JOURNAL switch: on unless explicitly turned off.
// "off" restores the old behaviour of writing every event straight to email_tracking.
func JournalEnabledFromEnv(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "off", "0", "false", "no":
		return false
	}
	return true
}

// JournalEnabled reports whether events are journalled (true) or written directly (false).
func JournalEnabled() bool {
	journalMu.Lock()
	defer journalMu.Unlock()
	return journalEnabled
}

// SetJournalEnabled switches journalling on or off at runtime. The test suite runs the direct
// handlers by default and turns this on for the journal tests.
func SetJournalEnabled(on bool) {
	journalMu.Lock()
	journalEnabled = on
	journalMu.Unlock()
}

// SetJournalTableForTest points the flusher at another table name and returns a restore func. It
// exists so a test can prove the fallback when the journal cannot be written.
func SetJournalTableForTest(name string) func() {
	journalMu.Lock()
	prev := journalTable
	journalTable = name
	journalMu.Unlock()
	return func() {
		journalMu.Lock()
		journalTable = prev
		journalMu.Unlock()
	}
}

// cleanRef returns the part of a tracking reference worth keeping, or "" if the value cannot be a
// tracking id. Real ids are alphanumeric (32 characters, or the 12-character compact ref); the
// test suite's ids also carry a hyphen. Anything else is a probe and is not recorded.
func cleanRef(ref string) string {
	if ref == "" {
		return ""
	}
	if len(ref) > journalRefMax {
		ref = ref[:journalRefMax]
	}
	for i := 0; i < len(ref); i++ {
		ch := ref[i]
		switch {
		case ch >= 'a' && ch <= 'z', ch >= 'A' && ch <= 'Z', ch >= '0' && ch <= '9', ch == '-':
		default:
			return ""
		}
	}
	return ref
}

func clampPosition(p string) string {
	if r := []rune(p); len(r) > journalPositionMax {
		return string(r[:journalPositionMax])
	}
	return p
}

// RecordImageLoad notes that the image at position was loaded for the email with tracking
// reference ref (full tracking id or compact ref). scrollPercent is the sender's estimate, or -1.
func RecordImageLoad(ref, position string, scrollPercent int) {
	ref = cleanRef(ref)
	if ref == "" {
		return
	}
	pos := clampPosition(position)
	row := journalRow{Ref: ref, Kind: JournalKindImage, Position: &pos, LoadedAt: time.Now()}
	if scrollPercent >= 0 && scrollPercent <= 100 {
		sp := uint8(scrollPercent)
		row.Scroll = &sp
	}
	record(row)
}

// RecordPixelOpen notes a hit on the open pixel for the email with tracking id ref.
func RecordPixelOpen(ref string) {
	ref = cleanRef(ref)
	if ref == "" {
		return
	}
	record(journalRow{Ref: ref, Kind: JournalKindPixel, LoadedAt: time.Now()})
}

func record(row journalRow) {
	if !JournalEnabled() {
		applyDirect(row)
		return
	}

	journalMu.Lock()
	if len(journalBuf) >= journalBufferCap {
		journalDropped++
		journalMu.Unlock()
		return
	}
	journalBuf = append(journalBuf, row)
	full := len(journalBuf) >= journalFlushAt
	journalMu.Unlock()

	journalOnce.Do(func() {
		journalMu.Lock()
		journalRunning = true
		journalMu.Unlock()
		go journalLoop()
	})

	if full {
		select {
		case journalKick <- struct{}{}:
		default:
		}
	}
}

func journalLoop() {
	defer close(journalDone)
	t := time.NewTicker(journalFlushEvery)
	defer t.Stop()
	for {
		select {
		case <-t.C:
			FlushJournal()
		case <-journalKick:
			FlushJournal()
		case <-journalStop:
			FlushJournal()
			return
		}
	}
}

// StopJournal flushes what is buffered and stops the flusher. Call it on graceful shutdown.
func StopJournal() {
	journalStopOnce.Do(func() {
		journalMu.Lock()
		running := journalRunning
		journalMu.Unlock()
		if running {
			close(journalStop)
			select {
			case <-journalDone:
			case <-time.After(5 * time.Second):
			}
		}
	})
	FlushJournal()
}

// FlushJournal appends everything buffered to the journal table and returns how many events it
// handled. If the table cannot be written the events are applied directly instead.
func FlushJournal() int {
	journalFlushMu.Lock()
	defer journalFlushMu.Unlock()

	journalMu.Lock()
	batch := journalBuf
	journalBuf = nil
	table := journalTable
	dropped := journalDropped
	journalDropped = 0
	journalMu.Unlock()

	if dropped > 0 {
		logJournal(fmt.Sprintf("email tracking journal buffer full, dropped %d events", dropped))
	}
	if len(batch) == 0 {
		return 0
	}

	db := database.DBConn
	if db == nil {
		return 0
	}

	if err := db.Table(table).CreateInBatches(&batch, journalFlushAt).Error; err != nil {
		logJournal(fmt.Sprintf("email tracking journal write failed, applying %d events directly: %v", len(batch), err))
		for _, row := range batch {
			applyDirect(row)
		}
	}

	return len(batch)
}

// logJournal prints at most one line a minute, so a broken journal cannot flood the log.
func logJournal(msg string) {
	journalMu.Lock()
	if time.Since(journalLastLog) < time.Minute {
		journalMu.Unlock()
		return
	}
	journalLastLog = time.Now()
	journalMu.Unlock()
	fmt.Println(msg)
}

// applyDirect is the pre-journal behaviour: find the tracking row and write the event into it. It
// is what runs with the journal switched off, and the fallback when the journal cannot be written.
// Both the first-open update and the scroll-depth update are guarded in SQL, so concurrent loads of
// one email do not all rewrite the parent row.
func applyDirect(row journalRow) {
	db := database.DBConn
	if db == nil {
		return
	}

	tracking, found := findTrackingByRef(row.Ref)
	if !found {
		return
	}

	if tracking.OpenedAt == nil {
		via := "image"
		if row.Kind == JournalKindPixel {
			via = "pixel"
		}
		db.Model(tracking).Where("opened_at IS NULL").Updates(map[string]interface{}{
			"opened_at":  row.LoadedAt,
			"opened_via": via,
		})
	}

	if row.Kind != JournalKindImage {
		return
	}

	position := "unknown"
	if row.Position != nil {
		position = *row.Position
	}
	db.Create(&EmailTrackingImage{
		EmailTrackingID:        tracking.ID,
		ImagePosition:          position,
		EstimatedScrollPercent: row.Scroll,
		LoadedAt:               row.LoadedAt,
	})

	if row.Scroll != nil && (tracking.ScrollDepthPercent == nil || *row.Scroll > *tracking.ScrollDepthPercent) {
		db.Model(tracking).Where("scroll_depth_percent IS NULL OR scroll_depth_percent < ?", *row.Scroll).
			Update("scroll_depth_percent", *row.Scroll)
	}
}
