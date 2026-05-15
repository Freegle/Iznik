# Spiralling Out: Isochrone-Based Message Notification Expansion

> **Environment**: All commands assume you are on the production server in the `iznik-server/scripts/cli/` directory with appropriate database access.
>
> **Status**: Fresh analysis in progress using local OpenRouteService (ORS) server - October 2025
>
> **Key Change**: We now have a local ORS server, so we can generate isochrones on-demand with no cost constraints. This allows us to simulate ALL messages, not just those with cached Mapbox isochrones.

## Summary (For Freegle Volunteers)

**What is Spiralling Out?**

Spiralling Out is a smart notification system that helps get items to the right people without spamming everyone. When someone posts an OFFER or WANTED, instead of emailing everyone in the group straight away, we start small and gradually expand.

**How does it work?**

1. **Start local**: First, we notify people very close to where the item is located (e.g. within a 5-minute drive)
2. **Wait and watch**: If someone replies or takes the item, we stop. Job done!
3. **Expand gradually**: If there's no response after a few hours, we notify people a bit further away (e.g. 10-minute drive)
4. **Keep expanding**: We continue widening the net every few hours until someone takes the item or we've reached everyone in the group

**Why is this better?**

- **Faster matches**: Close neighbours get notified immediately and can collect quickly
- **Less spammy mails**: Most items get taken by nearby people, so most members don't get notified at all
- **More sustainable**: Members get fewer more relevant emails, so they're less likely to unsubscribe or ignore notifications
- **Smarter geography**: Uses actual travel time, not straight-line distance, so accounts for rivers, hills, and road networks
- **Adapts to your area**: Works differently in dense cities (where 5 minutes covers a small area) vs rural groups (where 5 minutes covers many miles)

**What we're working on:**

This document describes how we're analyzing Freegle groups to find the best notification timings. Should urban groups expand faster than rural ones? Should we wait 4 hours or 6 hours before the next expansion? We're using historical data from real posts to answer these questions and optimize the system for each type of group.

---

## The Problem

When someone posts an OFFER or WANTED message to a Freegle group, we need to decide **who to notify** and **when**.

### The Challenge

- **Notify too few people**: Item doesn't find a new home, user is disappointed
- **Notify too many people**: Spam, notification fatigue, people unsubscribe
- **Notify the wrong people**: People too far away can't collect, wasted notifications
- **Notify too late**: Item already gone, frustrating for responders

### Historical Approaches

1. **Notify everyone in the group**: Too spammy, people hate it
2. **Notify based on keyword matching**: Misses interested people, complex to maintain
3. **Fixed radius**: Doesn't account for transport routes, ignores geography

## The Solution: Spiralling Out with Isochrones

### What Are Isochrones?

An **isochrone** is a geographic area representing everywhere you can reach from a point within a given travel time. Unlike simple radius circles, isochrones account for:

- **Real road networks**: You can't drive through buildings or across rivers
- **Transport modes**: Car isochrones vs walking vs cycling
- **Traffic patterns**: Urban areas are denser, rural areas more spread out

**Example:**
```
5-minute drive isochrone from London city center:
- Might only cover 2km due to traffic
- Follows major roads
- Irregular shape around the Thames

5-minute drive isochrone from rural village:
- Might cover 8km on open country roads
- More circular shape
- Limited by narrow lanes
```

### The Spiralling Out Algorithm

Instead of one fixed notification area, we **expand in stages**:

1. **Initial notification**: Small isochrone (e.g., 5-minute drive)
   - Notifies people very close to the item
   - Low spam, high relevance

2. **Wait for responses**: Give it time (e.g., 4 hours during daytime)
   - If enough people respond → stop, we're done!
   - If not enough interest → expand

3. **Expand isochrone**: Larger travel time (e.g., 10 minutes)
   - Notify NEW users in the expanded area
   - Don't re-notify people already notified

