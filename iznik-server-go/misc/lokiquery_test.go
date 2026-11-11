package misc

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestLokiQueryRangeSendsTheQueryAndReturnsStreams(t *testing.T) {
	var got map[string]string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/loki/api/v1/query_range", r.URL.Path)
		got = map[string]string{}
		for k := range r.URL.Query() {
			got[k] = r.URL.Query().Get(k)
		}
		w.Write([]byte(`{"status":"success","data":{"result":[{"stream":{"source":"client"},"values":[["5","{\"a\":1}"]]}]}}`))
	}))
	defer srv.Close()

	streams, err := LokiQueryRange(srv.URL+"/", `{app="freegle"}`, 1, 2, 30, "forward", time.Second)
	require.NoError(t, err)
	require.Len(t, streams, 1)
	assert.Equal(t, "client", streams[0].Stream["source"])
	assert.Equal(t, [][]string{{"5", `{"a":1}`}}, streams[0].Values)
	assert.Equal(t, map[string]string{"query": `{app="freegle"}`, "start": "1", "end": "2", "limit": "30", "direction": "forward"}, got)
}

func TestLokiQueryRangeReportsAStatusError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		w.Write([]byte("parse error"))
	}))
	defer srv.Close()

	_, err := LokiQueryRange(srv.URL, "x", 1, 2, 1, "forward", time.Second)
	var status *LokiStatusError
	require.True(t, errors.As(err, &status))
	assert.Equal(t, 400, status.Status)
	assert.Equal(t, "parse error", status.Body)
}

func TestLokiQueryRangeRejectsAFailedQuery(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"status":"error"}`))
	}))
	defer srv.Close()

	_, err := LokiQueryRange(srv.URL, "x", 1, 2, 1, "forward", time.Second)
	assert.ErrorContains(t, err, "status: error")
}
