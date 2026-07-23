package message

import (
	"net"
	"testing"
)

// TestIsDisallowedIP guards the bulk-offer photo SSRF fix: a user-supplied photo URL that resolves
// to an internal/private/link-local address must be blocked, while public addresses are allowed.
func TestIsDisallowedIP(t *testing.T) {
	blocked := []string{
		"127.0.0.1", "::1", "10.0.0.5", "172.16.0.1", "172.31.255.255",
		"192.168.1.1", "169.254.169.254", "0.0.0.0", "fc00::1", "fe80::1", "224.0.0.1",
	}
	for _, ip := range blocked {
		if !isDisallowedIP(net.ParseIP(ip)) {
			t.Errorf("isDisallowedIP(%s) = false, want true (internal/private must be blocked)", ip)
		}
	}

	allowed := []string{"8.8.8.8", "1.1.1.1", "185.199.221.13", "2606:4700:4700::1111"}
	for _, ip := range allowed {
		if isDisallowedIP(net.ParseIP(ip)) {
			t.Errorf("isDisallowedIP(%s) = true, want false (public must be allowed)", ip)
		}
	}

	if !isDisallowedIP(nil) {
		t.Error("isDisallowedIP(nil) should be true (fail closed on unparseable address)")
	}
}
