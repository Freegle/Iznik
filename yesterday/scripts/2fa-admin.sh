#!/bin/bash
# Admin CLI for managing Yesterday 2FA users

set -e

# Load environment from yesterday/.env if not already set
ENV_FILE="/var/www/FreegleDocker/yesterday/.env"
if [ -f "$ENV_FILE" ]; then
    if [ -z "$YESTERDAY_ADMIN_KEY" ]; then
        export YESTERDAY_ADMIN_KEY=$(grep -v '^#' "$ENV_FILE" | grep YESTERDAY_ADMIN_KEY | cut -d'=' -f2-)
    fi
    if [ -z "$BACKUP_BASIC_AUTH" ]; then
        export BACKUP_BASIC_AUTH=$(grep -v '^#' "$ENV_FILE" | grep BACKUP_BASIC_AUTH | cut -d'=' -f2-)
    fi
fi

GATEWAY_URL="${GATEWAY_URL:-http://localhost:8084}"
ADMIN_KEY="${YESTERDAY_ADMIN_KEY}"
BASIC_AUTH="${BACKUP_BASIC_AUTH}"

if [ -z "$ADMIN_KEY" ]; then
    echo "Error: YESTERDAY_ADMIN_KEY environment variable not set"
    echo "Set it in /var/www/FreegleDocker/yesterday/.env"
    exit 1
fi

if [ -z "$BASIC_AUTH" ]; then
    echo "Error: BACKUP_BASIC_AUTH environment variable not set"
    echo "Set it in /var/www/FreegleDocker/yesterday/.env"
    exit 1
fi

# Parse username:password from BASIC_AUTH
BASIC_AUTH_USER=$(echo "$BASIC_AUTH" | cut -d':' -f1)
BASIC_AUTH_PASS=$(echo "$BASIC_AUTH" | cut -d':' -f2-)

command=$1

case "$command" in
    list)
        echo "Listing 2FA users..."
        curl -s -u "$BASIC_AUTH_USER:$BASIC_AUTH_PASS" -H "X-Admin-Key: $ADMIN_KEY" "$GATEWAY_URL/admin/users" | jq '.'
        ;;

    add)
        username=$2
        if [ -z "$username" ]; then
            echo "Usage: $0 add <username>"
            exit 1
        fi

        echo "Creating user: $username"
        response=$(curl -s -u "$BASIC_AUTH_USER:$BASIC_AUTH_PASS" \
                       -H "X-Admin-Key: $ADMIN_KEY" \
                       -H "Content-Type: application/json" \
                       -d "{\"username\":\"$username\"}" \
                       "$GATEWAY_URL/admin/users")

        echo "$response" | jq '.'

        qr_url=$(echo "$response" | jq -r '.qr_code_url')
        if [ "$qr_url" != "null" ]; then
            echo ""
            echo "Setup instructions:"
            echo "1. Install Google Authenticator on your phone"
            echo "2. Scan this QR code:"
            echo ""
            qrencode -t ANSIUTF8 "$qr_url"
            echo ""
            echo "Or manually enter this secret:"
            echo "$response" | jq -r '.secret'
        fi
        ;;

    delete|remove)
        username=$2
        if [ -z "$username" ]; then
            echo "Usage: $0 delete <username>"
            exit 1
        fi

        echo "Deleting user: $username"
        curl -s -u "$BASIC_AUTH_USER:$BASIC_AUTH_PASS" -X DELETE -H "X-Admin-Key: $ADMIN_KEY" "$GATEWAY_URL/admin/users/$username" | jq '.'
        ;;

    status)
        echo "2FA Gateway Status:"
        curl -s "$GATEWAY_URL/health" | jq '.'
        ;;

    *)
        echo "Yesterday 2FA User Management"
        echo ""
        echo "Usage: $0 <command> [arguments]"
        echo ""
        echo "Commands:"
        echo "  list                    List all users"
        echo "  add <username>          Add a new user"
        echo "  delete <username>       Delete a user"
        echo "  status                  Show gateway status"
        echo ""
        echo "Environment variables:"
        echo "  YESTERDAY_ADMIN_KEY     Admin API key (required)"
        echo "  BACKUP_BASIC_AUTH       Basic auth credentials username:password (required)"
        echo "  GATEWAY_URL             Gateway URL (default: http://localhost:8084)"
        exit 1
        ;;
esac
