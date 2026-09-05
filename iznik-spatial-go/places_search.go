package main

// Search over the places index: token-prefix matching with a fuzzy fallback,
// ranked to reproduce what the geocode consumers relied on from photon
// (measured 2026-08-31 against the live instance):
//   - exact-name matches beat everything ("Devon" → the ceremonial county,
//     not "East Devon");
//   - among exact matches, bigger administrative footprints win ("West
//     Midlands" → the statistical region whose bbox WhatJobs uses to place
//     jobs, not the metropolitan county);
//   - the last query token matches as a prefix (autocomplete);
//   - a bbox parameter hard-filters, lat/lon bias the ranking;
//   - ", county" context tails restrict candidates (the WhatJobs
//     "Kenwyn, Cornwall" fallback).

import (
	"math"
	"sort"
	"strings"
)

type searchOpts struct {
	limit            int
	layers           map[string]bool
	bbox             *[4]float64 // swlng, swlat, nelng, nelat
	biasLat, biasLng *float64
	biasZoom         int // 0 = unset; photon defaults to 14
}

type scoredPlace struct {
	e     *PlaceEntry
	exact bool
	score float64
}

type placesIndex struct {
	entries []PlaceEntry
	tokens  []string           // sorted unique tokens across names and context
	post    map[string][]int32 // token -> entry indices
}

// placeEntityReplacer undoes the HTML-entity mangling seen in real feed
// queries ("batten&apos;s green") before normalisation.
var placeEntityReplacer = strings.NewReplacer(
	"&apos;", "'", "&#039;", "'", "&#39;", "'",
	"&amp;", " ", "&quot;", " ", "&nbsp;", " ",
)

// foldMap folds the diacritics that occur in UK place names (Welsh
// circumflexes included) to plain ASCII.
var foldMap = map[rune]string{
	'à': "a", 'á': "a", 'â': "a", 'ã': "a", 'ä': "a", 'å': "a", 'ā': "a",
	'è': "e", 'é': "e", 'ê': "e", 'ë': "e", 'ē': "e",
	'ì': "i", 'í': "i", 'î': "i", 'ï': "i", 'ī': "i",
	'ò': "o", 'ó': "o", 'ô': "o", 'ö': "o", 'õ': "o", 'ø': "o", 'ō': "o",
	'ù': "u", 'ú': "u", 'û': "u", 'ü': "u", 'ū': "u",
	'ŵ': "w", 'ŷ': "y", 'ý': "y", 'ỳ': "y", 'ÿ': "y",
	'ç': "c", 'ñ': "n", 'ß': "ss", 'æ': "ae", 'œ': "oe", 'ð': "d", 'þ': "th",
}

