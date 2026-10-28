package test

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/sso"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const relinkSecret = "relink_test_secret"

// fakeDiscourse behaves like Discourse for the calls RelinkMerged makes: accounts are found by
// external_id, and sync_sso with an unknown external_id links the account whose email matches,
// as DiscourseConnect does.
type fakeDiscourse struct {
	mu       sync.Mutex
	external map[string]string // external_id -> username
	email    map[string]string // username -> primary email
	syncs    []url.Values
}

func newFakeDiscourse(t *testing.T) (*fakeDiscourse, *httptest.Server) {
	f := &fakeDiscourse{external: map[string]string{}, email: map[string]string{}}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasPrefix(r.URL.Path, "/users/by-external/"):
			id := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/users/by-external/"), ".json")
			if u, ok := f.external[id]; ok {
				json.NewEncoder(w).Encode(map[string]interface{}{"user": map[string]string{"username": u}})
				return
			}
			w.WriteHeader(http.StatusNotFound)
		case r.URL.Path == "/admin/users/list/all.json":
			name := r.URL.Query().Get("filter")
			json.NewEncoder(w).Encode([]map[string]string{{"username": name, "email": f.email[name]}})
		case r.URL.Path == "/admin/users/sync_sso":
			r.ParseForm()
			payload, sig := r.PostForm.Get("sso"), r.PostForm.Get("sig")
			mac := hmac.New(sha256.New, []byte(relinkSecret))
			mac.Write([]byte(payload))
			if hex.EncodeToString(mac.Sum(nil)) != sig {
				w.WriteHeader(http.StatusForbidden)
				return
			}
			raw, _ := base64.StdEncoding.DecodeString(payload)
			p, _ := url.ParseQuery(string(raw))
			f.syncs = append(f.syncs, p)
			ext := p.Get("external_id")
			u, ok := f.external[ext]
			if !ok {
				for name, e := range f.email {
					if strings.EqualFold(e, p.Get("email")) {
						u, ok = name, true
						for k, v := range f.external {
							if v == name {
								delete(f.external, k)
							}
						}
						f.external[ext] = name
					}
				}
			}
			if !ok {
				w.WriteHeader(http.StatusUnprocessableEntity)
				return
			}
			f.email[u] = p.Get("email")
			w.Write([]byte(`{"success":"OK"}`))
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Setenv("DISCOURSE_API", srv.URL)
	t.Setenv("DISCOURSE_APIKEY", "key")
	t.Setenv("DISCOURSE_SECRET", relinkSecret)
	t.Cleanup(srv.Close)
	return f, srv
}

// A moderator whose old account was merged into this one, with a preferred email.
func mergedModerator(t *testing.T, prefix string, oldID uint64) uint64 {
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	newID := CreateTestUser(t, prefix, "Moderator")
	CreateTestMembership(t, newID, groupID, "Moderator")
	db.Exec("UPDATE users_emails SET preferred = 1 WHERE userid = ?", newID)
	db.Exec("INSERT INTO logs (user, byuser, type, subtype, text, timestamp) VALUES (?, ?, 'User', 'Merged', ?, NOW())",
		newID, newID, fmt.Sprintf("Merged %d into %d", oldID, newID))
	return newID
}

func TestRelinkMovesTheDiscourseAccountAndUpdatesItsEmail(t *testing.T) {
	f, _ := newFakeDiscourse(t)
	prefix := uniquePrefix("relink")
	oldID := uint64(910000000 + time.Now().UnixNano()%1000000)
	newID := mergedModerator(t, prefix, oldID)
	f.external[fmt.Sprint(oldID)] = "Jane_L"
	f.email["Jane_L"] = "old@yahoo.test"

	relinked, err := sso.RelinkMerged(newID)
	require.NoError(t, err)
	assert.True(t, relinked)

	assert.Equal(t, "Jane_L", f.external[fmt.Sprint(newID)], "the Discourse account now follows the kept account")
	assert.Equal(t, prefix+"@test.com", f.email["Jane_L"], "and has the member's preferred email")
	require.Len(t, f.syncs, 2)
	assert.Equal(t, "old@yahoo.test", f.syncs[0].Get("email"), "the link step sends the email Discourse has, so it matches")
}

func TestRelinkDoesNothingWhenDiscourseAlreadyKnowsTheAccount(t *testing.T) {
	f, _ := newFakeDiscourse(t)
	prefix := uniquePrefix("relink_known")
	newID := mergedModerator(t, prefix, 920000001)
	f.external[fmt.Sprint(newID)] = "Already"

	relinked, err := sso.RelinkMerged(newID)
	require.NoError(t, err)
	assert.False(t, relinked)
	assert.Empty(t, f.syncs)
}

func TestRelinkDoesNothingWithoutAMergedDiscourseAccount(t *testing.T) {
	f, _ := newFakeDiscourse(t)
	newID := mergedModerator(t, uniquePrefix("relink_none"), 920000002)

	relinked, err := sso.RelinkMerged(newID)
	require.NoError(t, err)
	assert.False(t, relinked)
	assert.Empty(t, f.syncs)
}

func TestRelinkDoesNothingWhenDiscourseIsNotConfigured(t *testing.T) {
	t.Setenv("DISCOURSE_API", "")
	relinked, err := sso.RelinkMerged(1)
	assert.NoError(t, err)
	assert.False(t, relinked)
}

// The merge itself re-links, in the background, when the kept account is a moderator.
func TestMergeRelinksAModeratorsDiscourseAccount(t *testing.T) {
	f, _ := newFakeDiscourse(t)
	db := database.DBConn
	prefix := uniquePrefix("relink_merge")
	groupID := CreateTestGroup(t, prefix)
	oldID := CreateTestUser(t, prefix+"_old", "User")
	newID := CreateTestUser(t, prefix+"_new", "Moderator")
	CreateTestMembership(t, newID, groupID, "Moderator")
	f.mu.Lock()
	f.external[fmt.Sprint(oldID)] = "Merged_Mod"
	f.email["Merged_Mod"] = prefix + "_old@test.com"
	f.mu.Unlock()

	require.NoError(t, user.MergeUsersTx(db, oldID, newID, newID))

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		f.mu.Lock()
		got := f.external[fmt.Sprint(newID)]
		f.mu.Unlock()
		if got == "Merged_Mod" {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatal("the merge did not move the Discourse account to the kept account")
}

func TestRelinkEndpointIsForSupportOnly(t *testing.T) {
	f, _ := newFakeDiscourse(t)
	prefix := uniquePrefix("relink_ep")
	oldID := uint64(930000000 + time.Now().UnixNano()%1000000)
	newID := mergedModerator(t, prefix, oldID)
	f.external[fmt.Sprint(oldID)] = "Ep_User"
	f.email["Ep_User"] = "ep@old.test"

	body, _ := json.Marshal(map[string]uint64{"userid": newID})

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)
	req := httptest.NewRequest("POST", "/api/discourse/relink?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, supportToken := CreateTestSession(t, supportID)
	req = httptest.NewRequest("POST", "/api/discourse/relink?jwt="+supportToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	assert.Equal(t, true, out["relinked"])
	assert.Equal(t, "Ep_User", f.external[fmt.Sprint(newID)])
}