4. **Repeat**: Keep expanding until:
   - We get enough responses (e.g., 7 interested people), OR
   - We reach maximum size (e.g., 60-minute drive), OR
   - Too much time has passed (e.g., 3 days)

5. **Skip nighttime**: Only expand during active hours (e.g., 8am-8pm)
   - People don't respond to notifications at 3am
   - Avoid waking people up

### Key Algorithm Parameters

The algorithm is controlled by several parameters:

| Parameter | Description | Example Value |
|-----------|-------------|---------------|
| `initialMinutes` | Starting isochrone size | 5 minutes |
| `maxMinutes` | Maximum isochrone size | 60 minutes |
| `increment` | How much to expand each time | 5 minutes |
| `minUsers` | Minimum users to add per expansion | 100 users |
| `numReplies` | Stop when we have this many responses | 7 responses |
| `transport` | Travel mode for isochrones | "car" |
| `activeSince` | Only notify users active in last N days | 90 days |

**Temporal expansion curve** (when to expand):
- 0-12 hours: Expand every 4 hours
- 12-24 hours: Expand every 6 hours
- 24-72 hours: Expand every 8 hours

**Active hours**: Only expand during hours when people respond:
- Computed from historical data
- Typically 8am-8pm on weekdays
- May vary by day of week

### Why This Works

1. **Progressive engagement**: Start with highly relevant, close-by people
2. **Demand-responsive**: Only expand if needed
3. **Self-limiting**: Stops when there's enough interest
4. **Geography-aware**: Uses real travel times, not arbitrary distances
5. **Activity-aware**: Only notifies recently active users
6. **Time-aware**: Respects when people actually respond to notifications

### Example Scenario

**Offer: Sofa in North London**

- **0:00** - Posted at 2pm, create 5-minute isochrone → notify 60 nearby users
- **4:00** - 6pm, got 2 responses, expand to 10 minutes → notify 120 new users
- **10:00** - 12am, skip expansion (nighttime)
- **10:00** - 8am next day, got 5 total responses, expand to 15 minutes → notify 150 new users
- **16:00** - 2pm next day, got 8 responses → **STOP**, we have enough interest
- **Final**: Notified 330 users total, got 8 responses (2.4% response rate)

**Wanted: Bicycle pump in rural village**

- **0:00** - Posted at 10am, create 5-minute isochrone → notify 15 users (sparse area)
- **4:00** - 2pm, got 0 responses, expand to 10 minutes → notify 25 new users
- **10:00** - 8pm, got 1 response, expand to 15 minutes → notify 40 new users
- **16:00** - Skip (nighttime)
- **24:00** - 8am next day, got 2 responses, expand to 20 minutes → notify 60 new users
- **30:00** - 2pm next day, got 4 responses, expand to 25 minutes → notify 80 new users
- **38:00** - 10pm, skip (nighttime)
- **46:00** - 6am next day, got 7 responses → **STOP**
- **Final**: Notified 220 users over 2 days, got 7 responses (3.2% response rate)

## The Simulation Page

To determine the optimal parameters, we built a comprehensive simulation framework that analyzes historical data.

### Database Schema

We store simulation data in MySQL tables:

**simulation_message_isochrones_runs**
- Tracks each simulation run
- Stores parameters being tested
- Records aggregate metrics (capture rate, efficiency, etc.)

**simulation_message_isochrones_messages**
- One row per message analyzed
- Links to actual historical message
- Stores message location, group info

**simulation_message_isochrones_users**
- Anonymized active users at the time
- Location data (approximate)
- Whether they replied, when they replied

**simulation_message_isochrones_expansions**
- Each expansion event for each message
- Timestamp, isochrone size, users reached
- Replies received by that time

### How Simulation Works

1. **Select historical messages**: e.g., all OFFERs/WANTEDs from last 30 days
2. **For each message**:
   - Get message location
   - Get all active users in the group at that time
   - Get actual chat responses ("Interested" messages)
   - Get who actually took the item (if known)

