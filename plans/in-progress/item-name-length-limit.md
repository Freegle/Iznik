# Task: Fix item name length limit - restore 60 character maximum

Created: 2026-01-06 17:08:28

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Add maxlength="60" to pages/give/mobile/details.vue | ✅ Complete | Added maxlength attribute to item input |
| 2 | Add maxlength="60" to pages/find/mobile/details.vue | ✅ Complete | Added maxlength attribute to item input |
| 3 | Add maxlength="60" to modtools ModStdMessageModal.vue | ✅ Complete | Added maxlength attribute to item input |
| 4 | Add backend validation in iznik-server | ✅ Complete | Added truncation in Item.php create() + tests |
| 5 | Test frontend changes locally | ⏳ Skip | Frontend maxlength is browser-enforced, no runtime test needed |
| 6 | Test backend validation | ✅ Complete | PHPUnit ItemTest passes (4 tests, 14 assertions) |

## Description

The item name length limit of 60 characters exists in PostItem.vue (maxlength=60) but is missing from several other locations where item names can be entered. This allows users to bypass the limit through mobile pages or modtools.

### Files to modify:

**Frontend (iznik-nuxt3):**
- `components/PostItem.vue` - Already has maxlength="60" (reference)
- `pages/give/mobile/details.vue` - Missing maxlength
- `pages/find/mobile/details.vue` - Missing maxlength

**Frontend (iznik-nuxt3-modtools):**
- `modtools/components/ModStdMessageModal.vue` - Missing maxlength

**Backend (iznik-server):**
- Add server-side validation to truncate or reject item names over 60 characters

## Success Criteria

- All item name input fields have maxlength="60" attribute
- Backend validates and handles item names over 60 characters
- All tests pass
- Code follows coding standards

## Associated PRs

(Will be updated when PRs are created)
