import { useRuntimeConfig } from '#imports'

// Experiment: a member site with no communities.
//
// GROUPLESS=1 removes group identity from what a member sees: no "on Cambridge Freegle"
// on posts, no community picker when reporting, one set of email settings, one set of
// rules. Groups still exist in the database as routing labels (TrashNothing addresses
// posts and members by community short name), they are just never shown.
//
// Off unless switched on. This is a thought experiment, not the shipped behaviour.
export function useGroupless() {
  const config = useRuntimeConfig()
  const v = config?.public?.GROUPLESS
  return v === true || v === 1 || v === '1' || v === 'true'
}
