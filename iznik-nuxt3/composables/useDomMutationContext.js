// Facts about the page that tell whether something outside Freegle has been rewriting it.
//
// Vue's insert and remove calls fail ("insertBefore ... not a child of this node", "The object can
// not be found here") when another script has moved or wrapped the nodes Vue was tracking. The
// usual culprits are page translation (which wraps text in <font> elements and sets a class on
// <html>) and extensions. The error alone cannot say which, so each such event carries these.
const DOM_ERROR =
  /insertBefore|removeChild|appendChild|not a child of this node|The object can not be found here|is not of type 'Node'/

export function isDomMutationError(event) {
  const values = event?.exception?.values || []

  return values.some((v) => DOM_ERROR.test(String(v?.value || '')))
}

export function domMutationContext(doc) {
  const root = doc?.documentElement

  return {
    translated: /\btranslated-(ltr|rtl)\b/.test(root?.className || ''),
    htmlLang: root?.getAttribute?.('lang') || null,
    fontElements: doc?.querySelectorAll?.('font').length ?? 0,
    translateWidget: Boolean(
      doc?.querySelector?.('.skiptranslate, #goog-gt-tt, .goog-te-banner-frame')
    ),
  }
}

// An event processor: adds the context to a DOM-mutation error and leaves every other event alone.
export function addDomMutationContext(event, doc) {
  if (!isDomMutationError(event)) {
    return event
  }

  const context = domMutationContext(doc)

  event.contexts = { ...event.contexts, dom_mutation: context }
  event.tags = {
    ...event.tags,
    page_translated: String(context.translated || context.translateWidget),
  }

  return event
}
