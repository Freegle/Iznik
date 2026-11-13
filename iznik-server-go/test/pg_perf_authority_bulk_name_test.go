package test

import (
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/authority"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestBulkOfferAuthorityStatsMatchesItemNameInAnyCase proves that the authority statistics
// find a bulk item's weight in the items catalogue when the two names differ only in case.
// A member types "armchair" where the catalogue says "Armchair". MySQL has always matched
// those. PostgreSQL compared the names as plain text, missed the match and quietly used the
// average weight instead, until the join was given a case-insensitive comparison.
//
// The fixture is the one TestBulkOfferAuthorityStats uses: the seeded postcode location
// 1000001 ("EH3 6SS") inside a test authority, so the result is under "EH3 6".
func TestBulkOfferAuthorityStatsMatchesItemNameInAnyCase(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkstatscase")

	const locID = uint64(1000001)
	const partialPostcode = "EH3 6"

	authName := "TestAuth_" + prefix
	db.Exec("INSERT INTO authorities (name, polygon) VALUES (?, ST_GeomFromText('POLYGON((-4 55, -3 55, -3 57, -4 57, -4 55))', 3857))", authName)
	var authorityID uint64
	db.Raw("SELECT id FROM authorities WHERE name = ? ORDER BY id DESC LIMIT 1", authName).Scan(&authorityID)
	require.NotZero(t, authorityID, "test authority must be created")
	t.Cleanup(func() {
		db.Exec("DELETE FROM authorities WHERE id = ?", authorityID)
	})

	// The catalogue name has capitals. The weight is one the average could not plausibly be,
	// so a missed match shows up as a wrong total.
	itemName := fmt.Sprintf("TestStatCaseChair_%d", time.Now().UnixNano())
	const knownWeight = 137.0
	db.Exec("INSERT INTO items (name, weight, popularity) VALUES (?, ?, 1)", itemName, knownWeight)
	var itemsRowID uint64
	db.Raw("SELECT id FROM items WHERE name = ? ORDER BY id DESC LIMIT 1", itemName).Scan(&itemsRowID)
	require.NotZero(t, itemsRowID, "test items row must be created")
	t.Cleanup(func() {
		db.Exec("DELETE FROM items WHERE id = ?", itemsRowID)
	})

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	wanterID := CreateTestUser(t, prefix+"_wanter", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_MEMBER)

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.957571, -3.205333)
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", locID, msgID)
	t.Cleanup(func() {
		// Other tests put posts on this same seeded postcode and count what is there, so
		// this one must not stay behind.
		db.Exec("DELETE FROM messages_bulk_items_interest WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_bulk_items WHERE msgid = ?", msgID)
		db.Exec("UPDATE messages SET locationid = NULL WHERE id = ?", msgID)
	})

	// Posts left on the postcode by other tests are counted too, so measure what this
	// test adds, not the total.
	before, err := authority.GetStatsByAuthority(authorityID, "365 days ago", "today")
	require.NoError(t, err)

	// The bulk item carries the same name in lower case. Two are left when the owner marks
	// it gone, and one was collected in the app, so both weight queries are exercised.
	bulkID := addBulkItem(t, msgID, strings.ToLower(itemName), 2, "Good")
	db.Exec("UPDATE messages_bulk_items SET available = 0 WHERE id = ?", bulkID)
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 1, 'Collected')",
		bulkID, msgID, wanterID)

	stats, err := authority.GetStatsByAuthority(authorityID, "365 days ago", "today")
	require.NoError(t, err)

	ps, found := stats[partialPostcode]
	require.True(t, found, "stats must include postcode %q (got keys: %v)", partialPostcode, statsKeys(stats))

	// 137 kg for each of the 3 units: 2 left at the flip and 1 collected in the app.
	assert.InDelta(t, 3*knownWeight, ps.Weight-before[partialPostcode].Weight, 0.001,
		"a bulk item must take its weight from the catalogue item of the same name, whatever the case")
}
