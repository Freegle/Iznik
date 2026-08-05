package abtest

import (
	"math/rand"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type GetABTestRequest struct {
	UID string `query:"uid"`
}

type PostABTestRequest struct {
	UID     string `json:"uid"`
	Variant string `json:"variant"`
	Shown   *bool  `json:"shown"`
	Action  *bool  `json:"action"`
	Score   *int   `json:"score"`
	App     *bool  `json:"app"`
}

type ABTestVariant struct {
	ID      uint64  `json:"id"`
	UID     string  `json:"uid"`
	Variant string  `json:"variant"`
	Shown   uint64  `json:"shown"`
	Action  uint64  `json:"action"`
	Rate    float64 `json:"rate"`
	Suggest bool    `json:"suggest"`
}

func GetABTest(c *fiber.Ctx) error {
	uid := c.Query("uid")
	if uid == "" {
		return fiber.NewError(fiber.StatusBadRequest, "uid is required")
	}

	db := database.DBConn

	var variants []ABTestVariant
	// ORM migration site b8d3220fdb2f (wave 1).
	db.Table("abtest").Where("uid = ? AND suggest = 1", uid).Order("rate DESC, RAND()").Scan(&variants)

	if len(variants) == 0 {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "variant": nil})
	}

	chosen := chooseVariant(variants, rand.Float64, rand.Intn)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "variant": chosen})
}

// chooseVariant applies an epsilon-greedy bandit over the suggestible variants
// (already sorted by rate DESC): 10% exploration (a uniformly random pick) and
// 90% exploitation (the best-rate variant). Callers pass a non-empty slice
// (GetABTest returns early when there are no variants).
//
// The randomness is injected (randFloat/randIntn) purely so both branches are
// reachable from deterministic unit tests. Inline, the selection sits after the
// DB query, so it was exercised only by the live-DB integration test — where the
// 10% exploration branch is hit only ~1 run in 10 (Go auto-seeds the global
// math/rand source). That made abtest.go's reported coverage flap between 95.34%
// and 97.67% run-to-run and intermittently tripped the Coveralls "coverage
// decreased" gate on unrelated PRs.
func chooseVariant(variants []ABTestVariant, randFloat func() float64, randIntn func(int) int) ABTestVariant {
	if randFloat() < 0.1 {
		return variants[randIntn(len(variants))]
	}
	return variants[0]
}

func PostABTest(c *fiber.Ctx) error {
	var req PostABTestRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	// Ignore app requests (old code may contaminate experiments)
	if req.App != nil && *req.App {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	if req.UID == "" || req.Variant == "" {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	db := database.DBConn

	if req.Shown != nil && *req.Shown {
		// ORM migration site 7e75c8eb601d (wave 3). DoUpdates is an explicit
		// ordered clause.Set, not clause.Assignments(map...): rate's expression
		// reads shown, which this same statement also assigns, and MySQL
		// evaluates a SET list left to right - shown must stay first, exactly
		// as the original wrote it, so rate picks up the incremented value.
		// clause.Assignments(map...) sorts keys alphabetically ("rate" before
		// "shown"), which would silently swap the order and change the result.
		db.Table("abtest").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "shown"}, Value: gorm.Expr("shown + 1")},
				{Column: clause.Column{Name: "rate"}, Value: gorm.Expr("COALESCE(100 * action / shown, 0)")},
			},
		}).Create(map[string]interface{}{
			"uid":     req.UID,
			"variant": req.Variant,
			"shown":   gorm.Expr("1"),
			"action":  gorm.Expr("0"),
			"rate":    gorm.Expr("0"),
		})
	}

	if req.Action != nil && *req.Action {
		score := 1
		if req.Score != nil && *req.Score > 0 {
			score = *req.Score
		}
		// ORM migration site 7e4882220657 (wave 3). Same reasoning as
		// 7e75c8eb601d above: rate reads action, which this statement also
		// assigns, so action must stay first in an explicit ordered Set.
		db.Table("abtest").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "action"}, Value: gorm.Expr("action + ?", score)},
				{Column: clause.Column{Name: "rate"}, Value: gorm.Expr("COALESCE(100 * action / shown, 0)")},
			},
		}).Create(map[string]interface{}{
			"uid":     req.UID,
			"variant": req.Variant,
			"shown":   gorm.Expr("0"),
			"action":  score,
			"rate":    gorm.Expr("0"),
		})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
