package misc

import (
	"errors"
	"net"
	"testing"

	"github.com/gofiber/fiber/v2"
)

func TestErrorLogLabelSeparatesDroppedConnectionsFromServerErrors(t *testing.T) {
	// The sentinel fiber substitutes for a connection-level net.Error: a
	// client that reset the connection, not a 502 we served.
	if got := ErrorLogLabel(fiber.ErrBadGateway, fiber.StatusBadGateway); got != "CLIENT CONNECTION DROPPED" {
		t.Errorf("dropped connection: got %q", got)
	}

	// A 502 this binary raised itself would carry its own message, so it must
	// still shout as a server error.
	ours := fiber.NewError(fiber.StatusBadGateway, "Failed to reach geolocation service")
	if got := ErrorLogLabel(ours, fiber.StatusBadGateway); got != "SERVER ERROR" {
		t.Errorf("our own 502: got %q", got)
	}

	if got := ErrorLogLabel(errors.New("boom"), fiber.StatusInternalServerError); got != "SERVER ERROR" {
		t.Errorf("500: got %q", got)
	}
	if got := ErrorLogLabel(fiber.ErrBadRequest, fiber.StatusBadRequest); got != "BAD REQUEST" {
		t.Errorf("400: got %q", got)
	}
	if got := ErrorLogLabel(fiber.ErrNotFound, fiber.StatusNotFound); got != "" {
		t.Errorf("404 should not be logged: got %q", got)
	}

	// A wrapped net.Error is not the sentinel, so it is not mistaken for one.
	wrapped := fiber.NewError(fiber.StatusBadGateway, (&net.OpError{Op: "read", Err: errors.New("reset")}).Error())
	if got := ErrorLogLabel(wrapped, fiber.StatusBadGateway); got != "SERVER ERROR" {
		t.Errorf("wrapped net error: got %q", got)
	}
}
