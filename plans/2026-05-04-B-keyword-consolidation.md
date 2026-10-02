# Keyword Consolidation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the overlapping `worrywords` and `spam_keywords` tables with a single `concern_keywords` table that has a clear category taxonomy, unified matching modes, and supports both global and per-group entries. Update the modtools management UI to manage the unified table.

**Architecture:** A new `concern_keywords` table replaces both old tables. Existing data is migrated in via a Laravel migration. The PHP `WorryWords.php` and `Spam.php` classes are updated to query `concern_keywords`. Per-group words (currently stored as a JSON string in `groups.settings.spammers.worrywords`) are migrated to rows in `concern_keywords` with `scope=group`. The modtools Vue UI merges the two existing subtabs (Worry Words and Spam Keywords) into a single Keyword List subtab. The old tables are retained but no longer written to (safe removal is post-prototype).

**Tech Stack:** Laravel 12 / PHP 8.3 (migrations, PHP class updates), Vue 3 / Pinia (modtools UI), MySQL

**Spec:** `docs/superpowers/specs/2026-05-04-post-review-service-design.md` — Section 7

---

## File Map

| Action | Path |
|---|---|
| Create | `iznik-batch/database/migrations/2026_05_04_000001_create_concern_keywords_table.php` |
| Create | `iznik-batch/database/migrations/2026_05_04_000002_migrate_concern_keywords_data.php` |
| Modify | `iznik-server/include/message/WorryWords.php` |
| Modify | `iznik-server/include/spam/Spam.php` |
| Create | `iznik-nuxt3/modtools/components/ModSupportConcernKeywords.vue` |
| Modify | `iznik-nuxt3/modtools/pages/support/[[id]].vue` |
| Modify | `iznik-nuxt3/modtools/stores/systemconfig.js` |
| Modify | `iznik-nuxt3/api/ConfigAPI.js` |
| Modify | `iznik-nuxt3/modtools/components/ModSettingsGroup.vue` |
| Create | `iznik-batch/tests/Unit/Migration/ConcernKeywordsMigrationTest.php` |
| Create | `iznik-server/test/ut/php/include/ConcernKeywordsTest.php` |

---

### Task 1: Create the concern_keywords table migration

**Files:**
- Create: `iznik-batch/database/migrations/2026_05_04_000001_create_concern_keywords_table.php`

- [ ] **Step 1: Write the failing test**

Create `iznik-batch/tests/Unit/Migration/ConcernKeywordsMigrationTest.php`:

```php
<?php

namespace Tests\Unit\Migration;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConcernKeywordsMigrationTest extends TestCase
{
    public function test_concern_keywords_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('concern_keywords'));
    }

    public function test_concern_keywords_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('concern_keywords');
        foreach (['id','keyword','category','match_mode','action','scope'] as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }
    }

    public function test_concern_keywords_group_id_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('concern_keywords', 'group_id'));
        // group_id is null for global entries
        \DB::table('concern_keywords')->insert([
            'keyword' => 'test-global',
            'category' => 'review',
            'match_mode' => 'literal',
            'action' => 'flag',
            'scope' => 'global',
            'group_id' => null,
        ]);
        $this->assertDatabaseHas('concern_keywords', ['keyword' => 'test-global', 'group_id' => null]);
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
docker exec freegle-batch php artisan test --filter=ConcernKeywordsMigrationTest
```

Expected: FAIL — `SQLSTATE[42S02]: Base table or view not found: concern_keywords`

- [ ] **Step 3: Create the migration**

```bash
# Create the file with the correct date prefix
cat > iznik-batch/database/migrations/2026_05_04_000001_create_concern_keywords_table.php << 'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('keyword', 255);
            $table->string('substance', 255)->nullable();
            $table->enum('category', [
                'substance_regulated',
                'substance_reportable',
                'substance_medicine',
                'scam',
                'review',
                'allowed',
            ]);
            $table->enum('match_mode', ['fuzzy', 'literal', 'regex'])->default('literal');
            $table->text('exclude')->nullable();
            $table->enum('scope', ['global', 'group'])->default('global');
            $table->unsignedInteger('group_id')->nullable();
            $table->enum('action', ['block', 'flag'])->default('flag');
            $table->timestamps();

            $table->unique(['keyword', 'scope', 'group_id']);
            $table->index(['scope', 'group_id']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_keywords');
    }
};
MIGRATION
```

