package message

import "strings"

// phraseMap handles multi-word queries that collapse to useless single words
// after stop-word removal. "white goods" → GetWords → ["goods"] which matches
// nothing useful, so we replace the whole output with the expansion.
// Keys are the full lowercased raw query.
var phraseMap = map[string][]string{
	"white goods":   {"fridge", "freezer", "washing", "dishwasher", "tumble", "cooker", "oven"},
	"flat screen":   {"tv", "television", "flatscreen", "monitor"},
	"flat screens":  {"tv", "television", "flatscreen", "monitor"},
	"big screen":    {"tv", "television", "flatscreen"},
	"smart tv":      {"tv", "television", "flatscreen"},
	"second hand":   {}, // intentionally empty — falls through to normal GetWords
}

// synonymMap maps a single normalised word to additional words to search
// alongside it. Keys and values survive GetWords normalisation (not in the
// common-words stop list, ≥2 chars). Applied word-by-word after phraseMap.
var synonymMap = map[string][]string{
	// Seating
	"sofa":     {"couch", "settee"},
	"couch":    {"sofa", "settee"},
	"settee":   {"sofa", "couch"},
	"loveseat": {"sofa", "couch"},

	// Televisions / screens
	"tv":         {"television", "telly", "flatscreen"},
	"television": {"tv", "telly"},
	"telly":      {"tv", "television"},
	"flatscreen": {"tv", "television"},

	// Bicycles
	"bike":     {"bicycle", "cycle"},
	"bicycle":  {"bike", "cycle"},
	"cycle":    {"bike", "bicycle"},
	"pushbike": {"bike", "bicycle"},

	// Baby transport
	"pram":      {"pushchair", "buggy", "stroller"},
	"pushchair": {"pram", "buggy", "stroller"},
	"buggy":     {"pram", "pushchair", "stroller"},
	"stroller":  {"pram", "pushchair", "buggy"},

	// Fridge — "refrigerator" is American English; UK messages use "fridge". Freezer
	// is a distinct appliance so fridge↔freezer is intentionally absent.
	"fridge": {},

	// Vacuums — "hoover" is the dominant UK search term (the brand became generic);
	// 190 indexed messages use "hoover", 276 use "vacuum", users search "hoover" 10×
	// more than "vacuum". Without this pair they never overlap.
	"hoover": {"vacuum"},
	"vacuum": {"hoover"},

	// Wardrobes / storage
	"cupboard": {"cabinet"},
	"cabinet":  {"cupboard"},

	// Two-wheeled motorised. "scooter" is deliberately excluded: in UK searches
	// "scooter" overwhelmingly means mobility scooters and electric kick scooters,
	// not mopeds — conflating them returns wrong results for the majority of users.
	"motorbike":  {"motorcycle", "moped"},
	"motorcycle": {"motorbike", "moped"},
	"moped":      {"motorcycle", "motorbike"},

	// Carpets / rugs
	"carpet": {"rug"},
	"rug":    {"carpet"},

	// Tumble dryer
	"tumble": {"dryer"},
	"dryer":  {"tumble"},
}

// ExpandQuery returns the full set of words to pass to the keyword search,
// after phrase-level and word-level synonym expansion.
//
// Priority:
//  1. phraseMap: if the whole lowercased query matches a key, its value
//     replaces GetWords output entirely (handles "white goods" → useless "goods").
//  2. synonymMap: GetWords is called normally, then each word's synonyms are
//     appended (deduped, preserving original word order first).
func ExpandQuery(rawTerm string) []string {
	lowerTerm := strings.ToLower(strings.TrimSpace(rawTerm))

	if extras, ok := phraseMap[lowerTerm]; ok && len(extras) > 0 {
		return extras
	}

	words := GetWords(rawTerm)
	if len(words) == 0 {
		return words
	}

	seen := make(map[string]struct{}, len(words)*3)
	result := make([]string, 0, len(words)*3)

	add := func(w string) {
		if _, dup := seen[w]; !dup {
			seen[w] = struct{}{}
			result = append(result, w)
		}
	}

	for _, w := range words {
		add(w)
		for _, s := range synonymMap[w] {
			add(s)
		}
	}

	return result
}
