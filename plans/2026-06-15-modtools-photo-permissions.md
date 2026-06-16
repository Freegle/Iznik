# ModTools Google Play rejection: remove restricted media permissions

**Date:** 2026-06-15
**Branch:** `fix/modtools-photo-permissions`

## Problem

Google Play rejected the ModTools app (version code 1282): `READ_MEDIA_IMAGES`
is a *restricted* permission and "Permission use is not directly related to your
app's core purpose." ModTools only needs occasional photo upload (group IDs,
post images), which does not qualify for broad media access.

## Root cause

Freegle and ModTools are built from the **same** native Android project
(`iznik-nuxt3/android`). The shared `AndroidManifest.xml` hard-codes three
restricted/legacy permissions (`READ_MEDIA_IMAGES`, `READ_EXTERNAL_STORAGE`,
`WRITE_EXTERNAL_STORAGE`). They are **vestigial** — leftovers from the old
Cordova camera plugin that predated the Android Photo Picker:

- Gallery selection (`Camera.pickImages` / `getPhoto` source Photos) uses
  `ActivityResultContracts.PickVisualMedia` — the **Android Photo Picker** —
  which needs **no** permission (verified in `@capacitor/camera` v7.0.2
  `CameraPlugin.java`).
- Taking a photo (`getPhoto` source Camera) needs only `CAMERA` (a *normal*,
  non-restricted permission) on Android 10+.
- The storage perms are only requested for `saveToGallery: true` on Android ≤ 9,
  which the app never sets.

ModTools made the mismatch glaring: it doesn't even bundle `@capacitor/camera`,
so the restricted permission was declared with zero corresponding functionality.

## Fix

| Permission | Freegle | ModTools |
|---|---|---|
| `READ_MEDIA_IMAGES` | removed | removed |
| `READ_EXTERNAL_STORAGE` | removed | removed |
| `WRITE_EXTERNAL_STORAGE` | removed | removed |
| `CAMERA` | kept (in-app capture) | removed |

1. **`iznik-nuxt3/android/app/src/main/AndroidManifest.xml`** — remove the three
   media/storage permissions from the shared base manifest (neither app uses
   them; this also future-proofs Freegle against the same rejection).
2. **`.circleci/orb/freegle-tests.yml`** — in both ModTools build jobs
   (`build-android-modtools-debug`, `build-android-modtools`), convert the
   `CAMERA` declaration to `tools:node="remove"` for the ModTools flavour only
   (mirrors the existing `AD_ID` removal). ModTools ends with **zero**
   camera/media permissions.
3. **`iznik-nuxt3/capacitor.config.modtools.js`** — add `@capacitor/camera` to
   `android`/`ios` `includePlugins` so the "Choose photo" (Photo Picker) and
   "Add photo" buttons in `OurUploader.vue` work in the ModTools app. The
   plugin's own manifest does not declare `READ_MEDIA_IMAGES`/`CAMERA`, so it
   does not re-introduce a restricted permission.

In ModTools, "take a photo" still works via the system `ACTION_IMAGE_CAPTURE`
intent, which needs no permission even with `CAMERA` removed.

## Out-of-code follow-ups (manual)

- Publish the orb after merge: `circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x`.
- Bump the ModTools version and resubmit **all** tracks (production + testing) so
  no live build still declares the restricted permission.
- The Play Console "Photo and Video Permissions" declaration becomes moot once
  the permission is gone from every track.
