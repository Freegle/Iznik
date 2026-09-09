package assistant

import "testing"

func TestAsstInflightCapPerIdentity(t *testing.T) {
	key := "u:inflight-test"
	if !acquire(key) || !acquire(key) {
		t.Fatal("two streams may run at once")
	}
	if acquire(key) {
		t.Fatal("a third stream waits its turn")
	}
	release(key)
	if !acquire(key) {
		t.Fatal("releasing one frees a slot")
	}
	release(key)
	release(key)
	inflight.Lock()
	_, still := inflight.m[key]
	inflight.Unlock()
	if still {
		t.Fatal("a fully released key leaves no trace")
	}
}
