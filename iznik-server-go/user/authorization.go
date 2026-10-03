package user

import (
	"github.com/freegle/iznik-server-go/auth"
)

// IsAdminOrSupport checks if the user has Admin or Support system role.
// Delegates to auth.IsAdminOrSupport to avoid circular imports.
func IsAdminOrSupport(myid uint64) bool {
	return auth.IsAdminOrSupport(myid)
}

// IsModOfGroup is a deprecated alias for auth.IsModerator, kept only so that
// packages outside user/ still mid-migration keep compiling. The group
// argument is ignored: moderators are a national pool, never scoped to a
// community. Callers should move to auth.IsModerator(myid) directly.
func IsModOfGroup(myid uint64, groupid uint64) bool {
	return auth.IsModerator(myid)
}

// IsModOfAnyGroup is a deprecated alias for auth.IsModerator, kept only so
// that packages outside user/ still mid-migration keep compiling. Callers
// should move to auth.IsModerator(myid) directly.
func IsModOfAnyGroup(myid uint64) bool {
	return auth.IsModerator(myid)
}

// IsModOfUser is a deprecated alias for auth.IsModerator, kept only so that
// packages outside user/ still mid-migration keep compiling. The target
// argument is ignored: there is no such thing as a group shared between
// caller and target any more, and moderators can act on any member.
// Callers should move to auth.IsModerator(myid) directly.
func IsModOfUser(myid, targetid uint64) bool {
	return auth.IsModerator(myid)
}
