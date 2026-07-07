# Moving Go API to Netlify with Secure Database Connections

## Overview

This document outlines the complete process for deploying Go applications on Netlify Functions with secure database connections to a Percona MySQL cluster behind HAProxy using client certificate authentication.

## Feasibility and Benefits

**✅ Yes, Go on Netlify is fully supported** for serverless functions with external database connections.

### Key Benefits
- **Serverless scaling** - Automatic scaling based on demand
- **Cost-effective** - Pay only for actual usage
- **Global edge distribution** - Functions run close to users
- **Built-in CI/CD** - Automatic deployments from Git
- **Environment variable management** - Secure credential storage

### Considerations
- **Stateless functions** - No persistent connections between invocations
- **Cold starts** - Initial latency when functions haven't run recently
- **Connection management** - Need efficient database connection handling

## Certificate Infrastructure Setup

### 1. Create Certificate Authority (CA)

```bash
# Create CA private key
openssl genrsa -out ca-key.pem 4096

# Create CA certificate (10 year validity)
openssl req -new -x509 -days 3650 -key ca-key.pem -sha256 -out ca-cert.pem \
  -subj "/C=US/ST=State/L=City/O=YourOrg/OU=IT/CN=YourOrg-CA"
```

### 2. Generate Server Certificate for HAProxy

```bash
# Create server private key
openssl genrsa -out server-key.pem 4096

# Create server certificate signing request
openssl req -subj "/C=US/ST=State/L=City/O=YourOrg/OU=IT/CN=mysql.yourdomain.com" \
  -sha256 -new -key server-key.pem -out server.csr

# Create server certificate extensions file
cat > server-extfile.cnf << EOF
subjectAltName = DNS:mysql.yourdomain.com,DNS:haproxy.yourdomain.com,IP:YOUR_HAPROXY_IP
extendedKeyUsage = serverAuth
EOF

# Sign server certificate with CA (2 year validity)
openssl x509 -req -days 730 -sha256 -in server.csr -CA ca-cert.pem -CAkey ca-key.pem \
  -out server-cert.pem -extfile server-extfile.cnf -CAcreateserial

# Create PEM bundle for HAProxy (cert + key)
cat server-cert.pem server-key.pem > server.pem
```

### 3. Generate Client Certificate for Netlify

```bash
# Create client private key
openssl genrsa -out netlify-client-key.pem 4096

# Create client certificate signing request
openssl req -subj "/C=US/ST=State/L=City/O=YourOrg/OU=Netlify/CN=netlify-client" \
  -new -key netlify-client-key.pem -out netlify-client.csr

# Create client certificate extensions file
cat > client-extfile.cnf << EOF
extendedKeyUsage = clientAuth
EOF

# Sign client certificate with CA (1 year validity)
openssl x509 -req -days 365 -sha256 -in netlify-client.csr -CA ca-cert.pem -CAkey ca-key.pem \
  -out netlify-client-cert.pem -extfile client-extfile.cnf -CAcreateserial
```

### 4. Set Proper Permissions

```bash
# Secure private keys
chmod 400 ca-key.pem server-key.pem netlify-client-key.pem

# Public certificates can be more permissive
chmod 444 ca-cert.pem server-cert.pem netlify-client-cert.pem server.pem

# Clean up CSR and config files
rm server.csr netlify-client.csr server-extfile.cnf client-extfile.cnf
```

### 5. Deploy Certificates to HAProxy

```bash
# Copy to HAProxy SSL directory
sudo mkdir -p /etc/ssl/haproxy
sudo cp server.pem /etc/ssl/haproxy/
sudo cp ca-cert.pem /etc/ssl/haproxy/
sudo chown -R haproxy:haproxy /etc/ssl/haproxy/
sudo chmod 400 /etc/ssl/haproxy/server.pem
sudo chmod 444 /etc/ssl/haproxy/ca-cert.pem
```

## HAProxy Configuration with mTLS

### Complete HAProxy Configuration

