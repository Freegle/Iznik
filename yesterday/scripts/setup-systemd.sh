#!/bin/bash
# Setup systemd service for Yesterday API
# This is an alternative to running via docker-compose
# Use this if you want the API to run directly on the host

echo "Setting up Yesterday API systemd service..."

# Copy service file
cp /var/www/FreegleDocker/yesterday/yesterday-api.service /etc/systemd/system/

# Reload systemd
systemctl daemon-reload

# Enable service to start on boot
systemctl enable yesterday-api

# Start the service
systemctl start yesterday-api

# Show status
systemctl status yesterday-api

echo ""
echo "✅ Yesterday API systemd service installed"
echo ""
echo "Commands:"
echo "  systemctl status yesterday-api  # Check status"
echo "  systemctl restart yesterday-api # Restart service"
echo "  systemctl stop yesterday-api    # Stop service"
echo "  journalctl -u yesterday-api -f  # View logs"
echo ""
echo "API will be accessible at http://localhost:8082"
echo "Logs: /var/log/yesterday-api.log"
