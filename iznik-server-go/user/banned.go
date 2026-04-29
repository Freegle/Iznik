package user

import "time"

type UserBanned struct {
	Userid  uint64     `json:"userid" gorm:"primaryKey;autoIncrement:false"`
	Groupid uint64     `json:"groupid" gorm:"primaryKey;autoIncrement:false"`
	Date    *time.Time `json:"date" gorm:"autoCreateTime"`
	Byuser  uint64     `json:"byuser"`
}

func (UserBanned) TableName() string {
	return "users_banned"
}