3. **Simulate the algorithm**:
   - Create initial isochrone at message arrival time
   - Determine which users would be notified
   - Advance time according to expansion curve
   - Expand isochrone, count new users notified
   - Check how many replies received by each time point
   - Track when taker (if known) would be reached
   - Stop when algorithm conditions met

4. **Calculate metrics**:
   - **Capture rate**: % of actual replies from within final isochrone
     - High = we're targeting the right area
   - **Efficiency**: % of notified users who replied
     - High = we're not over-notifying
   - **Taker reach**: Did we notify the person who took it?
   - **Taker reach time**: How quickly did we notify them?
   - **Expansions**: How many expansion stages were needed?

### Metrics Output

Results will be populated as we complete the analysis.

### Simulation Scripts

**spiralling_investigation_simulate.php**
- Main simulation script
- Uses hardcoded parameters for testing
- Stores results in database for analysis

**spiralling_investigation_simulate_temporal.php**
- Extended version with configurable temporal expansion curves
- Used by optimizer for testing different timing strategies

**Usage:**
```bash
# Simulate 100 messages from single group
php spiralling_investigation_simulate.php \
  --start=2025-09-01 --end=2025-09-30 \
  --group=12345 --limit=100 \
  --name="Test Run"

# Simulate multiple groups with custom minimum users
php spiralling_investigation_simulate.php \
  --groups="12345,67890,11111" --limit=50 \
  --min-users=150 --name="Multi-Group Test"
```

## Parameter Optimization Strategy

The key challenge: **What parameter values should we use?**

### The Core Question

**Do different types of groups need different parameters?**

- Urban dense groups (London, Manchester): Small isochrones, high user density
- Rural large groups (counties): Large isochrones, sparse user density
- Suburban groups: Medium size, medium density

**With local ORS server (no cost constraints):**

We can now optimize using ALL historical data for each group type. No need for representative sampling or clustering - we use official ONS classification and simulate everything.

### Simplified Two-Phase Strategy

#### Phase 1: Classify All Groups by Official ONS Category

**Script:** `spiralling_investigation_analyze_group_characteristics.php`

**What it does:**
1. Gets all Freegle groups with catchment area (CGA) polygons
2. Calculates geographic metrics: area, compactness, perimeter
3. Looks up official ONS Rural-Urban classification from external data
4. Calculates activity metric: messages per week
5. Classifies groups based on ONS category

**Features calculated:**
- **Geographic:** Area (km²), perimeter, compactness
- **Activity:** Messages per week
- **Classification:** ONS RU category (A1-F2), region, group type

**ONS Classification System:**
- **A1** = Urban Major Conurbation (London, Manchester, Glasgow, Edinburgh)
- **B1** = Urban Minor Conurbation (Aberdeen, Dundee)
- **C1/C2** = Urban City and Town
- **D1/D2** = Rural Town and Fringe
- **E1/E2** = Rural Village
- **F1/F2** = Rural Hamlets and Isolated Dwellings

**For Scotland/NI:** Uses geographic fallback (major cities classified correctly, rest defaults to rural)

**Output:** `group_characteristics_ons.csv`

**Time:** ~5-10 minutes for 489 groups (fast - no expensive user queries!)

**Run it:**
```bash
cd /var/www/iznik/scripts/cli
php spiralling_investigation_analyze_group_characteristics.php \
  --output=/tmp/group_characteristics_ons.csv
```

**Phase 1 Results:**

Successfully analyzed **489 active Freegle groups** with the following distribution:

**Groups by Type:**
- **Urban Dense (A1)**: 86 groups (17.6%)
- **Urban Moderate (B1, C1)**: 151 groups (30.9%)
- **Suburban (C2, D1, D2)**: 26 groups (5.3%)
- **Rural Village (E1, E2)**: 101 groups (20.7%)
- **Rural Sparse (F1, F2)**: 125 groups (25.6%)

**Geographic Statistics:**
- **Area (km²)**: Min: 3.01, Max: 24,824.87, Median: 192.57
- **Activity (messages/week)**: Min: 0, Max: 293.75, Median: 13.25

