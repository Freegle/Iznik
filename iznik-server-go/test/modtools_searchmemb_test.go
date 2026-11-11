package test

// contains reports whether id is in ids. Other tests in the package use it.
func contains(ids []uint64, id uint64) bool {
	for _, v := range ids {
		if v == id {
			return true
		}
	}
	return false
}
