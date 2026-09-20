---
last_reviewed: 2026-08-12
owner: Freegle dev team
covers:
  - iznik-batch/app/Console/Commands/Discourse/AgmCommand.php
  - iznik-batch/app/Services/AgmCategoryService.php
---

# Annual AGM category on Discourse

Each year the AGM gets its own Discourse category. Everyone can read it and reply,
only staff and the Announcers group can start topics, and every Discourse user is
put on "Watching" so the announcements reach them.

This is done with `discourse:agm`, run by hand. It is deliberately not scheduled:
each step is a decision about when to notify every user on the forum.

## The three steps, in order

```bash
docker exec freegle-batch php artisan discourse:agm setup
# ... write the information posts in Discourse ...
docker exec freegle-batch php artisan discourse:agm announce
# ... the AGM happens ...
docker exec freegle-batch php artisan discourse:agm close --year=2026
```

`--year` defaults to the current year. Every action takes `--dry-run`, which
reports what would change and writes nothing.

### setup

Creates "AGM &lt;year&gt;" (slug `agm-<year>`) with these permissions:

| Group | Permission |
|-------|-----------|
| everyone | reply / see |
| staff | create / reply / see |
| Announcers | create / reply / see |

No custom incoming email address is set. The category description is seeded from
`freegle.discourse.agm.description`, which Discourse turns into the body of the
"About the AGM &lt;year&gt; category" topic.

Re-running `setup` on a category that already exists re-applies the permissions
rather than creating a duplicate. It leaves the description alone, because for an
existing category Discourse takes the description from the About topic's first
post, so changing it means editing that post in the UI.

**`setup` does not switch Watching on.** That is the whole reason it is a separate
step: write the information posts first, otherwise every draft and correction
notifies the entire forum.

### announce

Adds the category to the `default_categories_watching` site setting and applies it
to existing users, so everyone is set to Watching. From this point on, new posts in
the category notify every user.

Two things about how Discourse implements this are worth knowing, because both
produce a silent no-op that looks like success:

- The backfill flag is `update_existing_user`, **singular**. The plural spelling is
  accepted and then ignored, which saves the setting while leaving every existing
  user untouched.
- Discourse backfills by diffing the previous value of the setting against the new
  one. Re-sending a value that is already set changes nothing, whatever the flag
  says.

So if the category is already in the setting, `announce` reports that and stops
rather than pretending it did something. Use `--force` to remove and re-add it,
which makes the diff real and does backfill existing users.

### close

Removes the category from `default_categories_watching` with the backfill applied,
which deletes the Watching rows that `announce` created and resets auto-watched
topics to Regular. It then drops `everyone` and `Announcers` to see-only, so the
category stays readable but inert. Staff keep full rights so they can still
moderate.

Topics are kept. Nothing is deleted or archived.

## Who can post

Membership of the Announcers group is managed in the Discourse UI at
`/g`. Anyone in it, plus staff, can start topics in the AGM category.

## Configuration

Under `freegle.discourse.agm` in `iznik-batch/config/freegle.php`:

| Key | Env var | Purpose |
|-----|---------|---------|
| `announcers_group` | `DISCOURSE_AGM_ANNOUNCERS_GROUP` | Group allowed to start topics |
| `colour` / `text_colour` | `DISCOURSE_AGM_COLOUR` / `..._TEXT_COLOUR` | Category badge colours |
| `description` | `DISCOURSE_AGM_DESCRIPTION` | Seeds the About topic. `:name` is replaced with the category name |

The command needs `DISCOURSE_APIKEY` set to an admin key; the site settings
endpoints are admin-only and a moderator key gets 404s. With no key set, the
command warns and exits without doing anything.