**Key Insights:**
- Combined Urban categories (A1, B1, C1) represent 48.5% of all groups (237 groups)
- Rural categories (E1, E2, F1, F2) represent 46.2% of all groups (226 groups)
- Suburban groups are relatively rare at just 5.3%
- Wide variation in group size (3 km² to 24,825 km²) suggests parameters may need to vary
- Activity levels vary dramatically (0 to 294 msg/wk), confirming diverse group characteristics

**Output:** Results exported to `/tmp/group_characteristics_ons.csv`

#### Phase 2: Optimize Parameters by Category

**Script:** `spiralling_investigation_optimize_parameters.php`

Now that we have local ORS (no cost constraints), we optimize using ALL historical data for each geographic category.

**Approach:**
1. Group all 489 groups by their ONS classification
2. For each category, run optimization on ALL their recent messages
3. Compare if parameters differ significantly across categories

**Categories to test:**
- **Urban** (A1, B1, C1): Dense areas, small isochrones
- **Suburban** (C2, D1, D2): Medium density
- **Rural** (E1, E2, F1, F2): Sparse areas, large isochrones

**Run it:**
```bash
# Urban groups
php spiralling_investigation_optimize_parameters.php \
  --categories="A1,B1,C1" --iterations=100 --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/urban_optimization.db

# Suburban groups
php spiralling_investigation_optimize_parameters.php \
  --categories="C2,D1,D2" --iterations=100 --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/suburban_optimization.db

# Rural groups
php spiralling_investigation_optimize_parameters.php \
  --categories="E1,E2,F1,F2" --iterations=100 --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/rural_optimization.db
```

**Time:** Several hours per category (but uses ALL data for robust results)

#### Phase 3: Compare and Decide

Compare the optimized parameters across categories:

1. Extract best parameters from each optimization database
2. Calculate coefficient of variation (CV) for each parameter
3. **Decision:**
   - **CV < 20%**: Parameters similar → Use **global parameters**
   - **CV > 20%**: Parameters differ → Use **category-specific parameters**

**If category-specific:** Map ONS categories to parameter sets in production code

### Bayesian Optimization

**Script:** `spiralling_investigation_optimize_parameters.php`

Uses two-stage Bayesian optimization:

**Stage 1: Spatial Parameters**
- Search space: initialMinutes, maxMinutes, increment, minUsers, activeSince, numReplies
- Fixed temporal: expansion curve from simulation
- Method:
  - First 15 iterations: Latin Hypercube Sampling (exploration)
  - Remaining: Bayesian-inspired sampling near best results

**Stage 2: Temporal Parameters**
- Search space: breakpoint1, breakpoint2, interval1, interval2, interval3
- Fixed spatial: best from Stage 1
- Same Bayesian approach

**Success Metric:**
```
For each parameter set:
  Run simulation on sample of messages
  Success = (reached_taker OR got_enough_replies) AND within_time_limit
  Score = success_rate (0.0 to 1.0)
```

**Output:**
- SQLite database with all tested parameters and scores
- Best parameters with highest score
- Optimization history (can export to CSV for analysis)

## Complete Workflow Example

```bash
# Step 1: Classify all groups by ONS category (~5-10 minutes)
cd /var/www/iznik/scripts/cli
php spiralling_investigation_analyze_group_characteristics.php \
  --output=/tmp/group_characteristics_ons.csv

# Review results - check distribution across ONS categories
head -20 /tmp/group_characteristics_ons.csv

# Step 2: Optimize parameters for each geographic category
# Urban groups (A1, B1, C1)
php spiralling_investigation_optimize_parameters.php \
  --categories="A1,B1,C1" \
  --iterations=100 \
  --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/urban_optimization.db

# Suburban groups (C2, D1, D2)
php spiralling_investigation_optimize_parameters.php \
  --categories="C2,D1,D2" \
  --iterations=100 \
  --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/suburban_optimization.db

# Rural groups (E1, E2, F1, F2)
php spiralling_investigation_optimize_parameters.php \
  --categories="E1,E2,F1,F2" \
  --iterations=100 \
  --ors-server=http://10.220.0.103:8081/ors \
  --db-path=/tmp/rural_optimization.db

# Step 3: Compare results and decide
# Extract best parameters from each database
# Calculate coefficient of variation
# If CV < 20%: use global parameters
# If CV > 20%: use category-specific parameters
```

