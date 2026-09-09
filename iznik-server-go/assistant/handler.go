package assistant

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// HTTP surface. One endpoint does the work: POST /assistant/turn takes typed text, a
// chip tap or a host event and answers with server-sent events, so the member sees
// Freegle's words arrive as they are composed.

var (
	serviceOnce sync.Once
	service     *Service
)

// Default builds the singleton service from the environment on first use.
func Default() *Service {
	serviceOnce.Do(func() {
		w, err := LoadWorkflow()
		if err != nil {
			panic(err)
		}
		w.Guardrails = Guardrails()
		var llm LLM = NewAnthropicLLM()
		if llm == (*AnthropicLLM)(nil) {
			llm = &FakeLLM{}
		}
		perHour := envInt("ASSISTANT_PER_HOUR", 60)
		anonCap := envInt("ASSISTANT_ANON_DAILY_CAP", 500)
		authCap := envInt("ASSISTANT_DAILY_CAP", 20000)
		q := NewQuota(perHour, anonCap, authCap, nil)
		q.Log = func(kind, key string) { fmt.Printf("assistant: %s %s\n", kind, key) }
		service = &Service{
			Engine:     &Engine{Workflow: w, Store: &DBStore{DB: database.DBConn}, LLM: llm, MaxRetries: 1},
			Quota:      q,
			Strikes:    NewStrikes(3, time.Hour, nil),
			Transcript: &Transcript{DB: database.DBConn},
		}
	})
	return service
}

// SetService replaces the singleton; for tests.
func SetService(s *Service) {
	serviceOnce.Do(func() {})
	service = s
}

func envInt(name string, def int) int {
	if v := os.Getenv(name); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}

type turnRequest struct {
	Conversation string `json:"conversation"`
	Text         string `json:"text"`
	Tap          string `json:"tap"`
	Event        *Event `json:"event"`
}

// identityFor resolves the member from the JWT, or the anonymous token, minting one
// when needed.
func identityFor(c *fiber.Ctx, ip string) Identity {
	if myid := user.WhoAmI(c); myid > 0 {
		id := Identity{Key: "u:" + strconv.FormatUint(myid, 10), UserID: myid}
		db := database.DBConn
		var row struct {
			Fullname  *string `gorm:"column:fullname"`
			Firstname *string `gorm:"column:firstname"`
			Location  *string `gorm:"column:location"`
		}
		db.Table("users").Select("fullname, firstname, JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.name')) AS location").Where("id = ?", myid).Limit(1).Scan(&row)
		switch {
		case row.Fullname != nil && *row.Fullname != "":
			id.Name = *row.Fullname
		case row.Firstname != nil:
			id.Name = *row.Firstname
		}
		if row.Location != nil {
			id.LocationName = *row.Location
		}
		var community string
		db.Table("memberships").Select("groups.namedisplay").
			Joins("INNER JOIN groups ON groups.id = memberships.groupid").
			Where("memberships.userid = ? AND groups.type = 'Freegle'", myid).
			Order("memberships.added DESC").Limit(1).Scan(&community)
		id.Community = community
		return id
	}
	given := c.Get("X-Assistant-Anon")
	if anon := VerifyAnon(given); anon != "" {
		return Identity{Key: "a:" + anon, AnonToken: given}
	}
	if !Default().Quota.AllowMint(ip) {
		// Too many new identities from this address: one shared, model-free identity.
		return Identity{Key: "a:throttled:" + ip, Throttled: true}
	}
	tok := MintAnon()
	return Identity{Key: "a:" + strings.SplitN(tok, ".", 2)[0], AnonToken: tok}
}

// clientAddress is the hop our own proxy recorded: the last entry of X-Forwarded-For,
// else the remote address. The first entry is whatever the client chose to claim.
func clientAddress(c *fiber.Ctx) string {
	if xff := c.Get("X-Forwarded-For"); xff != "" {
		parts := strings.Split(xff, ",")
		return strings.TrimSpace(parts[len(parts)-1])
	}
	return c.Context().RemoteIP().String()
}