- [ ] **Step 4: Run the migration**

```bash
docker exec freegle-batch php artisan migrate
```

Expected: `Migrating: 2026_05_04_000001_create_concern_keywords_table` then `Migrated`.

- [ ] **Step 5: Run test to confirm it passes**

```bash
docker exec freegle-batch php artisan test --filter=ConcernKeywordsMigrationTest
```

Expected: PASS — 3 tests

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/database/migrations/2026_05_04_000001_create_concern_keywords_table.php \
        iznik-batch/tests/Unit/Migration/ConcernKeywordsMigrationTest.php
git commit -m "feat(keywords): create concern_keywords table"
```

---

### Task 2: Data migration from worrywords, spam_keywords, and per-group settings

**Files:**
- Create: `iznik-batch/database/migrations/2026_05_04_000002_migrate_concern_keywords_data.php`

- [ ] **Step 1: Write the failing test**

Add to `ConcernKeywordsMigrationTest.php`:

```php
    public function test_worrywords_migrated(): void
    {
        // After migration, every row in worrywords should exist in concern_keywords
        $count = \DB::table('worrywords')->count();
        $migrated = \DB::table('concern_keywords')
            ->whereIn('category', ['substance_regulated','substance_reportable','substance_medicine','review','allowed'])
            ->where('scope', 'global')
            ->count();
        $this->assertGreaterThanOrEqual($count, $migrated);
    }

    public function test_spam_keywords_migrated(): void
    {
        $count = \DB::table('spam_keywords')->where('type', 'Literal')->count();
        $migrated = \DB::table('concern_keywords')
            ->whereIn('category', ['scam', 'review'])
            ->where('match_mode', 'literal')
            ->count();
        $this->assertGreaterThanOrEqual($count, $migrated);
    }