## Why This Approach Works

### No Cost Constraints
- **Local ORS server**: Unlimited isochrone generation at no cost
- **Use ALL data**: Simulate every recent message, not just samples
- **Statistical robustness**: Larger sample sizes = more confident results

### Official Classification
- **ONS data**: Government-maintained urban/rural classification
- **Independent of our users**: Classification based on location, not Freegle penetration
- **Well-researched**: Categories reflect actual travel patterns and demographics

### Simple and Direct
- **No clustering needed**: Official ONS categories are the natural groupings
- **Clear categories**: Urban (A1, B1, C1), Suburban (C2, D1, D2), Rural (E1, E2, F1, F2)
- **Actionable results**: Direct answer to "Do different areas need different parameters?"

## Understanding Metrics and Parameters

### Group Characteristics Metrics

**Compactness (0-1):**
- 1.0 = perfect circle (very compact)
- 0.5 = moderately irregular shape
- 0.1 = very elongated or fragmented
- Formula: 4π × area / perimeter²
- Urban areas tend to be more compact, rural areas more irregular

**User Density (users/km²):**
- **Urban dense**: >10 users/km²
- **Urban moderate**: 5-10 users/km²
- **Suburban**: 2-5 users/km²
- **Rural**: <2 users/km²

**Urban Percentage (0-100%):**
- Estimated from user clustering patterns
- High % = users are close together (urban-like density)
- Low % = users are spread out (rural-like sparsity)
- Based on proportion of user pairs within 2km of each other

### Parameter Validation Metrics

**Coefficient of Variation (CV):**
- Measures relative variability: (stddev / mean) × 100%
- **CV < 10%**: Very consistent across clusters
- **CV 10-20%**: Moderately consistent (probably ok for global parameters)
- **CV > 20%**: Significantly different (needs cluster-specific parameters)

**Example Parameter Analysis:**
```
Parameter: minUsers (minimum users per expansion)
  Cluster 0: 150
  Cluster 1: 140
  Cluster 2: 145
  Cluster 3: 155
  Mean: 147.5, Stddev: 6.5
  CV: 4.4% → CONSISTENT ✓
  Decision: Can use same minUsers globally

Parameter: initialMinutes (starting isochrone size)
  Cluster 0: 5   (dense urban - small start)
  Cluster 1: 8   (suburban - medium start)
  Cluster 2: 12  (rural - large start)
  Cluster 3: 15  (sparse rural - very large start)
  Mean: 10, Stddev: 4.3
  CV: 43% → VARIES SIGNIFICANTLY ✗
  Decision: Need cluster-specific initialMinutes
```

### Files Generated by Analysis

| File | Description | Size | Purpose |
|------|-------------|------|---------|
| `group_characteristics.csv` | All groups with calculated metrics | ~50KB | Clustering input |
| `group_clusters.csv` | All groups with cluster assignments | ~55KB | Production mapping |
| `group_clusters_representatives.csv` | 3 representatives per cluster | ~2KB | Optimization input |
| `cluster_parameter_validation.json` | Optimization results + recommendation | ~100KB | Decision making |
| `cluster_<N>_optimization.db` | SQLite DB with full optimization history | ~500KB each | Analysis, tuning |
| `messages_by_spread.csv` | Geographic spread message selection | ~10KB | Simulation input |

### Advanced Options

**Custom Clustering:**
Try different numbers of clusters to see which grouping makes most sense:

```bash
# Try 3 clusters (urban, suburban, rural)
php spiralling_investigation_cluster_groups.php --clusters=3

# Try 5 clusters (more granular)
php spiralling_investigation_cluster_groups.php --clusters=5
```

**Focus on Specific Date Range:**
```bash
# Analyze peak season (avoid holiday periods with unusual behavior)
php spiralling_investigation_validate_clusters.php \
  --start=2025-03-01 --end=2025-04-30
```

**Higher Precision Validation:**
```bash
# More iterations = more confident results, but slower
# Use when making final production decisions
php spiralling_investigation_validate_clusters.php --iterations=100
```

**Select Messages by Geographic Spread:**
```bash
# Ensure optimization sees messages from across entire CGA
php spiralling_investigation_select_messages_by_spread.php --group=12345 --count=50 \
  --output=/tmp/messages.csv
```

## Integration with Production

Once you have optimized parameters, they need to be integrated into the codebase.

### Global Parameters (if validation recommends)

Update default parameters in `Isochrone.php` or configuration:

```php
// In Isochrone.php or config
const DEFAULT_ISOCHRONE_PARAMS = [
    'initialMinutes' => 8,
    'maxMinutes' => 90,
    'increment' => 5,
    'minUsers' => 120,
    'activeSince' => 90,
    'numReplies' => 7,
    'transport' => 'car',

    // Temporal expansion curve
    'breakpoint1' => 12,  // hours
    'breakpoint2' => 24,  // hours
    'interval1' => 4,     // hours
    'interval2' => 6,     // hours
    'interval3' => 8      // hours
];
```

### Cluster-Specific Parameters (if validation recommends)

Create cluster mapping in code:

```php
// Load cluster assignments from CSV (one-time setup)
private static $groupClusters = null;

private static function loadGroupClusters() {
    if (self::$groupClusters === null) {
        self::$groupClusters = [];

        // Load from database or cached file
        $csv = file_get_contents('/var/www/iznik/data/group_clusters.csv');
        $lines = explode("\n", $csv);
        array_shift($lines); // Remove header

        foreach ($lines as $line) {
            if (empty($line)) continue;
            list($groupId, $clusterId) = explode(',', $line);
            self::$groupClusters[intval($groupId)] = intval($clusterId);
        }
    }

    return self::$groupClusters;
}

// Define cluster-specific parameters
private static $clusterParams = [
    0 => [ // Urban dense
        'initialMinutes' => 5,
        'maxMinutes' => 60,
        'increment' => 5,
        'minUsers' => 150,
        'breakpoint1' => 8,
        'breakpoint2' => 18,
    ],
    1 => [ // Suburban
        'initialMinutes' => 8,
        'maxMinutes' => 90,
        'increment' => 5,
        'minUsers' => 120,
        'breakpoint1' => 12,
        'breakpoint2' => 24,
    ],
    2 => [ // Rural large
        'initialMinutes' => 12,
        'maxMinutes' => 120,
        'increment' => 8,
        'minUsers' => 80,
        'breakpoint1' => 12,
        'breakpoint2' => 30,
    ],
    3 => [ // Rural small
        'initialMinutes' => 10,
        'maxMinutes' => 100,
        'increment' => 6,
        'minUsers' => 100,
        'breakpoint1' => 10,
        'breakpoint2' => 24,
    ]
];

// Get parameters for a specific group
public static function getParametersForGroup($groupId) {
    $clusters = self::loadGroupClusters();
    $clusterId = $clusters[$groupId] ?? 0; // Default to cluster 0

    return self::$clusterParams[$clusterId] ?? self::$clusterParams[0];
}

// Use in isochrone expansion
public function expandForMessage($messageId, $groupId) {
    $params = self::getParametersForGroup($groupId);

    // Use $params['initialMinutes'], $params['maxMinutes'], etc.
    // ...
}
```

## Troubleshooting

**"No groups found with CGA"**
- Check that groups have `polyindex` polygons set
- Verify `type = 'Freegle'` and `publish = 1`
- Run: `SELECT COUNT(*) FROM groups WHERE type='Freegle' AND polyindex IS NOT NULL`