```haproxy
global
    maxconn 4096
    tune.ssl.default-dh-param 2048
    ssl-default-bind-ciphers ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384
    ssl-default-bind-options ssl-min-ver TLSv1.2
    log stdout local0

defaults
    mode tcp
    timeout connect 5000ms
    timeout client 50000ms
    timeout server 50000ms
    option tcplog

frontend mysql_ssl_frontend
    # SSL termination with client certificate validation
    bind *:3306 ssl crt /etc/ssl/haproxy/server.pem ca-file /etc/ssl/haproxy/ca-cert.pem verify required
    mode tcp
    option tcplog

    # Basic connection limit (hardware protection)
    maxconn 1000

    # Reject expired certificates (automatic validation)
    tcp-request content reject unless { ssl_c_notafter,date(-now) gt 0 }

    # Valid certificate = access granted
    use_backend mysql_cluster

backend mysql_cluster
    mode tcp
    balance roundrobin

    # Health check user for MySQL monitoring
    option mysql-check user haproxy_check

    # Plain text connections to private network backend
    server mysql1 10.0.1.10:3306 check maxconn 200
    server mysql2 10.0.1.11:3306 check maxconn 200
    server mysql3 10.0.1.12:3306 check maxconn 200

# Optional: Statistics interface for monitoring
listen stats
    bind *:8404
    mode http
    stats enable
    stats uri /stats
    stats refresh 30s
    stats admin if TRUE
```

### DoS Protection Features

- **Certificate validation blocks attackers** before they reach the backend
- **Automatic certificate expiration checking**
- **Connection limits** at multiple levels (global, frontend, backend)
- **SSL termination** only at the edge for performance
- **No rate limiting needed** since certificate validation is the primary gate

## Netlify Environment Variables Setup

### Prepare Certificates for Environment Variables

```bash
# Convert certificates to base64 for Netlify environment variables
CA_CERT_B64=$(base64 -w 0 ca-cert.pem)
CLIENT_CERT_B64=$(base64 -w 0 netlify-client-cert.pem)
CLIENT_KEY_B64=$(base64 -w 0 netlify-client-key.pem)

# Set in Netlify dashboard or via CLI
netlify env:set CA_CERT_PEM "$CA_CERT_B64"
netlify env:set CLIENT_CERT_PEM "$CLIENT_CERT_B64"
netlify env:set CLIENT_KEY_PEM "$CLIENT_KEY_B64"
netlify env:set DB_HOST "mysql.yourdomain.com"
netlify env:set DB_PORT "3306"
netlify env:set DB_USERNAME "netlify_app"
netlify env:set DB_PASSWORD "your_secure_password"
netlify env:set DB_NAME "app_database"
```

### Environment Variables Reference

| Variable | Description | Example |
|----------|-------------|---------|
| `CA_CERT_PEM` | Base64 encoded CA certificate | `LS0tLS1CRUdJTi...` |
| `CLIENT_CERT_PEM` | Base64 encoded client certificate | `LS0tLS1CRUdJTi...` |
| `CLIENT_KEY_PEM` | Base64 encoded client private key | `LS0tLS1CRUdJTi...` |
| `DB_HOST` | HAProxy hostname | `mysql.yourdomain.com` |
| `DB_PORT` | HAProxy port | `3306` |
| `DB_USERNAME` | Database username | `netlify_app` |
| `DB_PASSWORD` | Database password | `your_secure_password` |
| `DB_NAME` | Database name | `app_database` |

## Go Client Implementation

### TLS Configuration Setup

```go
package main

import (
    "crypto/tls"
    "crypto/x509"
    "database/sql"
    "encoding/base64"
    "fmt"
    "os"
    "time"

    "github.com/go-sql-driver/mysql"
    _ "github.com/go-sql-driver/mysql"
)

func init() {
    // Setup TLS configuration for MySQL connections
    if err := setupTLSConfig(); err != nil {
        panic(fmt.Sprintf("Failed to setup TLS config: %v", err))
    }
}

func setupTLSConfig() error {
    // Decode base64 certificates from environment variables
    caCertPEM, err := base64.StdEncoding.DecodeString(os.Getenv("CA_CERT_PEM"))
    if err != nil {
        return fmt.Errorf("failed to decode CA certificate: %w", err)
    }

    clientCertPEM, err := base64.StdEncoding.DecodeString(os.Getenv("CLIENT_CERT_PEM"))
    if err != nil {
        return fmt.Errorf("failed to decode client certificate: %w", err)
    }

    clientKeyPEM, err := base64.StdEncoding.DecodeString(os.Getenv("CLIENT_KEY_PEM"))
    if err != nil {
        return fmt.Errorf("failed to decode client key: %w", err)
    }

    // Load client certificate and key
    cert, err := tls.X509KeyPair(clientCertPEM, clientKeyPEM)
    if err != nil {
        return fmt.Errorf("failed to load client certificate: %w", err)
    }

    // Load CA certificate
    caCertPool := x509.NewCertPool()
    if !caCertPool.AppendCertsFromPEM(caCertPEM) {
        return fmt.Errorf("failed to parse CA certificate")
    }

    // Configure TLS with client certificate
    tlsConfig := &tls.Config{
        Certificates: []tls.Certificate{cert},
        RootCAs:      caCertPool,
        ServerName:   os.Getenv("DB_HOST"),
        MinVersion:   tls.VersionTLS12,
    }

    // Register TLS config with MySQL driver
    mysql.RegisterTLSConfig("custom", tlsConfig)
    return nil
}

func GetDBConnection() (*sql.DB, error) {
    // Build connection string with TLS
    dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?tls=custom&timeout=30s&readTimeout=30s&writeTimeout=30s",
        os.Getenv("DB_USERNAME"),
        os.Getenv("DB_PASSWORD"),
        os.Getenv("DB_HOST"),
        os.Getenv("DB_PORT"),
        os.Getenv("DB_NAME"),
    )

    db, err := sql.Open("mysql", dsn)
    if err != nil {
        return nil, fmt.Errorf("failed to open database connection: %w", err)
    }

    // Configure connection pool for serverless environment
    db.SetMaxOpenConns(10)        // Limit concurrent connections
    db.SetMaxIdleConns(2)         // Minimal idle connections
    db.SetConnMaxLifetime(300 * time.Second) // 5 minute max lifetime

    // Test the connection
    if err := db.Ping(); err != nil {
        db.Close()
        return nil, fmt.Errorf("failed to ping database: %w", err)
    }

    return db, nil
}
```