// normPlace lowercases, folds diacritics, drops apostrophes and turns all
// other punctuation into spaces.
func normPlace(s string) string {
	s = strings.ToLower(s)
	if strings.Contains(s, "&") {
		s = placeEntityReplacer.Replace(s)
	}
	var b strings.Builder
	b.Grow(len(s))
	for _, r := range s {
		switch {
		case (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9'):
			b.WriteRune(r)
		case r == '\'' || r == '’' || r == '`':
			// dropped: "Batten's" and "Battens" must meet
		default:
			if f, ok := foldMap[r]; ok {
				b.WriteString(f)
			} else {
				b.WriteByte(' ')
			}
		}
	}
	return strings.Join(strings.Fields(b.String()), " ")
}

func placeTokens(s string) []string {
	return strings.Fields(normPlace(s))
}

// buildPlacesIndex computes per-entry search fields and the token postings.
// Repeated strings (layers, counties, nations, OSM kinds) are interned so
// 195k copies of "England" collapse to one, and the per-entry token bag is a
// build-time scratch map, not a retained field.
func buildPlacesIndex(entries []PlaceEntry) *placesIndex {
	ix := &placesIndex{entries: entries, post: map[string][]int32{}}
	interned := map[string]string{}
	intern := func(s string) string {
		if v, ok := interned[s]; ok {
			return v
		}
		interned[s] = s
		return s
	}
	bag := map[string]bool{}
	for i := range ix.entries {
		e := &ix.entries[i]
		e.OsmType = intern(e.OsmType)
		e.Key = intern(e.Key)
		e.Value = intern(e.Value)
		e.Layer = intern(e.Layer)
		e.County = intern(e.County)
		e.State = intern(e.State)

		e.nameNorms = []string{normPlace(e.Name)}
		for _, a := range e.Alt {
			if n := normPlace(a); n != "" {
				e.nameNorms = append(e.nameNorms, n)
			}
		}
		// Alt is only an input to nameNorms; the API responds with Name.
		e.Alt = nil

		clear(bag)
		for _, n := range e.nameNorms {
			for _, t := range strings.Fields(n) {
				bag[t] = true
			}
		}
		for _, ctx := range []string{e.County, e.State} {
			for _, t := range placeTokens(ctx) {
				bag[t] = true
			}
		}
		if len(e.Extent) == 4 {
			w, n, ee, s := e.Extent[0], e.Extent[1], e.Extent[2], e.Extent[3]
			e.areaKm2 = (ee - w) * 111 * math.Cos(e.Lat*math.Pi/180) * (n - s) * 111
			if e.areaKm2 < 0 {
				e.areaKm2 = 0
			}
		}
		for t := range bag {
			ix.post[intern(t)] = append(ix.post[t], int32(i))
		}
	}
	ix.tokens = make([]string, 0, len(ix.post))
	for t := range ix.post {
		ix.tokens = append(ix.tokens, t)
	}
	sort.Strings(ix.tokens)
	return ix
}

const (
	placesMaxQueryLen   = 300
	placesMaxTokens     = 10
	placesMaxCandidates = 20000
	placesDefaultLimit  = 15
	placesMaxLimit      = 50
)

func (ix *placesIndex) search(qRaw string, opts searchOpts) []scoredPlace {
	if len(qRaw) > placesMaxQueryLen {
		return nil
	}
	if opts.limit <= 0 {
		opts.limit = placesDefaultLimit
	}
	if opts.limit > placesMaxLimit {
		opts.limit = placesMaxLimit
	}

	// The part before the first comma is the place name proper; anything after
	// is context ("Kenwyn, Cornwall"). All tokens must match, but exactness is
	// judged on the head.
	head := qRaw
	if i := strings.IndexByte(qRaw, ','); i >= 0 {
		head = qRaw[:i]
	}
	headNorm := normPlace(head)
	qTokens := placeTokens(qRaw)
	if len(qTokens) == 0 {
		return nil
	}
	if len(qTokens) > placesMaxTokens {
		qTokens = qTokens[:placesMaxTokens]
	}

	cands, matchedTokens, effTokens, fuzzy := ix.gather(qTokens)
	if len(cands) == 0 {
		return nil
	}

	qSet := map[string]bool{}
	for _, t := range effTokens {
		qSet[t] = true
	}
	lastTok := effTokens[len(effTokens)-1]

	results := make([]scoredPlace, 0, len(cands))
	for _, ci := range cands {
		e := &ix.entries[ci]
		if opts.layers != nil && !opts.layers[e.Layer] {
			continue
		}
		if b := opts.bbox; b != nil {
			if e.Lng < b[0] || e.Lng > b[2] || e.Lat < b[1] || e.Lat > b[3] {
				continue
			}
		}
		sp := ix.score(e, qSet, matchedTokens, effTokens, lastTok, headNorm, opts, fuzzy)
		if sp.score <= 0 {
			continue
		}
		results = append(results, sp)
	}

	sort.Slice(results, func(a, b int) bool {
		ra, rb := results[a], results[b]
		if ra.exact != rb.exact {
			return ra.exact
		}
		if ra.score != rb.score {
			return ra.score > rb.score
		}
		if ra.e.Pop != rb.e.Pop {
			return ra.e.Pop > rb.e.Pop
		}
		if ra.e.OsmType != rb.e.OsmType {
			return ra.e.OsmType < rb.e.OsmType
		}
		return ra.e.ID < rb.e.ID
	})
	return dedupeSameEntity(results, opts.limit)
}

// dedupeSameEntity collapses results that are the same real-world entity seen
// through different OSM objects — an administrative boundary, a ceremonial
// boundary and a place node all called "Kent" — keeping the best-ranked.
// Photon does the same (it overfetches specifically to have room to dedupe).
// Same-name entries far apart (the two Miltons) are different places and stay.
// Results arrive ranked, so it stops as soon as limit survivors are found —
// broad prefix queries can carry thousands of ranked candidates.
func dedupeSameEntity(results []scoredPlace, limit int) []scoredPlace {
	out := results[:0]
	for _, r := range results {
		dup := false
		for _, kept := range out {
			if len(kept.e.nameNorms) == 0 || len(r.e.nameNorms) == 0 || kept.e.nameNorms[0] != r.e.nameNorms[0] {
				continue
			}
			if sameFootprint(kept.e, r.e) {
				dup = true
				break
			}
		}
		if !dup {
			out = append(out, r)
			if len(out) >= limit {
				break
			}
		}
	}
	return out
}

func sameFootprint(a, b *PlaceEntry) bool {
	if len(a.Extent) == 4 && len(b.Extent) == 4 {
		return extentIoU(a.Extent, b.Extent) > 0.5
	}
	return placesDistKm(a.Lat, a.Lng, b.Lat, b.Lng) < 10
}

// extentIoU computes intersection-over-union of two [W,N,E,S] extents.
func extentIoU(a, b []float64) float64 {
	iw := math.Min(a[2], b[2]) - math.Max(a[0], b[0])
	ih := math.Min(a[1], b[1]) - math.Max(a[3], b[3])
	if iw <= 0 || ih <= 0 {
		return 0
	}
	inter := iw * ih
	areaA := (a[2] - a[0]) * (a[1] - a[3])
	areaB := (b[2] - b[0]) * (b[1] - b[3])
	union := areaA + areaB - inter
	if union <= 0 {
		return 0
	}
	return inter / union
}

// placeStopwords are filler words a feed query may carry that the OSM name
// does not ("City of Glasgow" vs "Glasgow City", "The Vale of Glamorgan").
// They are stripped only in the relaxed passes, after a full-token match has
// found nothing.
var placeStopwords = map[string]bool{
	"the": true, "of": true, "and": true, "in": true, "on": true,
	"upon": true, "by": true, "city": true, "county": true,
	"borough": true, "royal": true, "district": true,
}

// gather finds candidate entries: every token must match (the last as a
// prefix). Passes, in order: strict; strict with stopwords stripped; fuzzy
// (edit distance 1 per token); fuzzy stripped. matchedTokens reports which
// index tokens satisfied the query so scoring can credit fuzzy-matched name
// tokens; effTokens is the token list the winning pass used.
func (ix *placesIndex) gather(qTokens []string) (cands []int32, matchedTokens map[string]bool, effTokens []string, fuzzy bool) {
	stripped := make([]string, 0, len(qTokens))
	for _, t := range qTokens {
		if !placeStopwords[t] {
			stripped = append(stripped, t)
		}
	}
	useStripped := len(stripped) > 0 && len(stripped) < len(qTokens)

	type pass struct {
		tokens []string
		fuzzy  bool
	}
	passes := []pass{{qTokens, false}}
	if useStripped {
		passes = append(passes, pass{stripped, false})
	}
	passes = append(passes, pass{qTokens, true})
	if useStripped {
		passes = append(passes, pass{stripped, true})
	}

	for _, p := range passes {
		if c, m := ix.gatherPass(p.tokens, p.fuzzy); len(c) > 0 {
			return c, m, p.tokens, p.fuzzy
		}
	}

	// Last resort, photon's lenient minimumShouldMatch("-34%"): up to a third
	// of the words may go unmatched ("two mile bottom" finds Six Mile
	// Bottom). Never for one- or two-word queries — those must match whole.
	if len(qTokens) >= 3 {
		if c, m := ix.gatherPartial(qTokens); len(c) > 0 {
			return c, m, qTokens, false
		}
	}
	return nil, nil, qTokens, false
}

// gatherPartial keeps entries matching at least two thirds of the query
// words (the last as a prefix), photon's lenient floor.
func (ix *placesIndex) gatherPartial(qTokens []string) ([]int32, map[string]bool) {
	need := (2*len(qTokens) + 2) / 3
	matchedTokens := map[string]bool{}
	counts := map[int32]int{}
	for i, tok := range qTokens {
		set := map[int32]bool{}
		for _, id := range ix.post[tok] {
			set[id] = true
		}
		matchedTokens[tok] = true
		if i == len(qTokens)-1 && len(tok) >= 2 {
			ix.expandPrefix(tok, set, matchedTokens)
		}
		for id := range set {
			counts[id]++
		}
	}
	var out []int32
	for id, n := range counts {
		if n >= need {
			out = append(out, id)
		}
	}
	return out, matchedTokens
}

func (ix *placesIndex) gatherPass(qTokens []string, fuzzy bool) ([]int32, map[string]bool) {
	matchedTokens := map[string]bool{}
	sets := make([]map[int32]bool, len(qTokens))
	for i, tok := range qTokens {
		set := map[int32]bool{}
		for _, id := range ix.post[tok] {
			set[id] = true
		}
		matchedTokens[tok] = true
		if fuzzy && len(tok) >= 4 {
			for _, t := range ix.editDistance1Tokens(tok) {
				matchedTokens[t] = true
				for _, id := range ix.post[t] {
					set[id] = true
				}
			}
		}
		if i == len(qTokens)-1 && len(tok) >= 2 {
			ix.expandPrefix(tok, set, matchedTokens)
		}
		if len(set) == 0 {
			return nil, nil
		}
		sets[i] = set
	}
	return intersect(sets), matchedTokens
}

func (ix *placesIndex) expandPrefix(prefix string, set map[int32]bool, matchedTokens map[string]bool) {
	i := sort.SearchStrings(ix.tokens, prefix)
	for ; i < len(ix.tokens) && strings.HasPrefix(ix.tokens[i], prefix); i++ {
		matchedTokens[ix.tokens[i]] = true
		for _, id := range ix.post[ix.tokens[i]] {
			set[id] = true
		}
		if len(set) > placesMaxCandidates {
			break
		}
	}
}

// editDistance1Tokens scans the token list for tokens within one edit of tok.
// The first letter must survive (photon's own typo tolerance is weak; probed
// "Mancester" does not find Manchester there, so being slightly kinder here is
// a deliberate, reported improvement).
func (ix *placesIndex) editDistance1Tokens(tok string) []string {
	var out []string
	i := sort.SearchStrings(ix.tokens, tok[:1])
	for ; i < len(ix.tokens) && strings.HasPrefix(ix.tokens[i], tok[:1]); i++ {
		t := ix.tokens[i]
		d := len(t) - len(tok)
		if d < -1 || d > 1 || t == tok {
			continue
		}
		if editDistance1(tok, t) {
			out = append(out, t)
		}
	}
	return out
}

// editDistance1 reports whether a and b differ by exactly one insert, delete
// or substitute.
func editDistance1(a, b string) bool {
	if len(a) > len(b) {
		a, b = b, a
	}
	switch len(b) - len(a) {
	case 0:
		diff := 0
		for i := 0; i < len(a); i++ {
			if a[i] != b[i] {
				diff++
				if diff > 1 {
					return false
				}
			}
		}
		return diff == 1
	case 1:
		i, j, skipped := 0, 0, false
		for i < len(a) && j < len(b) {
			if a[i] == b[j] {
				i++
				j++
				continue
			}
			if skipped {
				return false
			}
			skipped = true
			j++
		}
		return true
	default:
		return false
	}
}

func intersect(sets []map[int32]bool) []int32 {
	if len(sets) == 0 {
		return nil
	}
	smallest := 0
	for i, s := range sets {
		if len(s) < len(sets[smallest]) {
			smallest = i
		}
	}
	var out []int32
	for id := range sets[smallest] {
		all := true
		for i, s := range sets {
			if i == smallest {
				continue
			}
			if !s[id] {
				all = false
				break
			}
		}
		if all {
			out = append(out, id)
		}
	}
	return out
}

// layerBase orders photon layers roughly by prominence. "other" (ceremonial
// counties, statistical regions) sits above city because those are the
// entities the highest-volume WhatJobs region queries must hit first.
var layerBase = map[string]float64{
	"state":    1.5,
	"county":   1.35,
	"other":    1.3,
	"city":     1.0,
	"district": 0.6,
	"locality": 0.5,
}

func (ix *placesIndex) score(e *PlaceEntry, qSet, matchedTokens map[string]bool, effTokens []string, lastTok, headNorm string, opts searchOpts, fuzzy bool) scoredPlace {
	exact := false
	for _, n := range e.nameNorms {
		if n == headNorm {
			exact = true
			break
		}
	}

	// Two coverages, judged against the best single name variant (an alt name
	// like "Kirkby Kendal" must not dilute a full match on the primary name):
	//
	//   cov      — how much of the entry's name the query accounts for;
	//   qNameCov — how much of the query the entry's NAME accounts for.
	//
	// The second is what stops "South West" resolving to a village called
	// Westerleigh: its "West" is only a prefix and its "South" only its
	// county, while the region has both words in the name outright. Match
	// weights: exact word 1.0, last-word prefix 0.7, fuzzy variant 0.8.
	// Walked without allocating token slices — this runs for every candidate
	// of every query.
	cov, qNameCov := 0.0, 0.0
	for _, n := range e.nameNorms {
		matched, total := 0.0, 0
		start := 0
		for idx := 0; idx <= len(n); idx++ {
			if idx == len(n) || n[idx] == ' ' {
				if idx > start {
					tok := n[start:idx]
					total++
					switch {
					case qSet[tok]:
						matched += 1
					case strings.HasPrefix(tok, lastTok):
						matched += 0.7
					case matchedTokens[tok]:
						matched += 0.8
					}
				}
				start = idx + 1
			}
		}
		if total == 0 {
			continue
		}
		c := matched / float64(total)

		var qc float64
		if fuzzy {
			// A fuzzy hit cannot be attributed to one query word; the entry
			// coverage is the honest proxy and fuzzy is already discounted.
			qc = c
		} else {
			qm := 0.0
			for _, t := range effTokens {
				qm += nameTokenMatch(n, t, t == lastTok)
			}
			qc = qm / float64(len(effTokens))
		}

		if c*qc > cov*qNameCov || (cov == 0 && c > 0) {
			cov, qNameCov = c, qc
		}
	}
	if cov == 0 && !exact {
		// Context-only matches (query hit just the county name) are noise.
		return scoredPlace{}
	}

	s := layerBase[e.Layer] * (0.25 + 0.75*cov) * (0.4 + 0.6*qNameCov)
	// Population separates the city from the hamlet of the same name, but
	// only for places: boundary relations compete on footprint, not on
	// whether someone tagged a population.
	if e.Key == "place" && e.Pop > 0 {
		s *= 1 + math.Log10(float64(e.Pop)+1)/8
	}
	// Footprint disambiguates entities sharing the exact queried name (the
	// "West Midlands" region over the metropolitan county). For as-you-type
	// prefixes it must not push counties above their cities, so exact only.
	if exact && e.areaKm2 > 0 {
		s *= 1 + math.Log10(1+e.areaKm2)/5
	}
	if fuzzy {
		s *= 0.5
	}
	// Location bias, following photon 0.5.0's withLocationBias: a zoom-scaled
	// exponential distance decay (radius 2^(18-zoom)*0.25 km, 0.8 at the
	// radius) MAXed with an importance floor, so an important place survives
	// being far from the map centre. Our importance proxy is population and
	// admin prominence in place of Nominatim's wikipedia-derived score.
	if opts.biasLat != nil && opts.biasLng != nil {
		zoom := opts.biasZoom
		if zoom == 0 {
			zoom = 14
		}
		if zoom > 18 {
			zoom = 18
		}
		if zoom >= 4 {
			radius := float64(int(1)<<(18-zoom)) * 0.25
			d := placesDistKm(*opts.biasLat, *opts.biasLng, e.Lat, e.Lng)
			distFactor := math.Pow(0.8, math.Max(0, d-radius/10)/radius)
			imp := 0.2 + math.Min(0.6, math.Log10(float64(e.Pop)+1)/12)
			if e.Layer == "state" || e.Layer == "county" || e.Layer == "other" {
				imp = math.Max(imp, 0.6)
			}
			floor := 1 - 2.5*(1-imp)
			if floor < 0 {
				floor = 0
			}
			s *= math.Max(distFactor, floor)
		}
	}
	return scoredPlace{e: e, exact: exact, score: s}
}

// nameTokenMatch reports how well one query word matches within a normalised
// name: 1.0 for an exact word, 0.7 when the word is a prefix of a name word
// (allowed for the query's last word only — as-you-type), else 0.
func nameTokenMatch(n, t string, allowPrefix bool) float64 {
	best := 0.0
	start := 0
	for idx := 0; idx <= len(n); idx++ {
		if idx == len(n) || n[idx] == ' ' {
			if idx > start {
				tok := n[start:idx]
				if tok == t {
					return 1.0
				}
				if allowPrefix && strings.HasPrefix(tok, t) {
					best = 0.7
				}
			}
			start = idx + 1
		}
	}
	return best
}

func placesDistKm(lat1, lng1, lat2, lng2 float64) float64 {
	const r = 6371
	la1, la2 := lat1*math.Pi/180, lat2*math.Pi/180
	dLat := (lat2 - lat1) * math.Pi / 180
	dLng := (lng2 - lng1) * math.Pi / 180
	a := math.Sin(dLat/2)*math.Sin(dLat/2) + math.Cos(la1)*math.Cos(la2)*math.Sin(dLng/2)*math.Sin(dLng/2)
	return 2 * r * math.Asin(math.Sqrt(a))
}