**"Not enough active users"**
- Try adjusting the 90-day lookback window (increase to 120 days)
- Check `users_approxlocs` table has recent data
- Run: `SELECT COUNT(DISTINCT userid) FROM users_approxlocs WHERE timestamp > DATE_SUB(NOW(), INTERVAL 90 DAY)`

**"K-means didn't converge"**
- This is normal for some datasets
- Results are still valid if algorithm ran close to max iterations
- Try different random seed by re-running

**"All parameters have low CV but validation says cluster-specific"**
- Check the actual parameter values in the JSON output
- Some parameters may have high CV even if others don't
- Review detailed per-parameter analysis

**"Messages all from one area of CGA"**
- Use `spiralling_investigation_select_messages_by_spread.php` instead of time-based selection
- Ensures geographic diversity in optimization sample

**"Optimization score is very low (<50%)"**
- Check date range - may include holiday periods with unusual behavior
- Verify parameters are in reasonable ranges
- Look at individual message results to understand failure modes

## Performance Notes

**Computational Complexity:**
- `spiralling_investigation_analyze_group_characteristics.php`: O(n) where n = number of groups (~2-5 min for 300 groups)
- `spiralling_investigation_cluster_groups.php`: O(n × k × i) where k = clusters, i = iterations (~10 seconds)
- `spiralling_investigation_validate_clusters.php`: O(k × r × m × t) where:
  - k = number of clusters
  - r = representatives per cluster
  - m = optimization iterations
  - t = sample size per iteration
  - Total: ~30-60 minutes with defaults

**Database Impact:**
- Simulation creates temporary tables, cleaned up automatically
- Optimization uses SQLite, not MySQL (no production DB impact)
- Can run safely on production servers with low priority

## Implementation Roadmap

### Phase 1: Data Collection & Analysis ✓ (Complete)
- [x] Simulation framework
- [x] Group characteristics analysis
- [x] Clustering algorithm
- [x] Parameter validation framework
- [x] Bayesian optimization

### Phase 2: Optimization Runs (In Progress)
- [ ] Run validation on representative groups
- [ ] Determine global vs cluster-specific approach
- [ ] Run full optimization based on validation
- [ ] Analyze and validate results

### Phase 3: Integration (Future)
- [ ] Add isochrone expansion to message posting flow
- [ ] Implement notification queue with timing
- [ ] Add cluster parameter mapping to code
- [ ] Create admin UI to view isochrone expansions

### Phase 4: Testing & Rollout (Future)
- [ ] A/B test on small group subset
- [ ] Monitor response rates, unsubscribes
- [ ] Gradual rollout to all groups
- [ ] Continuous monitoring and re-tuning

## Expected Benefits

### For Users
- **Less spam**: Only notified if item is realistically reachable
- **Better timing**: Notifications during active hours
- **Higher relevance**: Progressive expansion means closer people get priority

### For Freegle
- **Higher response rates**: Right people at right time
- **Lower unsubscribe rates**: Less notification fatigue
- **Better outcomes**: More items rehomed successfully
- **Data-driven**: Parameters optimized on real historical data

### Metrics to Track

**Pre-launch (baseline):**
- Notification volume per message
- Response rate (% of notified users who respond)
- Outcome rate (% of messages that result in item being taken)
- Unsubscribe rate

**Post-launch (compare):**
- Did notification volume decrease? (Good if yes)
- Did response rate increase? (Good if yes)
- Did outcome rate increase? (Good if yes)
- Did unsubscribe rate decrease? (Good if yes)

## Technical Details

### Isochrone Generation

We use the **Isochrone.php** class which integrates with geospatial services:

```php
$isochrone = new Isochrone($dbhr, $dbhm);
$isochroneId = $isochrone->ensureIsochroneExists(
    $locationId,    // Message location
    $minutes,       // Travel time (e.g., 5, 10, 15...)
    'car'          // Transport mode
);
```

