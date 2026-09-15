---
paths:
  - "iznik-batch/**/*.php"
---

# Traps in the Laravel batch code

All of these fail quietly. A green test run does not clear them.

## `Http::fake()` merges - the first stub wins

`Http::fake($closure)` does **not** replace a previous fake. It merges the callback into the
stub list and the first registered callback that returns a response wins. A test helper called a
second time with different data is therefore silently ignored, and the second run re-reads the
first feed.

The tell is a result array that makes no sense for the feed you think you sent, such as an
`updated` count when the new feed contained only an unseen id. Build the second response inside
the original closure, keyed on the request, rather than registering a second fake.

## Never put a Mailable into its own view data

`build()` doing `array_merge(['mail' => $this], $content)` so templates can call
`$mail->trackedUrl()` **segfaults the Blade renderer (exit 139) under PHP 8.4**, before any MJML
compile. The same template renders fine without the object.

Pass plain strings instead: pre-wrap tracked URLs, hand the open pixel over as a ready MJML
string, and guard with `@if(!empty($trackingPixelMjml))`.

Debugging it is its own trap - the crash looks like an MJML problem and it is not. Bisect by
rendering with and without the object. A segfault kills the process, so capture the MJML and
compile it separately to prove the compiler is fine. After template edits run
`php artisan view:clear` and remove `storage/framework/views/*.php`.

## `Schema::hasTable()` / `hasColumn()` guards rot silently

A guard that hard-codes a name becomes permanently false when a migration renames the thing, and
nothing reports it. One guard sat false for months after its table was renamed, harmless only
because the method returned an empty array on the next line anyway.

When you rename a table or column, grep for the old name in `hasTable`/`hasColumn` guards. When
you find a guard whose migration has long since shipped, delete the guard rather than leaving it.

## Adding a foreign key needs `foreign_key_checks = 0` to stay in place

MySQL 8 supports `ALGORITHM=INPLACE` for adding a FOREIGN KEY **only when the session has
`foreign_key_checks = 0`**. With checks on, the server silently downgrades the ALTER to
`ALGORITHM=COPY`, a full table rebuild, even when the referencing column is freshly added,
indexed and entirely NULL. On a large table under Galera that is an outage-shaped mistake.

For any foreign key added to a big table:

1. `SET SESSION foreign_key_checks = 0;` - safe when the column is all NULL.
2. State `ALGORITHM=INPLACE` explicitly, so anything copy-shaped refuses rather than proceeding.
3. On the production cluster, run it node by node the way index adds are run.
4. Combine index adds and foreign key adds into one ALTER so that dance happens once.

## See also

- `.claude/rules/go-api-traps.md` - the same class of silent wrong answer on the Go side.
- Migrations in `iznik-batch/database/migrations/` are the single source of truth for schema.
