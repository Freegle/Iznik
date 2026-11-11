package message

// A post is "promised" when every item still available on it is spoken for.
//
// A promise carries no quantity (messages_promises is one row per person), so one
// promise counts as one item. That errs towards showing a post as still open: an
// offer of seven jars with one promise stays open with six to go, which is right;
// one person promised the whole lot stays open until the giver marks it taken,
// which is the trade-off chosen in #1444. The alternative, stamping a post
// PROMISED on the first promise, greyed out multi-item offers for everyone while
// the same card said "6 left".
//
// Every reader of promised must use one of these two forms so the browse feeds,
// My Posts, the full message record and messages_spatial.promised (written by
// iznik-batch's MessageSpatialService) agree.

// PromisedFor reports whether a post with the given number of promises and
// items still available should show as promised. availablenow of 0 (nothing
// left) or NULL (read as 0) needs only one promise.
func PromisedFor(promisecount int, availablenow uint) bool {
	if promisecount <= 0 {
		return false
	}
	need := availablenow
	if need < 1 {
		need = 1
	}
	return uint(promisecount) >= need
}

// PromisedSQL is the same test as PromisedFor as a SQL expression yielding 1 or
// 0, for the feed queries that compute promised in the database. msgCol is the
// column holding the post id and availCol the post's availablenow column, both
// qualified as the surrounding query names them.
func PromisedSQL(msgCol, availCol string) string {
	return "((SELECT COUNT(*) FROM messages_promises mpc WHERE mpc.msgid = " + msgCol + ") >= " +
		"GREATEST(COALESCE(" + availCol + ", 1), 1))"
}
