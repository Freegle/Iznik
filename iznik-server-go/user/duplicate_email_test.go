package user

import (
	"errors"
	"fmt"
	"testing"

	"github.com/go-sql-driver/mysql"
	"github.com/stretchr/testify/assert"
)

func TestIsDuplicateEmailMatchesEveryServerSpelling(t *testing.T) {
	// MySQL 8 qualifies the key with its table; 5.7, Percona and MariaDB do not.
	assert.True(t, isDuplicateEmail(&mysql.MySQLError{Number: 1062, Message: "Duplicate entry 'x' for key 'users_emails.email'"}))
	assert.True(t, isDuplicateEmail(&mysql.MySQLError{Number: 1062, Message: "Duplicate entry 'x' for key 'email'"}))
	assert.True(t, isDuplicateEmail(fmt.Errorf("wrapped: %w", &mysql.MySQLError{Number: 1062, Message: "Duplicate entry 'x' for key 'email'"})))
}

func TestIsDuplicateEmailIgnoresOtherErrors(t *testing.T) {
	assert.False(t, isDuplicateEmail(&mysql.MySQLError{Number: 1062, Message: "Duplicate entry '1-2' for key 'users_emails.userid_canon'"}))
	assert.False(t, isDuplicateEmail(&mysql.MySQLError{Number: 1213, Message: "Deadlock found for key 'email'"}))
	assert.False(t, isDuplicateEmail(errors.New("Duplicate entry 'x' for key 'email'")), "needs the driver error, not text")
	assert.False(t, isDuplicateEmail(nil))
}
