package database

import (
	"context"
	"database/sql/driver"
	"testing"
)

func TestFailoverConnector_Driver(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name          string
		primaryDriver driver.Driver
		wantSameAs    bool
	}{
		{
			name:          "returns_primary_driver",
			primaryDriver: &mockDriver{name: "primary"},
			wantSameAs:    true,
		},
		{
			name:          "fallback_driver_ignored",
			primaryDriver: &mockDriver{name: "primary"},
			wantSameAs:    true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()

			// Build a failoverConnector with the specified primary driver.
			// We use a mock connector that returns our mockDriver.
			primaryConn := &mockConnector{driver: tt.primaryDriver}
			fallbackConn := &mockConnector{driver: &mockDriver{name: "fallback"}}

			fc := &failoverConnector{
				primary:    primaryConn,
				fallback:   fallbackConn,
				retryAfter: 0, // not used for Driver()
			}

			got := fc.Driver()

			if tt.wantSameAs {
				// Verify that the returned driver is the same instance as the primary's driver.
				// Since we can't compare interfaces directly for identity in all cases,
				// we check if they are the same type and have the same name via our mock.
				gotName := getMockDriverName(got)
				wantName := getMockDriverName(tt.primaryDriver)
				if gotName != wantName {
					t.Errorf("fc.Driver() = %v, want driver with name %q", got, wantName)
				}
			}

			// Ensure the fallback connector's Driver is NOT returned.
			fallbackDriver := fc.fallback.Driver()
			if got == fallbackDriver {
				t.Errorf("fc.Driver() incorrectly returned fallback driver")
			}
		})
	}
}

// Helper types for testing failoverConnector.Driver()

type mockDriver struct {
	name string
}

func (m *mockDriver) Open(name string) (driver.Conn, error) {
	return &mockConn{name: m.name}, nil
}

func getMockDriverName(d driver.Driver) string {
	if md, ok := d.(*mockDriver); ok {
		return md.name
	}
	return "unknown"
}

type mockConnector struct {
	driver driver.Driver
}

func (m *mockConnector) Connect(ctx context.Context) (driver.Conn, error) {
	return m.driver.Open("test")
}

func (m *mockConnector) Driver() driver.Driver {
	return m.driver
}

type mockConn struct {
	name string
}

func (m *mockConn) Close() error                              { return nil }
func (m *mockConn) Prepare(query string) (driver.Stmt, error) { return nil, nil }
func (m *mockConn) Begin() (driver.Tx, error)                 { return nil, nil }
func (m *mockConn) CheckNamedValue(*driver.NamedValue) error  { return nil }
