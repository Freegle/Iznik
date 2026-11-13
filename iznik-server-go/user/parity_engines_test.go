package user

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

type digestSetting struct {
	Userid         uint64
	Groupid        uint64
	Emailfrequency int
}

// Layer 2 of the parity harness. Ids start at parity.ID(3000).
func TestParityResult_StopDigestEmails(t *testing.T) {
	group := func(n int) parity.Row {
		return parity.Row{"id": parity.ID(n), "polyindex": parity.Geometry("POINT(-3.2 55.9)")}
	}
	fx := parity.Fixture{
		parity.Table("users", parity.Row{"id": parity.ID(3001)}, parity.Row{"id": parity.ID(3002)}),
		parity.Table("groups", group(3100), group(3101)),
		parity.Table("memberships",
			parity.Row{"id": parity.ID(3010), "userid": parity.ID(3001), "groupid": parity.ID(3100), "emailfrequency": 24},
			parity.Row{"id": parity.ID(3011), "userid": parity.ID(3001), "groupid": parity.ID(3101), "emailfrequency": 1},
			parity.Row{"id": parity.ID(3012), "userid": parity.ID(3002), "groupid": parity.ID(3100), "emailfrequency": 24},
		),
	}

	// A write site returns the rows it left behind. Member 3002 must be untouched.
	parity.AssertResultParity(t, "665bf9ca18a5", fx, func(db *gorm.DB) any {
		applyUnsubscribe(db, parity.ID(3001), UnsubDigest)

		// A struct, not a map: MySQL's text protocol hands a map every column as bytes.
		var rows []digestSetting
		db.Table("memberships").Select("userid", "groupid", "emailfrequency").
			Where("userid IN ?", []uint64{parity.ID(3001), parity.ID(3002)}).Order("id").Find(&rows)
		return rows
	}, parity.Expect([]digestSetting{
		{Userid: parity.ID(3001), Groupid: parity.ID(3100), Emailfrequency: 0},
		{Userid: parity.ID(3001), Groupid: parity.ID(3101), Emailfrequency: 0},
		{Userid: parity.ID(3002), Groupid: parity.ID(3100), Emailfrequency: 24},
	}), parity.Ordered())
}