// At most this many streams open at once per identity; the hourly bucket only counts
// new turns, not ones still running.
const maxInflight = 2

var inflight = struct {
	sync.Mutex
	m map[string]int
}{m: map[string]int{}}

func acquire(key string) bool {
	inflight.Lock()
	defer inflight.Unlock()
	if inflight.m[key] >= maxInflight {
		return false
	}
	inflight.m[key]++
	return true
}

func release(key string) {
	inflight.Lock()
	defer inflight.Unlock()
	inflight.m[key]--
	if inflight.m[key] <= 0 {
		delete(inflight.m, key)
	}
}

// Turn handles POST /assistant/turn.
// @Router /assistant/turn [post]
// @Summary One turn of the Freegle chat assistant
// @Description Takes typed text, a chip tap or a host event; streams the reply as server-sent events (delta, then turn).
// @Tags assistant
// @Accept json
// @Produce text/event-stream
func Turn(c *fiber.Ctx) error {
	var req turnRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid body")
	}
	if len(req.Text) > 6000 {
		req.Text = req.Text[:6000]
	}
	ip := clientAddress(c)
	id := identityFor(c, ip)
	if !acquire(id.Key) {
		return fiber.NewError(fiber.StatusTooManyRequests, "Still answering your last message")
	}
	svc := Default()
	c.Set("Content-Type", "text/event-stream")
	c.Set("Cache-Control", "no-cache, no-transform")
	c.Set("X-Accel-Buffering", "no")
	if id.AnonToken != "" {
		c.Set("X-Assistant-Anon", id.AnonToken)
	}
	ctx := c.UserContext()
	c.Context().SetBodyStreamWriter(func(w *bufio.Writer) {
		defer release(id.Key)
		send := func(event string, data interface{}) {
			b, _ := json.Marshal(data)
			fmt.Fprintf(w, "event: %s\ndata: %s\n\n", event, string(b))
			_ = w.Flush()
		}
		if id.AnonToken != "" {
			send("identity", map[string]string{"anon": id.AnonToken})
		}
		res, err := svc.Turn(ctx, id, TurnInput{ConversationID: req.Conversation, Text: req.Text, Tap: req.Tap, Event: req.Event, IP: ip}, func(s string) {
			send("delta", map[string]string{"text": s})
		})
		if err != nil {
			fmt.Printf("assistant: turn error: %v\n", err)
			send("error", map[string]string{"message": "Something went wrong. Please try again."})
			return
		}
		send("turn", res)
	})
	return nil
}

// Workflow handles GET /assistant/workflow: the definition, for the editor and viewer.
func Workflow(c *fiber.Ctx) error {
	return c.JSON(Default().Engine.Workflow)
}

// Widgets handles GET /assistant/widgets?ids=1,2,3 for chat messages the caller can see.
func Widgets(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	var ids []uint64
	for _, part := range strings.Split(c.Query("ids"), ",") {
		if n, err := strconv.ParseUint(strings.TrimSpace(part), 10, 64); err == nil {
			ids = append(ids, n)
		}
	}
	if len(ids) == 0 || len(ids) > 200 {
		return c.JSON(fiber.Map{"ret": 0, "widgets": map[string]interface{}{}})
	}
	// Only messages in rooms the caller is part of.
	var allowed []uint64
	database.DBConn.Table("chat_messages").Select("chat_messages.id").
		Joins("INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid").
		Where("chat_messages.id IN ? AND (chat_rooms.user1 = ? OR chat_rooms.user2 = ?)", ids, myid, myid).
		Scan(&allowed)
	widgets := Default().Transcript.WidgetsFor(allowed)
	out := map[string]interface{}{}
	for k, v := range widgets {
		out[strconv.FormatUint(k, 10)] = v
	}
	return c.JSON(fiber.Map{"ret": 0, "widgets": out})
}
