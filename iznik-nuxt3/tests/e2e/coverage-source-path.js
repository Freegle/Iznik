// Source-path handling for the monocart coverage run in playwright.config.js.
//
// The suite drives two Nuxt apps: the main site and ModTools, which extends it.
// ModTools overrides some main files under the same relative path (app.vue,
// layouts/default.vue, pages/index.vue, ...). monocart strips "../" from
// sourcemap sources, so ModTools files were reported under paths that do not
// exist in the repo (components/ModAdmin.vue), and where both apps have the
// same file they collided: monocart keys its output by path alone, so whichever
// version it handled last replaced the other, and that depended on the order
// test workers finished.
//
// Sources the ModTools app owns are reported at their real path, modtools/<path>
// relative to iznik-nuxt3. Layer files ModTools merely bundles from the main
// tree keep their path and still merge with the main app's coverage of them.

const OWNED_PREFIX = 'modtools/'

// In a Nuxt build the maps live in .output/public/_nuxt, three levels below the
// app root, so a source owned by the app being built starts "../../../" and a
// source from its parent layer starts "../../../../".
const OWNED_SOURCE_DEPTH = 3

const isModtoolsDist = (distFile) =>
  typeof distFile === 'string' && distFile.split('/')[0].includes('modtools')

const leadingParentCount = (url) => {
  const match = /^(?:\.\.\/)+/.exec(url)
  return match ? match[0].length / 3 : 0
}

// monocart calls this for each sourcemap source with (path, { url, distFile }),
// and again for istanbul data with (path, item), which has neither field.
function coverageSourcePath(sourcePath, info) {
  if (!info || typeof info.url !== 'string' || !isModtoolsDist(info.distFile)) {
    return sourcePath
  }

  return leadingParentCount(info.url) === OWNED_SOURCE_DEPTH
    ? OWNED_PREFIX + sourcePath
    : sourcePath
}

module.exports = { coverageSourcePath, OWNED_PREFIX }
