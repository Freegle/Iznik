// How the text of a job is shown. The job feed is not clean: a line break can arrive as a backslash
// and an n, or as <br>, a pound sign can arrive as two characters, and a place starts with a comma
// and a space. Every place a job appears (the tiles in the ad slots, the mosaic tile, and the card
// on the email job page) shows its text through these, so a job reads the same wherever a member
// sees it.

// A title or description as shown: line breaks made real, a pound sign put right, the ends
// trimmed and accents removed.
export function filterNonsense(val) {
  return val
    .replace(/\\n/g, '\n')
    .replace(/<br>/g, '\n')
    .replace(/Â£/g, '£')
    .trim()
    .normalize('NFD')
    .replace(/[\u0300-\u036F]/g, '')
}

// A place as shown: the comma and space the feed puts in front of it are dropped. An empty string
// when there is no place.
export function jobLocation(val) {
  if (val && val.indexOf(', ') === 0) {
    return val.substring(2)
  }

  return val || ''
}
