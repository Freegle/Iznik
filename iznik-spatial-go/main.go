package main

import (
	"database/sql"
	"flag"
	"fmt"
	"log"
	"os"
	"sync"
	"sync/atomic"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
)

type server struct {
	mu         sync.RWMutex
	idx        *Index
	mysqlDB    *sql.DB
	idxPath    string
	rebuilding atomic.Bool
}

func (s *server) getIndex() *Index {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.idx
}

func (s *server) swapIndex(newIdx *Index) {
	s.mu.Lock()
	defer s.mu.Unlock()
	old := s.idx
	s.idx = newIdx
	if old != nil {
		old.Close()
	}
}

// rebuild builds a fresh index from MySQL and atomically replaces the live one.
// Returns immediately with an error if another rebuild is already in progress.
func (s *server) rebuild() error {
	if !s.rebuilding.CompareAndSwap(false, true) {
		return fmt.Errorf("rebuild already in progress")
	}
	defer s.rebuilding.Store(false)

	start := time.Now()
	tmpPath := s.idxPath + ".building"
	os.Remove(tmpPath)

	newIdx, err := CreateIndex(tmpPath)
	if err != nil {
		return fmt.Errorf("create index: %w", err)
	}

	if err := LoadFromMySQL(s.mysqlDB, newIdx); err != nil {
		newIdx.Close()
		os.Remove(tmpPath)
		return fmt.Errorf("load from mysql: %w", err)
	}
	newIdx.Close()

	if err := os.Rename(tmpPath, s.idxPath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("rename index: %w", err)
	}

	live, err := OpenIndex(s.idxPath)
	if err != nil {
		return fmt.Errorf("reopen index after rebuild: %w", err)
	}

	s.swapIndex(live)
	log.Printf("knn-server: index rebuilt and swapped in %s", time.Since(start).Round(time.Millisecond))
	return nil
}

func main() {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		getenv("MYSQL_USER", "iznik"),
		getenv("MYSQL_PASSWORD", "iz"),
		getenv("MYSQL_HOST", "localhost"),
		getenv("MYSQL_PORT", "3306"),
		getenv("MYSQL_DBNAME", "iznik"),
	)

	mysqlDB, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("mysql open: %v", err)
	}
	defer mysqlDB.Close()

	forceRebuild := flag.Bool("rebuild", false, "force a full index rebuild from MySQL on startup, ignoring any existing index file")
	flag.Parse()

	idxPath := getenv("KNN_INDEX_PATH", "/data/knn-locations.db")

	srv := &server{
		mysqlDB: mysqlDB,
		idxPath: idxPath,
	}

	// Open existing index unless --rebuild was requested.
	if !*forceRebuild {
		if existing, err := OpenIndex(idxPath); err == nil {
			srv.idx = existing
			log.Printf("knn-server: opened existing index from %s", idxPath)
		} else {
			log.Printf("knn-server: no usable index at %s (%v), building from MySQL...", idxPath, err)
			if err := srv.rebuild(); err != nil {
				log.Fatalf("initial build failed: %v", err)
			}
		}
	} else {
		log.Printf("knn-server: --rebuild requested, building fresh index from MySQL...")
		if err := srv.rebuild(); err != nil {
			log.Fatalf("forced rebuild failed: %v", err)
		}
	}

	go scheduledRebuild(srv)

	// Public API: /knn and /health.
	api := fiber.New(fiber.Config{DisableStartupMessage: true})

	api.Get("/health", func(c *fiber.Ctx) error {
		return c.JSON(fiber.Map{"status": "ok"})
	})

	api.Get("/knn", func(c *fiber.Ctx) error {
		lng := c.QueryFloat("lng", 0)
		lat := c.QueryFloat("lat", 0)
		if lng == 0 && lat == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "lng and lat required"})
		}
		idx := srv.getIndex()
		id, err := FindNearestArea(idx, lng, lat)
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{"locationid": id})
	})

	// Admin API: /rebuild on a separate port, not exposed externally.
	admin := fiber.New(fiber.Config{DisableStartupMessage: true})

	admin.Post("/rebuild", func(c *fiber.Ctx) error {
		if srv.rebuilding.Load() {
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{"error": "rebuild already in progress"})
		}
		go func() {
			if err := srv.rebuild(); err != nil {
				log.Printf("knn-server: async rebuild failed: %v", err)
			}
		}()
		return c.JSON(fiber.Map{"status": "rebuilding"})
	})

	port := getenv("KNN_PORT", "8194")
	adminPort := getenv("KNN_ADMIN_PORT", "8195")

	go func() {
		log.Printf("knn-server: admin listening on :%s", adminPort)
		if err := admin.Listen(":" + adminPort); err != nil {
			log.Fatalf("admin listener failed: %v", err)
		}
	}()

	log.Printf("knn-server: listening on :%s", port)
	log.Fatal(api.Listen(":" + port))
}

// scheduledRebuild triggers a nightly index rebuild at 03:00 UTC,
// matching the V1 nightly PostgreSQL sync cadence.
func scheduledRebuild(srv *server) {
	for {
		now := time.Now().UTC()
		next := time.Date(now.Year(), now.Month(), now.Day()+1, 3, 0, 0, 0, time.UTC)
		time.Sleep(time.Until(next))
		if err := srv.rebuild(); err != nil {
			log.Printf("knn-server: scheduled rebuild failed: %v", err)
		}
	}
}

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
