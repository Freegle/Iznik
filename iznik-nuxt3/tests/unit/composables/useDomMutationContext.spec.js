import { describe, it, expect, afterEach } from 'vitest'
import {
  addDomMutationContext,
  domMutationContext,
  isDomMutationError,
} from '~/composables/useDomMutationContext'

const eventWith = (value) => ({ exception: { values: [{ value }] } })

describe('isDomMutationError', () => {
  it.each([
    "Failed to execute 'insertBefore' on 'Node': The node before which the new node is to be inserted is not a child of this node.",
    "Failed to execute 'removeChild' on 'Node'",
    'The object can not be found here.',
    "Failed to execute 'appendChild' on 'Node': parameter 1 is not of type 'Node'.",
  ])('is true for %s', (value) => {
    expect(isDomMutationError(eventWith(value))).toBe(true)
  })

  it.each([
    eventWith('Cannot read properties of undefined'),
    { message: 'plain message' },
    {},
    null,
  ])('is false for %j', (event) => {
    expect(isDomMutationError(event)).toBe(false)
  })
})

describe('domMutationContext', () => {
  afterEach(() => {
    document.documentElement.className = ''
    document.documentElement.removeAttribute('lang')
    document.body.innerHTML = ''
  })

  it('reports a plain page as untranslated', () => {
    expect(domMutationContext(document)).toEqual({
      translated: false,
      htmlLang: null,
      fontElements: 0,
      translateWidget: false,
    })
  })

  it('reports a translated page', () => {
    document.documentElement.className = 'translated-ltr'
    document.documentElement.setAttribute('lang', 'fr')
    document.body.innerHTML =
      '<font><font>Bonjour</font></font><div class="skiptranslate"></div>'

    expect(domMutationContext(document)).toEqual({
      translated: true,
      htmlLang: 'fr',
      fontElements: 2,
      translateWidget: true,
    })
  })
})

describe('addDomMutationContext', () => {
  it('adds the context and a tag to a DOM mutation error', () => {
    document.documentElement.className = 'translated-rtl'
    const event = addDomMutationContext(
      { ...eventWith('The object can not be found here.'), tags: { a: '1' } },
      document
    )

    expect(event.contexts.dom_mutation.translated).toBe(true)
    expect(event.tags).toEqual({ a: '1', page_translated: 'true' })
    document.documentElement.className = ''
  })

  it('leaves other events alone', () => {
    const event = eventWith('Cannot read properties of undefined')

    expect(addDomMutationContext(event, document)).toBe(event)
    expect(event.contexts).toBeUndefined()
  })
})