```

- [ ] **Step 2: Run to confirm failure** (passes vacuously if worrywords is empty in dev — that's fine; the migration still runs correctly in production)

```bash
docker exec freegle-batch php artisan test --filter=ConcernKeywordsMigrationTest
```

- [ ] **Step 3: Create the data migration**

```php
<?php
// iznik-batch/database/migrations/2026_05_04_000002_migrate_concern_keywords_data.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Type mapping from worrywords.type → concern_keywords.category
    private const WORRY_CATEGORY_MAP = [
        'Regulated'  => 'substance_regulated',
        'Reportable' => 'substance_reportable',
        'Medicine'   => 'substance_medicine',
        'Review'     => 'review',
        'Allowed'    => 'allowed',
    ];

    public function up(): void
    {
        // 1. Migrate global worrywords
        $worrywords = DB::table('worrywords')->get();
        foreach ($worrywords as $ww) {
            $category = self::WORRY_CATEGORY_MAP[$ww->type] ?? 'review';
            DB::table('concern_keywords')->insertOrIgnore([
                'keyword'    => $ww->keyword,
                'substance'  => $ww->substance,
                'category'   => $category,
                'match_mode' => 'fuzzy',
                'action'     => in_array($ww->type, ['Regulated','Reportable']) ? 'block' : 'flag',
                'scope'      => 'global',
                'group_id'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Migrate spam_keywords
        $spamKeywords = DB::table('spam_keywords')->get();
        foreach ($spamKeywords as $sk) {
            $category = ($sk->action === 'Spam') ? 'scam' : 'review';
            $matchMode = ($sk->type === 'Regex') ? 'regex' : 'literal';
            $action    = ($sk->action === 'Spam') ? 'block' : 'flag';
            DB::table('concern_keywords')->insertOrIgnore([
                'keyword'    => $sk->word,
                'substance'  => null,
                'category'   => $category,
                'match_mode' => $matchMode,
                'exclude'    => $sk->exclude,
                'action'     => $action,
                'scope'      => 'global',
                'group_id'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Migrate per-group worry words from groups.settings JSON
        // groups.settings is a JSON column; per-group words are at settings->spammers->worrywords
        $groups = DB::table('groups')
            ->whereNotNull('settings')
            ->get(['id', 'settings']);

        foreach ($groups as $group) {
            $settings = json_decode($group->settings, true);
            $wordsStr = $settings['spammers']['worrywords'] ?? '';
            if (empty($wordsStr)) {
                continue;
            }
            $words = array_filter(array_map('trim', explode(',', $wordsStr)));
            foreach ($words as $word) {
                if (empty($word)) {
                    continue;
                }
                DB::table('concern_keywords')->insertOrIgnore([
                    'keyword'    => $word,
                    'substance'  => null,
                    'category'   => 'review',
                    'match_mode' => 'literal',
                    'action'     => 'flag',
                    'scope'      => 'group',
                    'group_id'   => $group->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('concern_keywords')->truncate();
    }
};
```

- [ ] **Step 4: Run the migration**

```bash
docker exec freegle-batch php artisan migrate
```

- [ ] **Step 5: Run tests**

```bash
docker exec freegle-batch php artisan test --filter=ConcernKeywordsMigrationTest
```

Expected: PASS — 5 tests (vacuously for counts if dev DB has no data — that is expected)

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/database/migrations/2026_05_04_000002_migrate_concern_keywords_data.php \
        iznik-batch/tests/Unit/Migration/ConcernKeywordsMigrationTest.php
git commit -m "feat(keywords): migrate worrywords and spam_keywords data to concern_keywords"
```

---

### Task 3: Update WorryWords.php to read from concern_keywords

**Files:**
- Modify: `iznik-server/include/message/WorryWords.php`
- Create: `iznik-server/test/ut/php/include/ConcernKeywordsTest.php`

- [ ] **Step 1: Write failing test**

The existing `WorryWords` PHPUnit tests live in `iznik-server/test/ut/php/include/`. Read the existing WorryWordsTest.php and add a test confirming the new table is queried:

```bash
# Find existing test file
find /home/edward/FreegleDockerWSL/iznik-server/test -name "*WorryWord*" -o -name "*worryword*" 2>/dev/null
```

Add to the existing test class (or create `ConcernKeywordsTest.php`):

```php
public function testWorryWordReadFromConcernKeywords(): void
{
    // Insert a row directly into concern_keywords
    $this->dbhm->preExec(
        "INSERT INTO concern_keywords (keyword, category, match_mode, action, scope) VALUES (?, ?, ?, ?, ?)",
        ['testdrug_unique_xyz', 'substance_regulated', 'literal', 'block', 'global']
    );

    $ww = new WorryWords($this->dbhr, $this->dbhm);
    $results = $ww->checkMessage(null, null, 'TestDrug_unique_xyz for sale', '');
    $this->assertNotEmpty($results);
    $this->assertEquals('testdrug_unique_xyz', $results[0]['worryword']['keyword']);
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
# Run via the status API (per project convention)
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?filter=ConcernKeywords" | python3 -m json.tool
```

Expected: test not found or FAIL

- [ ] **Step 3: Update WorryWords.php to query concern_keywords**

In `WorryWords.php`, replace the SQL that queries `worrywords` table with a query to `concern_keywords`. The key change is in the constructor where keywords are loaded:

```php
// Before (approximate — exact line numbers from reading the file):
$sql = "SELECT keyword, substance, type FROM worrywords ORDER BY keyword";

// After:
$sql = "SELECT keyword, substance, category AS type, match_mode, action
        FROM concern_keywords
        WHERE scope = 'global'
        ORDER BY keyword";

// For per-group (where $groupid is set):
$sql = "SELECT keyword, substance, category AS type, match_mode, action
        FROM concern_keywords
        WHERE scope = 'global' OR (scope = 'group' AND group_id = ?)
        ORDER BY keyword";
```

Map `category` back to the existing type constants that callers expect:

```php
private function categoryToType(string $category): string
{
    return match ($category) {
        'substance_regulated' => self::TYPE_REGULATED,
        'substance_reportable' => self::TYPE_REPORTABLE,
        'substance_medicine' => self::TYPE_MEDICINE,
        'allowed' => self::TYPE_ALLOWED,
        default => self::TYPE_REVIEW,
    };
}
```

Wrap each loaded keyword through `categoryToType()` so existing callers see the same type constants they always did. The rest of the fuzzy-matching logic is unchanged.

- [ ] **Step 4: Run the test to confirm it passes**

```bash
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?filter=ConcernKeywords"
```

Expected: PASS

- [ ] **Step 5: Run the full PHP test suite to check for regressions**

```bash
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?suite=WorryWords"
```

Expected: all existing tests pass

- [ ] **Step 6: Commit**

```bash
git add iznik-server/include/message/WorryWords.php \
        iznik-server/test/ut/php/include/ConcernKeywordsTest.php
git commit -m "feat(keywords): WorryWords.php reads from concern_keywords table"
```

---

### Task 4: Update Spam.php to read from concern_keywords

**Files:**
- Modify: `iznik-server/include/spam/Spam.php`

- [ ] **Step 1: Write failing test**

Add to `ConcernKeywordsTest.php`:

```php
public function testSpamKeywordReadFromConcernKeywords(): void
{
    $this->dbhm->preExec(
        "INSERT INTO concern_keywords (keyword, category, match_mode, action, scope) VALUES (?, ?, ?, ?, ?)",
        ['wire_transfer_test_xyz', 'scam', 'literal', 'block', 'global']
    );

    $spam = new Spam($this->dbhr, $this->dbhm);
    [$isSpam, $reason] = $spam->checkSpam('Please wire transfer test xyz money now', '');
    $this->assertTrue($isSpam);
    $this->assertEquals(Spam::REASON_KNOWN_KEYWORD, $reason);
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?filter=testSpamKeywordReadFromConcernKeywords"
```

Expected: FAIL

- [ ] **Step 3: Update Spam.php keyword loading**

In `Spam.php`, in the constructor or wherever `spam_keywords` is queried, replace:

```php
// Before:
$sql = "SELECT word, exclude, action, type FROM spam_keywords ORDER BY word";

// After:
$sql = "SELECT keyword AS word, exclude, action, match_mode AS type
        FROM concern_keywords
        WHERE scope = 'global' AND category IN ('scam', 'review')
        ORDER BY keyword";
```

Map `action` values: `concern_keywords.action = 'block'` maps to old `Spam::ACTION_SPAM`; `action = 'flag'` maps to `ACTION_REVIEW`. Map `match_mode`: `'regex'` → `TYPE_REGEX`, others → `TYPE_LITERAL`.

```php
private function mapConcernAction(string $action): string
{
    return $action === 'block' ? self::ACTION_SPAM : self::ACTION_REVIEW;
}

private function mapConcernMatchMode(string $matchMode): string
{
    return $matchMode === 'regex' ? self::TYPE_REGEX : self::TYPE_LITERAL;
}
```

Apply these when loading keywords so the rest of `checkSpam()` and `checkReview()` are unchanged.

- [ ] **Step 4: Run the test to confirm it passes**

```bash
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?filter=ConcernKeywords"
```

Expected: PASS — all tests including the new one

- [ ] **Step 5: Run full spam test suite**

```bash
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?suite=Spam"
```

Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add iznik-server/include/spam/Spam.php \
        iznik-server/test/ut/php/include/ConcernKeywordsTest.php
git commit -m "feat(keywords): Spam.php reads from concern_keywords table"
```

---

### Task 5: Add Go API endpoint for concern_keywords CRUD

The modtools Vue UI calls the Go API (`/api/config/admin/concern_keywords`). The Go API needs to serve this endpoint.

**Files:**
- Modify: `iznik-server-go/config/config.go` (or wherever `/api/config` routes are defined — locate with `grep -rn "concern_keywords\|worry_words\|spam_keywords" iznik-server-go/`)

- [ ] **Step 1: Find where the existing worry_words and spam_keywords API routes are defined**

```bash
grep -rn "worry_words\|spam_keywords\|worryword\|spamkeyword" \
  /home/edward/FreegleDockerWSL/iznik-server-go/ --include="*.go" | head -20
```

Follow the pattern for whichever file defines those routes.

- [ ] **Step 2: Add CRUD handlers for concern_keywords**

Following the exact pattern of the existing worry_words handlers, add:

```go
// GET /api/config/admin/concern_keywords
// POST /api/config/admin/concern_keywords
// DELETE /api/config/admin/concern_keywords/:id

type ConcernKeyword struct {
    ID        uint      `json:"id" gorm:"primaryKey"`
    Keyword   string    `json:"keyword"`
    Substance *string   `json:"substance"`
    Category  string    `json:"category"`
    MatchMode string    `json:"match_mode"`
    Exclude   *string   `json:"exclude"`
    Scope     string    `json:"scope"`
    GroupID   *uint     `json:"group_id"`
    Action    string    `json:"action"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
```

- [ ] **Step 3: Write a Go test for the new endpoint**

Following the pattern in the closest existing config test file:

```go
func TestConcernKeywordsCRUD(t *testing.T) {
    // POST: create
    body := `{"keyword":"test_kw","category":"review","match_mode":"literal","action":"flag","scope":"global"}`
    w := httptest.NewRecorder()
    req := httptest.NewRequest("POST", "/api/config/admin/concern_keywords", strings.NewReader(body))
    req.Header.Set("Content-Type", "application/json")
    // add auth token header per test pattern
    router.ServeHTTP(w, req)
    assert.Equal(t, 200, w.Code)

    // GET: list
    w2 := httptest.NewRecorder()
    req2 := httptest.NewRequest("GET", "/api/config/admin/concern_keywords", nil)
    router.ServeHTTP(w2, req2)
    assert.Equal(t, 200, w2.Code)
    // verify test_kw appears in response
    assert.Contains(t, w2.Body.String(), "test_kw")
}
```

- [ ] **Step 4: Run Go tests**

```bash
docker exec freegle-apiv2 go test ./config/... -v -run TestConcernKeywords
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add iznik-server-go/config/
git commit -m "feat(keywords): add concern_keywords CRUD API endpoints"
```

---

### Task 6: Create unified modtools Vue component and update UI

**Files:**
- Create: `iznik-nuxt3/modtools/components/ModSupportConcernKeywords.vue`
- Modify: `iznik-nuxt3/modtools/pages/support/[[id]].vue`
- Modify: `iznik-nuxt3/modtools/stores/systemconfig.js`
- Modify: `iznik-nuxt3/api/ConfigAPI.js`

- [ ] **Step 1: Add concern_keywords API calls to ConfigAPI.js**

In `iznik-nuxt3/api/ConfigAPI.js`, add alongside the existing `worry_words` and `spam_keywords` calls:

```js
async fetchConcernKeywords() {
  return useApiv2('GET', '/config/admin/concern_keywords')
},
async addConcernKeyword(keyword, category, matchMode, action, scope, groupId = null) {
  return useApiv2('POST', '/config/admin/concern_keywords', {
    keyword, category, match_mode: matchMode, action, scope, group_id: groupId,
  })
},
async deleteConcernKeyword(id) {
  return useApiv2('DELETE', `/config/admin/concern_keywords/${id}`)
},
```

- [ ] **Step 2: Add concern_keywords to the Pinia store**

In `iznik-nuxt3/modtools/stores/systemconfig.js`, add to state and actions following the existing `worrywords` pattern:

```js
state: () => ({
  // existing ...
  concern_keywords: [],
}),
actions: {
  // existing ...
  async fetchConcernKeywords() {
    const api = useConfigAPI()
    const result = await api.fetchConcernKeywords()
    if (result?.ret === 0) {
      this.concern_keywords = result.concern_keywords ?? []
    }
  },
  async addConcernKeyword(keyword, category, matchMode, action, scope, groupId = null) {
    const api = useConfigAPI()
    await api.addConcernKeyword(keyword, category, matchMode, action, scope, groupId)
    await this.fetchConcernKeywords()
  },
  async deleteConcernKeyword(id) {
    const api = useConfigAPI()
    await api.deleteConcernKeyword(id)
    this.concern_keywords = this.concern_keywords.filter(k => k.id !== id)
  },
},
```

- [ ] **Step 3: Create ModSupportConcernKeywords.vue**

```vue
<!-- iznik-nuxt3/modtools/components/ModSupportConcernKeywords.vue -->
<template>
  <div>
    <b-alert variant="danger" show>
      Changes here affect the entire system and all communities.
    </b-alert>

    <div class="mb-3 d-flex gap-2 flex-wrap">
      <b-form-input v-model="newKeyword" placeholder="Keyword or regex pattern" class="w-auto" />
      <b-form-select v-model="newCategory" :options="categoryOptions" class="w-auto" />
      <b-form-select v-model="newMatchMode" :options="matchModeOptions" class="w-auto" />
      <b-form-select v-model="newAction" :options="actionOptions" class="w-auto" />
      <b-button variant="primary" :disabled="!newKeyword" @click="add">
        Add
      </b-button>
    </div>

    <b-form-select v-model="filterCategory" :options="[{value:null,text:'All categories'},...categoryOptions]" class="mb-3 w-auto" />

    <b-table
      :items="filtered"
      :fields="fields"
      striped
      hover
      small
    >
      <template #cell(actions)="{ item }">
        <b-button size="sm" variant="danger" @click="remove(item.id)">Delete</b-button>
      </template>
    </b-table>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useSystemConfigStore } from '../stores/systemconfig'

const store = useSystemConfigStore()
await store.fetchConcernKeywords()

const newKeyword = ref('')
const newCategory = ref('review')
const newMatchMode = ref('literal')
const newAction = ref('flag')
const filterCategory = ref(null)

const categoryOptions = [
  { value: 'substance_regulated', text: 'Substance — Regulated' },
  { value: 'substance_reportable', text: 'Substance — Reportable' },
  { value: 'substance_medicine', text: 'Substance — Medicine' },
  { value: 'scam', text: 'Scam / spam' },
  { value: 'review', text: 'Review (general)' },
  { value: 'allowed', text: 'Allowed (whitelist)' },
]

const matchModeOptions = [
  { value: 'literal', text: 'Literal (word boundary)' },
  { value: 'fuzzy', text: 'Fuzzy (catches typos)' },
  { value: 'regex', text: 'Regex' },
]

const actionOptions = [
  { value: 'flag', text: 'Flag for review' },
  { value: 'block', text: 'Block (route to pending immediately)' },
]

const fields = [
  { key: 'keyword', label: 'Keyword' },
  { key: 'category', label: 'Category' },
  { key: 'match_mode', label: 'Match mode' },
  { key: 'action', label: 'Action' },
  { key: 'actions', label: '' },
]

const filtered = computed(() =>
  store.concern_keywords.filter(k =>
    k.scope === 'global' && (filterCategory.value === null || k.category === filterCategory.value)
  )
)

async function add() {
  if (!newKeyword.value.trim()) return
  await store.addConcernKeyword(
    newKeyword.value.trim(), newCategory.value, newMatchMode.value, newAction.value, 'global'
  )
  newKeyword.value = ''
}

async function remove(id) {
  await store.deleteConcernKeyword(id)
}
</script>
```

- [ ] **Step 4: Replace the two old subtabs with the new component in support/[[id]].vue**

Find the Spam tab section in `modtools/pages/support/[[id]].vue`. Replace the two existing subtab entries (`ModSupportWorryWords` and `ModSupportSpamKeywords`) with:

```vue
<b-tab title="Keyword List">
  <ModSupportConcernKeywords />
</b-tab>
```

Remove the imports for `ModSupportWorryWords` and `ModSupportSpamKeywords`.

- [ ] **Step 5: Update per-group settings to use concern_keywords API**

In `ModSettingsGroup.vue`, the Spammers section has a plain text input for per-group worry words. Update its save handler to write to `concern_keywords` (scope=group) instead of the group settings JSON string.

Replace the save for `settings.spammers.worrywords` with individual `addConcernKeyword` calls per word, and load via `concern_keywords?scope=group&group_id=X` on mount.

(The old `settings.spammers.worrywords` field in the group settings JSON is left in place as a read-only migration artefact — it is no longer written to.)

- [ ] **Step 6: Start dev server and test the UI**

```bash
# The nuxt3 dev server should already be running via the dev containers
# Navigate to http://modtools.localhost/modtools/support and open the Spam tab
# Verify: single "Keyword List" subtab, can add and delete keywords, filter by category
```

- [ ] **Step 7: Commit**

```bash
git add iznik-nuxt3/modtools/components/ModSupportConcernKeywords.vue \
        iznik-nuxt3/modtools/pages/support/[[id]].vue \
        iznik-nuxt3/modtools/stores/systemconfig.js \
        iznik-nuxt3/api/ConfigAPI.js \
        iznik-nuxt3/modtools/components/ModSettingsGroup.vue
git commit -m "feat(keywords): unified concern_keywords modtools UI, replaces separate worry words and spam keyword tabs"
```
