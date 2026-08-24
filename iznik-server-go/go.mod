module github.com/freegle/iznik-server-go

go 1.23.0

toolchain go1.23.12

require (
	github.com/aws/aws-lambda-go v1.47.0
	github.com/awslabs/aws-lambda-go-api-proxy v0.16.2
	// Required when building with -tags embed_native (native ONNX pool).
	// ONNX Runtime C library must also be installed; see Dockerfile.
	github.com/daulet/tokenizers v1.27.0
	github.com/getsentry/sentry-go v0.29.0
	github.com/go-sql-driver/mysql v1.8.1
	github.com/gofiber/fiber/v2 v2.52.5
	github.com/gofiber/utils v1.1.0
	github.com/golang-jwt/jwt/v4 v4.5.0
	github.com/kellydunn/golang-geo v0.7.0
	github.com/rocketlaunchr/mysql-go v1.1.3
	github.com/srwiley/oksvg v0.0.0-20221011165216-be6e8873101c
	github.com/srwiley/rasterx v0.0.0-20220730225603-2ab79fcdd4ef
	github.com/stretchr/testify v1.10.0
	github.com/stripe/stripe-go/v82 v82.5.1
	github.com/tidwall/geodesic v0.3.5
	github.com/valyala/fasthttp v1.55.0
	github.com/yalue/onnxruntime_go v1.30.1
	golang.org/x/text v0.22.0
	gorm.io/driver/mysql v1.5.7
	gorm.io/gorm v1.31.0
	mvdan.cc/xurls/v2 v2.5.0
)

require (
	github.com/oschwald/maxminddb-golang v1.13.1
	golang.org/x/net v0.26.0
	gorm.io/plugin/dbresolver v1.6.2
	modernc.org/sqlite v1.38.0
)

require (
	filippo.io/edwards25519 v1.1.0 // indirect
	github.com/andybalholm/brotli v1.1.0 // indirect
	github.com/cenkalti/backoff/v4 v4.3.0 // indirect
	github.com/davecgh/go-spew v1.1.2-0.20180830191138-d8f796af33cc // indirect
	github.com/dustin/go-humanize v1.0.1 // indirect
	github.com/erikstmartin/go-testdb v0.0.0-20160219214506-8d10e4a1bae5 // indirect
	github.com/google/uuid v1.6.0 // indirect
	github.com/jinzhu/inflection v1.0.0 // indirect
	github.com/jinzhu/now v1.1.5 // indirect
	github.com/jmoiron/sqlx v1.4.0 // indirect
	github.com/klauspost/compress v1.17.9 // indirect
	github.com/kylelemons/go-gypsy v1.0.0 // indirect
	github.com/lib/pq v1.10.9 // indirect
	github.com/mattn/go-colorable v0.1.13 // indirect
	github.com/mattn/go-isatty v0.0.20 // indirect
	github.com/mattn/go-runewidth v0.0.15 // indirect
	github.com/ncruces/go-strftime v1.0.0 // indirect
	github.com/ory/dockertest v0.0.0-00010101000000-000000000000 // indirect
	github.com/ory/dockertest/v3 v3.12.0 // indirect
	github.com/peterstace/simplefeatures v0.59.0 // indirect
	github.com/philhofer/fwd v1.1.2 // indirect
	github.com/pmezard/go-difflib v1.0.1-0.20181226105442-5d4384ee4fb2 // indirect
	github.com/remyoudompheng/bigfft v0.0.0-20230129092748-24d4a6f8daec // indirect
	github.com/rivo/uniseg v0.4.4 // indirect
	github.com/stretchr/objx v0.5.2 // indirect
	github.com/tinylib/msgp v1.1.8 // indirect
	github.com/valyala/bytebufferpool v1.0.0 // indirect
	github.com/valyala/tcplisten v1.0.0 // indirect
	github.com/ziutek/mymysql v1.5.4 // indirect
	golang.org/x/exp v0.0.0-20250620022241-b7579e27df2b // indirect
	golang.org/x/image v0.23.0 // indirect
	golang.org/x/sync v0.15.0 // indirect
	golang.org/x/sys v0.33.0 // indirect
	gopkg.in/yaml.v3 v3.0.1 // indirect
	modernc.org/libc v1.66.3 // indirect
	modernc.org/mathutil v1.7.1 // indirect
	modernc.org/memory v1.11.0 // indirect
)

// Fix for dockertest v3.3.5 incompatibility with modern runc
replace github.com/ory/dockertest => github.com/ory/dockertest/v3 v3.10.0
