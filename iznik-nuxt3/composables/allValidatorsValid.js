/**
 * Resolve true only if every present validator reports valid.
 *
 * Each validator is an object exposing an async `validate()` that runs its rules
 * — which surfaces the inline error message and red invalid border — and
 * resolves to a boolean. This is used to gate form submission: calling
 * validate() makes empty/untouched fields show their error, and we only proceed
 * once every present validator passes.
 *
 * Returns false when there are no validators, so a form whose validators aren't
 * wired up never silently submits.
 *
 * @param {Array<{ validate: () => Promise<boolean> }|null|undefined>} validators
 * @returns {Promise<boolean>}
 */
export async function allValidatorsValid(validators) {
  const present = (validators || []).filter(Boolean)

  if (!present.length) {
    return false
  }

  const results = await Promise.all(present.map((v) => v.validate()))
  return results.every(Boolean)
}
