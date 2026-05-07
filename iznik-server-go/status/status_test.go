package status

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/assert"
)

// setupGitDir creates a temporary directory tree that looks like a git working
// tree and returns its path.  The caller is responsible for cleanup via the
// returned teardown function.
func setupGitDir(t *testing.T) (dir string, teardown func()) {
	t.Helper()
	dir = t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, ".git", "refs", "heads"), 0755); err != nil {
		t.Fatalf("setupGitDir: %v", err)
	}
	return dir, func() {}
}

func TestReadGitHead_DetachedHEAD(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	sha := "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2"
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(sha+"\n"), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, sha, got)
}

func TestReadGitHead_SymbolicRef(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	sha := "deadbeef12345678deadbeef12345678deadbeef"
	headContent := "ref: refs/heads/master\n"
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(headContent), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}
	refFile := filepath.Join(dir, ".git", "refs", "heads", "master")
	if err := os.WriteFile(refFile, []byte(sha+"\n"), 0644); err != nil {
		t.Fatalf("write ref: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, sha, got)
}

func TestReadGitHead_SymbolicRefMissingRefFile(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	headContent := "ref: refs/heads/nonexistent\n"
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(headContent), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, "", got, "missing ref file must return empty string")
}

func TestReadGitHead_NoGitDirectory(t *testing.T) {
	dir := t.TempDir()
	got := readGitHead(dir)
	assert.Equal(t, "", got, "no .git directory must return empty string")
}

func TestReadGitHead_EmptyHEADFile(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(""), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, "", got, "empty HEAD file must return empty string")
}

func TestReadGitHead_ShortHEADContentNotSymbolicRef(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	// 39 chars: not 40, not a "ref: ..." line — treated as unknown format.
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte("abc123def456abc123def456abc123def456abc"), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, "", got, "unrecognised HEAD format must return empty string")
}

func TestReadGitHead_DetachedHEADNoTrailingNewline(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	sha := "1111111111111111111111111111111111111111"
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(sha), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, sha, got, "detached HEAD without trailing newline must still work")
}

func TestReadGitHead_EmbeddedSpaceInSHA(t *testing.T) {
	dir, cleanup := setupGitDir(t)
	defer cleanup()

	// A string that is exactly 40 chars after TrimSpace but contains an
	// internal space is not a valid bare SHA.  The implementation checks
	// !strings.Contains(head, " "), so it falls through to the symbolic-ref
	// branch and returns "" (no "ref: " prefix either).
	// "aaaaaaaaaaaaaaaaaa bbbbbbbbbbbbbbbbbbbbb" = 18 + 1 + 21 = 40 chars.
	bad := "aaaaaaaaaaaaaaaaaa bbbbbbbbbbbbbbbbbbbbb\n"
	if err := os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte(bad), 0644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	got := readGitHead(dir)
	assert.Equal(t, "", got, "SHA with embedded space must not be accepted as detached HEAD")
}
