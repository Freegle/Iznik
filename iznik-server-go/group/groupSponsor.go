package group

import "gorm.io/gorm"

func (GroupSponsor) TableName() string {
	return "groups_sponsorship"
}

type GroupSponsor struct {
	ID       uint64 `json:"id" gorm:"primary_key"`
	Groupid  uint64 `json:"-"`
	Name     string `json:"name"`
	Linkurl  string `json:"linkurl"`
	Imageurl string `json:"imageurl"`
	Tagline  string `json:"tagline"`
}

// currentSponsors limits a GroupSponsors preload to the sponsors members should see: visible,
// and inside their dates. Old sponsorship rows stay in the table after a deal ends, so without
// this a community shows every council that has ever sponsored it.
func currentSponsors(db *gorm.DB) *gorm.DB {
	return db.Where("startdate <= NOW() AND enddate >= DATE(NOW()) AND visible = 1").Order("amount DESC")
}
