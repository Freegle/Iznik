// Vue template node transform that stamps each component's root element with
// data-component="<ComponentName>", derived from the file name.
//
// Production builds drop the component names Vue keeps in dev, so the interaction capture and the
// impression tracker read this attribute instead (closest('[data-component]')). It is a compile-time
// string change: no runtime cost beyond the attribute itself.
//
// Only a native element that is the sole root (or one branch of a v-if / v-else-if / v-else chain)
// is stamped. Fragments, <slot>, <template>, <Teleport> and other component roots are left alone:
// stamping a multi-root template would add a fallthrough attribute that Vue warns about, and a
// component root passes its attributes down and would overwrite the child's own name.

// Numeric values of Vue's compiler NodeTypes / ElementTypes, to avoid a build-time import.
const ROOT = 0
const ELEMENT = 1
const TEXT = 2
const COMMENT = 3
const ATTRIBUTE = 6
const DIRECTIVE = 7
const PLAIN_ELEMENT = 0

const ATTR = 'data-component'

export function componentNameFromFile(filename) {
  if (!filename) return null

  const path = filename.replace(/\\/g, '/').split('?')[0]

  if (path.includes('/node_modules/') || !path.endsWith('.vue')) return null

  const clean = (s) => s.replace(/[^A-Za-z0-9_-]/g, '')
  const pages = path.match(/\/pages\/(.+)\.vue$/)

  if (pages) {
    // Page files are mostly index.vue or [id].vue, so keep their route-relative path.
    return pages[1].split('/').map(clean).filter(Boolean).join('/') || null
  }

  const parts = path.slice(0, -'.vue'.length).split('/')
  const base = clean(parts.pop())

  return base === 'index' ? clean(parts.pop() || '') || null : base || null
}

function directive(el, names) {
  return el.props.find((p) => p.type === DIRECTIVE && names.includes(p.name))
}

function isBlank(n) {
  return (
    n.type === COMMENT || (n.type === TEXT && !String(n.content).trim().length)
  )
}

// The elements that could be the component's single root, or null for a fragment.
function rootElements(root) {
  const nodes = root.children.filter((n) => !isBlank(n))

  if (!nodes.length || nodes.some((n) => n.type !== ELEMENT)) return null

  const [first, ...rest] = nodes

  if (directive(first, ['else', 'else-if'])) return null

  if (rest.some((n) => !directive(n, ['else', 'else-if']))) return null

  if (nodes.some((n) => directive(n, ['for']))) return null

  return nodes
}

export function componentNameTransform(node, context) {
  if (node.type !== ROOT) return

  const name = componentNameFromFile(context.filename)
  if (!name) return

  const roots = rootElements(node)
  if (!roots) return

  for (const el of roots) {
    if (
      el.tagType !== PLAIN_ELEMENT ||
      el.props.some((p) => p.name === ATTR || p.arg?.content === ATTR)
    ) {
      continue
    }

    el.props.push({
      type: ATTRIBUTE,
      name: ATTR,
      nameLoc: el.loc,
      value: { type: TEXT, content: name, loc: el.loc },
      loc: el.loc,
    })
  }
}