### Netlify Function Example

```go
package main

import (
    "context"
    "encoding/json"
    "fmt"
    "net/http"

    "github.com/aws/aws-lambda-go/events"
    "github.com/aws/aws-lambda-go/lambda"
)

func handler(ctx context.Context, request events.APIGatewayProxyRequest) (events.APIGatewayProxyResponse, error) {
    // Get database connection with client certificate authentication
    db, err := GetDBConnection()
    if err != nil {
        return events.APIGatewayProxyResponse{
            StatusCode: 500,
            Body:       fmt.Sprintf(`{"error": "Database connection failed: %v"}`, err),
        }, nil
    }
    defer db.Close()

    // Example query
    var count int
    err = db.QueryRowContext(ctx, "SELECT COUNT(*) FROM users").Scan(&count)
    if err != nil {
        return events.APIGatewayProxyResponse{
            StatusCode: 500,
            Body:       fmt.Sprintf(`{"error": "Query failed: %v"}`, err),
        }, nil
    }

    response := map[string]interface{}{
        "userCount": count,
        "message":   "Successfully connected with client certificate",
    }

    responseBody, _ := json.Marshal(response)

    return events.APIGatewayProxyResponse{
        StatusCode: 200,
        Headers: map[string]string{
            "Content-Type": "application/json",
        },
        Body: string(responseBody),
    }, nil
}

func main() {
    lambda.Start(handler)
}
```

## Database Security Setup

### MySQL User Configuration

```sql
-- Create health check user for HAProxy (no password needed for health checks)
CREATE USER 'haproxy_check'@'10.0.1.%' IDENTIFIED WITH 'mysql_native_password';

-- Create application user with minimal privileges
CREATE USER 'netlify_app'@'10.0.1.%' IDENTIFIED BY 'your_secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON app_database.* TO 'netlify_app'@'10.0.1.%';

-- Optional: Create read-only user for reporting functions
CREATE USER 'netlify_readonly'@'10.0.1.%' IDENTIFIED BY 'readonly_password';
GRANT SELECT ON app_database.* TO 'netlify_readonly'@'10.0.1.%';

FLUSH PRIVILEGES;
```

### Security Best Practices

1. **Principle of least privilege** - Grant only necessary permissions
2. **Network restrictions** - Use IP ranges for database users (10.0.1.% for HAProxy subnet)
3. **Strong passwords** - Use complex, unique passwords for each user
4. **Regular auditing** - Monitor database access and failed login attempts

## Certificate Management

### Certificate Lifecycle

| Certificate Type | Validity Period | Renewal Strategy |
|------------------|-----------------|------------------|
| **CA Certificate** | 10 years | Long-term stability, manual renewal |
| **Server Certificate** | 2 years | Automated renewal before expiration |
| **Client Certificate** | 1 year | Regular rotation for security |

### Certificate Rotation Process

