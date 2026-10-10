// Community page titles are worded the way people search: "Free stuff in Birmingham", not
// "Explore Birmingham Freegle". The trailing "Freegle" on a community's display name is dropped
// because the brand is added at the end.
export function exploreTitle(namedisplay) {
  const place = (namedisplay || '').replace(/\s*freegle\s*$/i, '').trim()

  return place ? 'Free stuff in ' + place + ' | Freegle' : 'Explore Freegle'
}
