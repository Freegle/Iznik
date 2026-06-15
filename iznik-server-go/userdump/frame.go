package userdump

import (
	"bufio"
	"encoding/binary"
	"encoding/json"
)

// Length-prefixed framing for the streamed dump. Each frame is a 5-byte header
// (1 type byte + uint32 big-endian payload length) followed by the payload.
// Progress and end frames carry JSON; data frames carry raw SQLite bytes. A
// client reassembles the database by concatenating all data-frame payloads.
const (
	frameProgress byte = 0x01
	frameData     byte = 0x02
	frameEnd      byte = 0x03
)

type progressFrame struct {
	Phase     string  `json:"phase"`
	Section   string  `json:"section"`
	Status    string  `json:"status"`
	Done      int     `json:"done"`
	Total     int     `json:"total"`
	Percent   float64 `json:"percent"`
	ElapsedMs int64   `json:"elapsed_ms"`
	EtaMs     int64   `json:"eta_ms"`
	Rows      int     `json:"rows"`
	Message   string  `json:"message,omitempty"`
}

type endFrame struct {
	Bytes    int64    `json:"bytes"`
	SHA256   string   `json:"sha256"`
	Warnings []string `json:"warnings"`
}

func writeFrame(w *bufio.Writer, typ byte, payload []byte) error {
	var hdr [5]byte
	hdr[0] = typ
	binary.BigEndian.PutUint32(hdr[1:], uint32(len(payload)))
	if _, err := w.Write(hdr[:]); err != nil {
		return err
	}
	if len(payload) > 0 {
		if _, err := w.Write(payload); err != nil {
			return err
		}
	}
	return w.Flush()
}

func writeProgress(w *bufio.Writer, p progressFrame) error {
	b, _ := json.Marshal(p)
	return writeFrame(w, frameProgress, b)
}

func writeEnd(w *bufio.Writer, e endFrame) error {
	if e.Warnings == nil {
		e.Warnings = []string{}
	}
	b, _ := json.Marshal(e)
	return writeFrame(w, frameEnd, b)
}
