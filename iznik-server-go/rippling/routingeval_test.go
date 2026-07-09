package rippling

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// An explicit ROUTING_EVAL_URL is used as-is and wins over everything else.
func TestRoutingEvalURL_ExplicitOverrideWins(t *testing.T) {
	t.Setenv("ROUTING_EVAL_URL", "http://routing.example:8197")
	t.Setenv("SPATIAL_KNN_URL", "http://spatial-knn:8194")
	assert.Equal(t, "http://routing.example:8197", routingEvalURL())
}

// Regression guard for the all-grey-bullseye bug: SPATIAL_KNN_URL addresses the
// KNN service, which has no /v1/ripple-eval. With ROUTING_EVAL_URL unset, the
// ripple-eval base must fall to the routing default and NEVER to SPATIAL_KNN_URL.
func TestRoutingEvalURL_NeverFallsBackToSpatialKnn(t *testing.T) {
	t.Setenv("ROUTING_EVAL_URL", "")
	t.Setenv("SPATIAL_KNN_URL", "http://spatial-knn:8194")
	got := routingEvalURL()
	assert.NotEqual(t, "http://spatial-knn:8194", got)
	assert.Equal(t, "http://spatial:8194", got)
}

// With nothing set, the local-Docker routing default (the routing container's
// internal no-auth port) is used.
func TestRoutingEvalURL_DefaultWhenUnset(t *testing.T) {
	t.Setenv("ROUTING_EVAL_URL", "")
	t.Setenv("SPATIAL_KNN_URL", "")
	assert.Equal(t, "http://spatial:8194", routingEvalURL())
}
