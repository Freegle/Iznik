package userdump

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"testing"

	"github.com/stretchr/testify/assert"
)

// failingWriter returns an error after failAfter successful Write calls, so
// writeFrame's header-write and payload-write error paths can both be forced
// deterministically.
type failingWriter struct {
	failAfter int
	calls     int
	buf       bytes.Buffer
}

func (f *failingWriter) Write(p []byte) (int, error) {
	f.calls++
	if f.calls > f.failAfter {
		return 0, errors.New("boom: simulated write failure")
	}
	return f.buf.Write(p)
}

func TestWriteFrame_HeaderPacking(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	payload := []byte("hello")
	err := writeFrame(w, frameData, payload)
	assert.NoError(t, err)

	out := buf.Bytes()
	if assert.Len(t, out, 5+len(payload)) {
		assert.Equal(t, frameData, out[0])
		assert.Equal(t, uint32(len(payload)), binary.BigEndian.Uint32(out[1:5]))
		assert.Equal(t, payload, out[5:])
	}
}

func TestWriteFrame_EmptyPayloadWritesHeaderOnly(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	err := writeFrame(w, frameProgress, nil)
	assert.NoError(t, err)

	out := buf.Bytes()
	if assert.Len(t, out, 5) {
		assert.Equal(t, frameProgress, out[0])
		assert.Equal(t, uint32(0), binary.BigEndian.Uint32(out[1:5]))
	}
}

func TestWriteFrame_EmptyByteSlicePayloadWritesHeaderOnly(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	err := writeFrame(w, frameEnd, []byte{})
	assert.NoError(t, err)
	assert.Len(t, buf.Bytes(), 5)
}

func TestWriteFrame_TrailingFlushErrorPropagates(t *testing.T) {
	// A small header+payload fits entirely inside bufio's default buffer, so
	// neither Write call reaches the underlying writer - the error only
	// surfaces from writeFrame's own trailing w.Flush() call.
	fw := &failingWriter{failAfter: 0}
	w := bufio.NewWriter(fw)
	err := writeFrame(w, frameData, []byte("payload"))
	assert.Error(t, err)
}

func TestWriteFrame_PayloadWriteErrorPropagates(t *testing.T) {
	// A payload larger than the (tiny) buffer forces bufio to flush mid-Write,
	// so the failure surfaces directly from the payload w.Write call itself,
	// distinct from the trailing-Flush path above.
	bigPayload := bytes.Repeat([]byte("x"), 8192)
	fw := &failingWriter{failAfter: 0}
	w := bufio.NewWriterSize(fw, 16) // tiny buffer forces incremental flushes
	err := writeFrame(w, frameData, bigPayload)
	assert.Error(t, err)
}

func TestWriteProgress(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	p := progressFrame{Phase: "collect", Section: "messages", Status: "running", Done: 3, Total: 10, Percent: 30.5, Rows: 100}
	err := writeProgress(w, p)
	assert.NoError(t, err)

	out := buf.Bytes()
	if assert.True(t, len(out) >= 5) {
		assert.Equal(t, frameProgress, out[0])
		length := binary.BigEndian.Uint32(out[1:5])
		var decoded progressFrame
		assert.NoError(t, json.Unmarshal(out[5:5+length], &decoded))
		assert.Equal(t, p, decoded)
	}
}

// writeEnd must never emit a JSON `null` for Warnings - the client always
// expects an array, even when nothing warned.
func TestWriteEnd_NilWarningsBecomesEmptyArrayNotNull(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	err := writeEnd(w, endFrame{Bytes: 1024, SHA256: "abc123", Warnings: nil})
	assert.NoError(t, err)

	out := buf.Bytes()
	length := binary.BigEndian.Uint32(out[1:5])
	payload := out[5 : 5+length]
	assert.NotContains(t, string(payload), `"warnings":null`)
	assert.Contains(t, string(payload), `"warnings":[]`)

	var decoded endFrame
	assert.NoError(t, json.Unmarshal(payload, &decoded))
	assert.Equal(t, []string{}, decoded.Warnings)
}

func TestWriteEnd_NonNilWarningsPreserved(t *testing.T) {
	var buf bytes.Buffer
	w := bufio.NewWriter(&buf)

	warnings := []string{"table X missing", "loki timeout"}
	err := writeEnd(w, endFrame{Bytes: 1, SHA256: "x", Warnings: warnings})
	assert.NoError(t, err)

	out := buf.Bytes()
	length := binary.BigEndian.Uint32(out[1:5])
	var decoded endFrame
	assert.NoError(t, json.Unmarshal(out[5:5+length], &decoded))
	assert.Equal(t, warnings, decoded.Warnings)
}
