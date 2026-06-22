import turfdistance from 'turf-distance'
import turfpoint from 'turf-point'

export function milesAway(flat, flng, tlat, tlng) {
  let ret = null

  if ((flat || flng) && (tlat || tlng)) {
    ret = turfdistance(
      turfpoint([flng, flat]),
      turfpoint([tlng, tlat]),
      'miles'
    )

    ret = ret > 2 ? Math.round(ret) : Math.round(ret * 10) / 10
  }

  return ret
}
