import { describe, it, expect } from 'vitest'
import { compile } from '@vue/compiler-dom'
import {
  componentNameFromFile,
  componentNameTransform,
} from '../../../build-plugins/componentNameTransform'

function render(template, filename = '/app/components/MessageSummary.vue') {
  const warnings = []
  const { code } = compile(template, {
    filename,
    nodeTransforms: [componentNameTransform],
    onWarn: (w) => warnings.push(w.message),
  })
  return { code, warnings }
}

const stamps = (code) => (code.match(/"data-component"/g) || []).length

describe('componentNameFromFile', () => {
  it('uses the file name', () => {
    expect(componentNameFromFile('/app/components/MessageSummary.vue')).toBe(
      'MessageSummary'
    )
  })

  it('handles Windows paths and query strings', () => {
    expect(
      componentNameFromFile('C:\\app\\components\\NoticeMessage.vue?vue&x=1')
    ).toBe('NoticeMessage')
  })

  it('keeps the route-relative path for pages', () => {
    expect(componentNameFromFile('/app/pages/explore/[[id]].vue')).toBe(
      'explore/id'
    )
    expect(componentNameFromFile('/app/pages/index.vue')).toBe('index')
  })

  it('uses the folder for an index component', () => {
    expect(componentNameFromFile('/app/components/Thing/index.vue')).toBe(
      'Thing'
    )
  })

  it('ignores node_modules and non-vue files', () => {
    expect(componentNameFromFile('/app/node_modules/x/Y.vue')).toBeNull()
    expect(componentNameFromFile('/app/foo.js')).toBeNull()
    expect(componentNameFromFile(undefined)).toBeNull()
  })
})

describe('componentNameTransform', () => {
  it('stamps a single root element', () => {
    const { code } = render('<div class="a"><span>hi</span></div>')
    expect(code).toContain('"data-component": "MessageSummary"')
    expect(stamps(code)).toBe(1)
  })

  it('only stamps the root, not descendants', () => {
    const { code } = render('<div><section><p>x</p></section></div>')
    expect(stamps(code)).toBe(1)
  })

  it('ignores surrounding whitespace and comments', () => {
    const { code } = render('<!-- c -->\n  <div>x</div>\n')
    expect(stamps(code)).toBe(1)
  })

  it('stamps every branch of a v-if chain', () => {
    const { code, warnings } = render(
      '<div v-if="a">a</div><p v-else-if="b">b</p><span v-else>c</span>'
    )
    expect(stamps(code)).toBe(3)
    expect(warnings).toEqual([])
  })

  it('stamps a root that has a v-if on its own', () => {
    const { code } = render('<div v-if="a">a</div>')
    expect(stamps(code)).toBe(1)
  })

  it('leaves a fragment alone', () => {
    const { code } = render('<div>a</div><div>b</div>')
    expect(stamps(code)).toBe(0)
  })

  it('leaves a v-for root alone', () => {
    const { code } = render('<div v-for="i in 3" :key="i">x</div>')
    expect(stamps(code)).toBe(0)
  })

  it('leaves text roots alone', () => {
    const { code } = render('hello <div>x</div>')
    expect(stamps(code)).toBe(0)
  })

  it('leaves component, slot and template roots alone', () => {
    expect(stamps(render('<BButton>x</BButton>').code)).toBe(0)
    expect(stamps(render('<slot />').code)).toBe(0)
    expect(stamps(render('<template v-if="a"><p>x</p></template>').code)).toBe(
      0
    )
    expect(
      stamps(render('<Teleport to="body"><div>x</div></Teleport>').code)
    ).toBe(0)
  })

  it('does not duplicate an explicit data-component', () => {
    const { code } = render('<div data-component="Custom">x</div>')
    expect(stamps(code)).toBe(1)
    expect(code).toContain('Custom')
  })

  it('does nothing for files it cannot name', () => {
    const { code } = render('<div>x</div>', '/app/node_modules/a/B.vue')
    expect(stamps(code)).toBe(0)
  })
})
