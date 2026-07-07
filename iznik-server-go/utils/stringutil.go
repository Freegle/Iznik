package utils

import (
	"regexp"
	"strings"
)

// StripEmailDomain removes the domain part from an email address.
// Returns the local part before the @ sign, or the whole string if no @ sign.
func StripEmailDomain(email string) string {
	i := strings.Index(email, "@")
	if i == -1 {
		return email
	}
	return email[:i]
}

// TruncateStringUtil truncates a string to a maximum length, appending "..." if truncated.
func TruncateStringUtil(s string, maxLen int) string {
	if maxLen < 0 {
		maxLen = 0
	}
	if len(s) <= maxLen {
		return s
	}
	return s[:maxLen] + "..."
}

// IsValidEmailAddress validates an email address using a regex pattern.
func IsValidEmailAddress(email string) bool {
	if email == "" {
		return false
	}
	// Simple validation: must have @ and . with text on both sides
	pattern := `^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$`
	regex := regexp.MustCompile(pattern)
	return regex.MatchString(email)
}
