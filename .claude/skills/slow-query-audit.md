# Slow Query Audit

Investigate slow/frequent database queries on db3-internal and create a GitHub issue listing optimization opportunities.

## Process

1. **Collect queries from performance_schema**:
   ```bash
   ssh db3-internal "mysql performance_schema -e \"SELECT DIGEST_TEXT, COUNT_STAR, ROUND(AVG_TIMER_WAIT/1000000000, 3) as avg_ms, ROUND(SUM_TIMER_WAIT/1000000000000, 1) as total_s, FIRST_SEEN, LAST_SEEN FROM events_statements_summary_by_digest WHERE SCHEMA_NAME = 'iznik' AND LAST_SEEN > DATE_SUB(NOW(), INTERVAL 1 DAY) AND AVG_TIMER_WAIT > 100000000 ORDER BY AVG_TIMER_WAIT DESC LIMIT 50;\""
   ```
   Also check by total time:
   ```bash
   ssh db3-internal "mysql performance_schema -e \"SELECT DIGEST_TEXT, COUNT_STAR, ROUND(AVG_TIMER_WAIT/1000000000, 1) as avg_ms, ROUND(SUM_TIMER_WAIT/1000000000000, 1) as total_s FROM events_statements_summary_by_digest WHERE SCHEMA_NAME = 'iznik' AND LAST_SEEN > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY SUM_TIMER_WAIT DESC LIMIT 10;\""
   ```

2. **Filter**: Focus on V2 Go API (`iznik-server-go/`) and `iznik-batch` queries. Exclude V1 PHP API (`iznik-server/`). Map each query to its source file using distinctive table/column patterns with Grep.

3. **Analyze**: For each identified query, determine if it can be optimized. Common patterns:
   - OR conditions in JOINs → UNION of simpler queries
   - Double self-joins → UNION of individual conditions
   - Correlated subqueries → JOINs with GROUP BY
   - Missing indexes → add targeted indexes

4. **Benchmark**: Write SQL to a file, SCP to db3-internal, run via `mysql iznik < /tmp/file.sql`.
   - Use `SET profiling = 1;` and `SHOW PROFILES;` to get timing.
   - For each optimization, run BOTH original and proposed query.
   - **Verify same results**: Check row count AND row content match between original and optimized.
   - **Skip benchmarking** for queries that take 60+ seconds — note them as "too slow to benchmark, confirmed slow".

5. **Create GitHub issue**: List all optimization opportunities with:
   - Source file and line number
   - Current avg execution time from performance_schema
   - Total time consumed (indicates frequency × cost)
   - Proposed optimization with explanation
   - Benchmark results (before/after timing, row count verification)
   - Checkbox for each item so they can be tracked

## Tips

- Use `ssh db3-internal` for database access. The database is `iznik`.
- Performance schema timers are in picoseconds. Divide by 1e9 for ms, 1e12 for seconds.
- Use a test user ID (e.g., 20098) for queries that need a userid parameter.
- When escaping backticks in SSH commands, write SQL to a file instead.
- The `memberships` table is very large — queries joining it multiple times are prime optimization targets.
