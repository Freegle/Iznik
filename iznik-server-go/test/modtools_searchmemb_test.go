package test

import (

)

// The ModTools "find posts by member" search (GET /modtools/messages
// ?subaction=searchmemb) finds the matching members first and then their
// posts through the poster index, the way v1 did. The previous shape scanned
// the community's posts newest-first looking for one whose poster matched,
// which for a person with fewer posts than the page size - the usual case -
// had to read the whole community before it could stop, and on production's
// larger communities hit MAX_EXECUTION_TIME on every search and answered 500.
//
// The candidate query pins the memberships access path with FORCE INDEX on
// memberships_groupid_collection_emailfrequency, as the member search does,
// so these tests also fail loudly if that index is ever renamed.

func contains(ids []uint64, id uint64) bool {
	for _, v := range ids {
		if v == id {
			return true
		}
	}
	return false
}