Isochrones are cached in the database to avoid regenerating identical ones.

### Notification Queue

Messages will have expansion events scheduled:

```sql
CREATE TABLE message_isochrone_expansions (
    msgid BIGINT,
    expansion_num INT,
    scheduled_time TIMESTAMP,
    executed_time TIMESTAMP,
    minutes INT,
    users_notified INT,
    responses_at_time INT
);
```

Background worker checks for scheduled expansions and executes them.

### User Selection

For each expansion:
1. Get isochrone polygon
2. Query users within polygon who:
   - Are members of the group
   - Have been active in last 90 days
   - Have not been notified already for this message
   - Have notification preferences enabled
3. Send notifications
4. Record who was notified and when

### Stopping Conditions

Algorithm stops when ANY of these conditions is met:
- Got enough responses (e.g., 7 interested users)
- Reached maximum isochrone size (e.g., 60 minutes)
- Reached maximum time (e.g., 72 active hours)
- Message was withdrawn/taken

## Frequently Asked Questions

**Q: Why not just notify everyone?**
A: Notification fatigue leads to unsubscribes. Better to notify fewer, more relevant people.

**Q: Why car travel time instead of distance?**
A: A 5-mile radius in London is very different from 5 miles in Devon. Travel time accounts for real-world accessibility.

**Q: What if someone is just outside the final isochrone?**
A: They can still see the message in browse/search. Notifications are just one discovery method.

**Q: Will this work for rural areas with few users?**
A: Yes - the algorithm expands further in sparse areas. Simulation validates this across different group types.

**Q: What about walking/cycling?**
A: Currently using car isochrones as most items require car collection. Could add cycling for small items in future.

**Q: How do we handle cross-posted messages?**
A: Each group has independent isochrone expansion. If posted to multiple groups, algorithm runs separately for each.

**Q: Can users opt out?**
A: Yes, all existing notification preferences are respected. This only changes WHO and WHEN, not whether they're enrolled.

**Q: What about messages posted at midnight?**
A: Initial isochrone is created, but first expansion waits until active hours (e.g., 8am).

## Future Enhancements

1. **Real urban density data**: Integrate UK census data or ONS urban/rural classification
   - More accurate urban percentage estimates
   - Could incorporate population density, income, demographics

2. **Temporal variation**: Test if parameters should vary by time of day/week
   - Weekday vs weekend behavior might differ
   - Evening posts vs morning posts might need different strategies

3. **Seasonal adjustment**: Different parameters for summer vs winter
   - User activity patterns change seasonally
   - Travel behavior varies (people more active in summer)

4. **Automated re-tuning**: Periodic re-optimization as user behavior evolves
   - Run quarterly optimizations
   - Detect parameter drift
   - Auto-adjust if behavior changes significantly

5. **Machine learning integration**: Use ML to predict optimal expansion timing
   - Learn from historical success patterns
   - Personalized expansion based on message characteristics
   - Predict probability of reply for each user

6. **A/B testing framework**: Test different parameters in production
   - Randomly assign messages to different parameter sets
   - Measure actual outcomes, not just simulation
   - Continuous optimization loop

## Script Reference

All scripts are located in `iznik-server/scripts/cli/`. Run from that directory.

### Simulation & Optimization
- **Simulation framework**: `spiralling_investigation_simulate.php`
- **Temporal simulator**: `spiralling_investigation_simulate_temporal.php`
- **Parameter optimizer**: `spiralling_investigation_optimize_parameters.php`

### Analysis & Clustering
- **Group analysis**: `spiralling_investigation_analyze_group_characteristics.php`
- **Clustering**: `spiralling_investigation_cluster_groups.php`
- **Validation**: `spiralling_investigation_validate_clusters.php`
- **Geographic sampling**: `spiralling_investigation_select_messages_by_spread.php`

### Documentation
- **This plan**: `plans/Spiralling Out.md`
- **Database schema**: See `simulation_message_isochrones_*` tables in MySQL
