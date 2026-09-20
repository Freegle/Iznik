package message

import "testing"

// Helper to create a pointer to a string for schedule tests.
func schedPtr(s string) *string { return &s }

// schedText renders a schedule pointer for failure messages; %v on a *string
// would print its address.
func schedText(s *string) string {
	if s == nil {
		return "<nil>"
	}
	return *s
}

func TestCurrentBudgetMins(t *testing.T) {
	const maxDriveMin = 30.0

	tests := []struct {
		name     string
		tick     int
		schedule *string
		want     float64
	}{
		{
			name:     "nil_schedule_returns_max",
			tick:     5,
			schedule: nil,
			want:     maxDriveMin,
		},
		{
			name:     "empty_string_schedule_returns_max",
			tick:     5,
			schedule: schedPtr(""),
			want:     maxDriveMin,
		},
		{
			name:     "invalid_json_schedule_returns_max",
			tick:     5,
			schedule: schedPtr("{not json}"),
			want:     maxDriveMin,
		},
		{
			name:     "empty_array_schedule_returns_max",
			tick:     5,
			schedule: schedPtr("[]"),
			want:     maxDriveMin,
		},
		{
			name:     "matching_tick_returns_drive_min",
			tick:     2,
			schedule: schedPtr(`[{"tick":0,"drive_min":10},{"tick":2,"drive_min":5}]`),
			want:     5.0,
		},
		{
			name:     "multiple_ticks_returns_correct_entry",
			tick:     3,
			schedule: schedPtr(`[{"tick":1,"drive_min":1},{"tick":2,"drive_min":2},{"tick":3,"drive_min":7}]`),
			want:     7.0,
		},
		{
			name:     "no_matching_tick_returns_max",
			tick:     9,
			schedule: schedPtr(`[{"tick":1,"drive_min":1},{"tick":2,"drive_min":2}]`),
			want:     maxDriveMin,
		},
		{
			name:     "matching_tick_with_zero_drive_min_returns_max",
			tick:     2,
			schedule: schedPtr(`[{"tick":0,"drive_min":10},{"tick":2,"drive_min":0}]`),
			want:     maxDriveMin,
		},
		{
			name:     "matching_tick_with_negative_drive_min_returns_max",
			tick:     2,
			schedule: schedPtr(`[{"tick":0,"drive_min":10},{"tick":2,"drive_min":-5}]`),
			want:     maxDriveMin,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := currentBudgetMins(tt.tick, maxDriveMin, tt.schedule)
			// Values are passed through unmodified by the function; no epsilon needed.
			if got != tt.want {
				t.Errorf("currentBudgetMins(%d, %v) = %v, want %v (schedule=%s)",
					tt.tick, maxDriveMin, got, tt.want, schedText(tt.schedule))
			}
		})
	}
}
