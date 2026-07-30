package item

import (
	"math"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// Weight estimation and reuse-impact lookup for free-text item names.
//
// Ported from iznik-batch/app/Services/ItemService.php (estimateWeight() and
// the wordsInCommon/canonSentence/canonWord word-similarity helpers, ported
// there "verbatim from V1 Utils"). This file is READ-ONLY: unlike
// ItemService::findOrCreate(), it never inserts novel names into the items
// catalog - a public endpoint must not be able to pollute it.

// Source values for the ImpactResponse.Source field.
const (
	SourceItems   = "items"
	SourceWeights = "weights"
	SourceAverage = "average"
)

// maxImpactQty caps the caller-supplied qty so a single request can't
// produce an unbounded totalWeightKg/co2eKg/benefitGbp in the response.
const maxImpactQty = 100000

// wordSplitRe mirrors PHP's preg_split('/\s+/', $sentence). PCRE's default
// \s class includes vertical tab (0x0B), unlike Go RE2's \s, so the class is
// spelled out explicitly to match PHP's tokenisation exactly.
var wordSplitRe = regexp.MustCompile("[\t\n\f\r \v]+")

// nonAlphanumericRe mirrors PHP's preg_replace('/[^\da-z]/i', ”, $word)
// applied after strtolower() - i.e. strip everything but digits and letters.
var nonAlphanumericRe = regexp.MustCompile(`[^0-9a-z]`)

// canonWord canonicalises a single word for fuzzy matching: lowercase, strip
// non-alphanumerics, and - for words longer than 3 characters - sort the
// letters alphabetically so typos/anagrams of the same word still match.
// Ported verbatim from ItemService::canonWord().
func canonWord(word string) string {
	word = strings.ToLower(word)
	word = nonAlphanumericRe.ReplaceAllString(word, "")

	if len(word) > 3 {
		b := []byte(word)
		sort.Slice(b, func(i, j int) bool { return b[i] < b[j] })
		word = string(b)
	}

	return word
}

// canonSentence splits a sentence on whitespace, canonicalises each word,
// and de-duplicates while preserving first-seen order (matches PHP's
// array_values(array_unique(...))). Ported from ItemService::canonSentence().
func canonSentence(sentence string) []string {
	words := wordSplitRe.Split(sentence, -1)

	seen := make(map[string]bool, len(words))
	canon := make([]string, 0, len(words))
	for _, w := range words {
		cw := canonWord(w)
		if !seen[cw] {
			seen[cw] = true
			canon = append(canon, cw)
		}
	}

	return canon
}

// wordsInCommon returns the percentage (0-100) of words two sentences have
// in common, relative to the longer sentence's (de-duplicated) word count.
// Ported verbatim from ItemService::wordsInCommon(), including its
// single-word-list edge case (returns 0, not 100, when the longer list has
// exactly one word).
func wordsInCommon(sentence1, sentence2 string) float64 {
	words1 := canonSentence(sentence1)
	words2 := canonSentence(sentence2)

	ret := 0
	for _, w1 := range words1 {
		for _, w2 := range words2 {
			if w1 == w2 {
				ret++
			}
		}
	}

	limit := len(words1)
	if len(words2) > limit {
		limit = len(words2)
	}

	if limit == 1 {
		return 0
	}

	return 100 * float64(ret) / float64(limit)
}

// standardWeight is one row of the `weights` reference table, with
// simplename preferred over name - matches ItemService::standardWeights()'s
// `CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END`.
type standardWeight struct {
	Name   string  `gorm:"column:name"`
	Weight float64 `gorm:"column:weight"`
}

// loadStandardWeights fetches every row of the `weights` reference table.
func loadStandardWeights(db *gorm.DB) []standardWeight {
	var rows []standardWeight
	db.Raw("SELECT CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight FROM weights").Scan(&rows)
	return rows
}

// estimateWeightFromStandardWeights picks the `weights` entry with the most
// words in common with name, and returns it only if the match is good
// enough (V1/ItemService threshold: words-in-common strictly > 10%). Ported
// verbatim from ItemService::estimateWeight().
func estimateWeightFromStandardWeights(name string, weights []standardWeight) (weight float64, matched string, ok bool) {
	var bestWeight float64
	var bestName string
	var bestWic float64
	found := false

	for _, w := range weights {
		wic := wordsInCommon(name, w.Name)
		if !found || wic > bestWic {
			bestWic = wic
			bestWeight = w.Weight
			bestName = w.Name
			found = true
		}
	}

	if found && bestWic > 10 {
		return bestWeight, bestName, true
	}

	return 0, "", false
}

// itemsExactMatch is the row shape for the exact case-insensitive `items`
// lookup.
type itemsExactMatch struct {
	Name   string  `gorm:"column:name"`
	Weight float64 `gorm:"column:weight"`
}

// findExactItemWeight looks up an exact match in the `items` catalog with a
// usable (non-null, non-zero) weight. The `items.name` column has a UNIQUE
// index under the table's default utf8mb4_unicode_ci collation, which is
// already case-insensitive, so a plain equality comparison both matches
// case-insensitively and uses the index - matching the convention used
// elsewhere in this codebase (e.g. message/message.go, test/testUtils.go).
// Wrapping the column in LOWER() would prevent the unique index from being
// used, forcing a full table scan on every call. This is a plain SELECT -
// it never inserts, unlike ItemService::findOrCreate().
func findExactItemWeight(db *gorm.DB, name string) (weight float64, matched string, ok bool) {
	var row itemsExactMatch
	result := db.Raw(
		"SELECT name, weight FROM items WHERE name = ? AND weight IS NOT NULL AND weight != 0 LIMIT 1",
		name,
	).Scan(&row)

	if result.Error != nil || row.Name == "" {
		return 0, "", false
	}

	return row.Weight, row.Name, true
}

// populationAverageWeight returns the popularity-weighted average item
// weight across the whole `items` catalog:
// SUM(popularity*weight)/SUM(popularity) WHERE weight IS NOT NULL AND weight
// != 0 - identical to AuthorityStatsService.php, StatsGenerationService.php
// and authority/stats.go's GetStatsByAuthority. Used as the last-resort
// fallback when neither an exact items match nor a fuzzy weights match is
// found. Returns 0 if the catalog has no usable weights yet.
func populationAverageWeight(db *gorm.DB) float64 {
	var result struct {
		Average *float64 `gorm:"column:average"`
	}

	db.Raw("SELECT SUM(popularity*weight)/SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0").Scan(&result)

	if result.Average == nil {
		return 0
	}

	return *result.Average
}

// ImpactResponse is the response shape for GET /item/impact.
type ImpactResponse struct {
	Name            string  `json:"name"`
	Matched         *string `json:"matched"`
	Source          string  `json:"source"`
	Qty             int     `json:"qty"`
	PerUnitWeightKg float64 `json:"perUnitWeightKg"`
	TotalWeightKg   float64 `json:"totalWeightKg"`
	Co2eKg          float64 `json:"co2eKg"`
	BenefitGbp      float64 `json:"benefitGbp"`
	BenefitPerTonne float64 `json:"benefitPerTonne"`
	Year            int     `json:"year"`
}

// round2dp rounds to 2 decimal places for tidy JSON output.
func round2dp(v float64) float64 {
	return math.Round(v*100) / 100
}

// Impact estimates the weight, CO2e and financial benefit of reusing qty
// units of a free-text item name.
// @Summary Estimate reuse impact for an item name
// @Description Estimates weight, CO2e saved and financial benefit of reuse for qty units of a free-text item name. Read-only: never writes to the items catalog. Lookup order: (1) exact case-insensitive match against the items catalog with a known weight, (2) fuzzy word-overlap match (>10%) against the standard weights reference table, (3) popularity-weighted average item weight.
// @Tags item
// @Produce json
// @Param name query string true "Free-text item name"
// @Param qty query integer false "Quantity (default 1)"
// @Success 200 {object} item.ImpactResponse
// @Failure 400 {object} fiber.Error "Missing or empty name"
// @Router /item/impact [get]
func Impact(c *fiber.Ctx) error {
	name := strings.TrimSpace(c.Query("name"))
	if name == "" {
		return fiber.NewError(fiber.StatusBadRequest, "name is required")
	}

	qty := c.QueryInt("qty", 1)
	if qty < 1 {
		qty = 1
	}
	if qty > maxImpactQty {
		qty = maxImpactQty
	}

	db := database.DBConn

	var (
		perUnitWeightKg float64
		matched         *string
		source          string
	)

	// (a) Exact case-insensitive items match with a usable weight.
	if weight, matchedName, ok := findExactItemWeight(db, name); ok {
		perUnitWeightKg = weight
		m := matchedName
		matched = &m
		source = SourceItems
	} else if weight, matchedName, ok := estimateWeightFromStandardWeights(name, loadStandardWeights(db)); ok {
		// (b) Fuzzy match against the standard weights reference table.
		perUnitWeightKg = weight
		m := matchedName
		matched = &m
		source = SourceWeights
	} else {
		// (c) Popularity-weighted average across the whole catalog.
		perUnitWeightKg = populationAverageWeight(db)
		matched = nil
		source = SourceAverage
	}

	totalWeightKg := perUnitWeightKg * float64(qty)

	year := time.Now().UTC().Year()
	cpiData := LoadCPIData(db)
	benefitPerTonne := GetBenefitPerTonne(year, cpiData)

	co2eKg := totalWeightKg * CO2PerTonne
	benefitGbp := totalWeightKg * benefitPerTonne / 1000

	return c.JSON(ImpactResponse{
		Name:            name,
		Matched:         matched,
		Source:          source,
		Qty:             qty,
		PerUnitWeightKg: round2dp(perUnitWeightKg),
		TotalWeightKg:   round2dp(totalWeightKg),
		Co2eKg:          round2dp(co2eKg),
		BenefitGbp:      round2dp(benefitGbp),
		BenefitPerTonne: benefitPerTonne,
		Year:            year,
	})
}