```bash
# 1. Generate new client certificate (before expiration)
openssl genrsa -out netlify-client-key-new.pem 4096
openssl req -subj "/C=US/ST=State/L=City/O=YourOrg/OU=Netlify/CN=netlify-client-new" \
  -new -key netlify-client-key-new.pem -out netlify-client-new.csr
openssl x509 -req -days 365 -sha256 -in netlify-client-new.csr -CA ca-cert.pem -CAkey ca-key.pem \
  -out netlify-client-cert-new.pem -extfile client-extfile.cnf -CAcreateserial

# 2. Update Netlify environment variables with new certificate
NEW_CLIENT_CERT_B64=$(base64 -w 0 netlify-client-cert-new.pem)
NEW_CLIENT_KEY_B64=$(base64 -w 0 netlify-client-key-new.pem)
netlify env:set CLIENT_CERT_PEM "$NEW_CLIENT_CERT_B64"
netlify env:set CLIENT_KEY_PEM "$NEW_CLIENT_KEY_B64"

# 3. Deploy and test new certificate
# 4. Revoke old certificate if needed
```

### Certificate Revocation

```bash
# Create certificate revocation list (CRL)
openssl ca -revoke netlify-client-cert-old.pem -keyfile ca-key.pem -cert ca-cert.pem
openssl ca -gencrl -keyfile ca-key.pem -cert ca-cert.pem -out revoked.crl

# Update HAProxy configuration to use CRL
# Add to bind line: crl-file /etc/ssl/haproxy/revoked.crl
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Certificate Validation Errors

**Error**: `x509: certificate signed by unknown authority`

**Solution**:
- Verify CA certificate is properly set in environment variables
- Check base64 encoding is correct
- Ensure CA certificate matches the one used to sign client certificate

#### 2. HAProxy SSL Handshake Failures

**Error**: `SSL handshake failure`

**Solution**:
- Check server certificate includes correct Subject Alternative Names
- Verify client certificate is not expired
- Ensure TLS version compatibility (minimum TLS 1.2)

#### 3. MySQL Connection Refused

**Error**: `connection refused` or `access denied`

**Solution**:
- Verify database user exists and has correct permissions
- Check HAProxy backend server IPs are correct
- Ensure MySQL health check user is configured

#### 4. Netlify Function Timeouts

**Error**: Function timeout during database operations

**Solution**:
- Optimize database queries
- Implement connection pooling properly
- Set appropriate timeout values in connection string

### Debugging Commands

```bash
# Test certificate validity
openssl x509 -in netlify-client-cert.pem -text -noout

# Check certificate expiration
openssl x509 -in netlify-client-cert.pem -noout -dates

# Test HAProxy SSL connection
openssl s_client -connect mysql.yourdomain.com:3306 -cert netlify-client-cert.pem -key netlify-client-key.pem

# View HAProxy statistics
curl -s http://haproxy.yourdomain.com:8404/stats

# Check HAProxy logs
sudo journalctl -u haproxy -f
```

## Security Considerations

### Network Security
- **Private backend network** - Percona cluster isolated from public internet
- **Firewall rules** - Restrict access to HAProxy from specific sources
- **VPC/subnet isolation** - Separate network segments for different components

### Application Security
- **Input validation** - Sanitize all user inputs before database queries
- **Prepared statements** - Use parameterized queries to prevent SQL injection
- **Error handling** - Don't expose internal errors to clients
- **Logging** - Log security events and failed authentication attempts

### Certificate Security
- **Private key protection** - Store private keys securely, never in source code
- **Regular rotation** - Implement automated certificate renewal
- **Monitoring** - Alert on certificate expiration and revocation events
- **Backup** - Secure backup of CA private key and certificates

## Performance Optimization

### Connection Management
- **Connection pooling** - Reuse connections when possible
- **Connection limits** - Prevent resource exhaustion
- **Health monitoring** - Regular health checks to avoid routing to failed backends

### HAProxy Optimization
- **SSL session caching** - Reuse SSL sessions for better performance
- **Keep-alive** - Enable keep-alive for persistent connections
- **Load balancing** - Distribute load evenly across Percona cluster nodes

### Netlify Function Optimization
- **Cold start reduction** - Minimize initialization code
- **Memory management** - Proper cleanup of database connections
- **Caching** - Cache frequently accessed data when appropriate

## Conclusion

This setup provides enterprise-grade security for Go applications on Netlify with:

- **Strong authentication** via client certificates
- **DoS protection** at the HAProxy level
- **Secure communication** with TLS encryption
- **High availability** through Percona cluster and HAProxy load balancing
- **Scalable architecture** with serverless functions

The certificate-based authentication ensures that only authorized Netlify functions can access your database, while HAProxy provides robust load balancing and protection for your Percona MySQL cluster.