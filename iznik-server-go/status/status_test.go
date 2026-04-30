package status

import (
	"os"
	"path/filepath"
	"testing"
)

func TestReadGitHead_NoDirectory(t *testing.T) {
	got := readGitHead("/tmp/nonexistent-dir-for-test-12345")
	if got != "" {
		t.Errorf("nonexistent dir: expected empty string, got %q", got)
	}
}

func TestReadGitHead_NoGitDir(t *testing.T) {
	dir := t.TempDir()
	got := readGitHead(dir)
	if got != "" {
		t.Errorf("no .git dir: expected empty string, got %q", got)
	}
}

func TestReadGitHead_DetachedHEAD(t *testing.T) {
	dir := t.TempDir()
	gitDir := filepath.Join(dir, ".git")
	if err := os.MkdirAll(gitDir, 0755); err != nil {
		t.Fatal(err)
	}
	sha := "abc123def456abc123def456abc123def456abc1"
	if err := os.WriteFile(filepath.Join(gitDir, "HEAD"), []byte(sha+"\n"), 0644); err != nil {
		t.Fatal(err)
	}
	got := readGitHead(dir)
	if got != sha {
		t.Errorf("detached HEAD: got %q, want %q", got, sha)
	}
}

func TestReadGitHead_SymbolicRef(t *testing.T) {
	dir := t.TempDir()
	gitDir := filepath.Join(dir, ".git")
	refsDir := filepath.Join(gitDir, "refs", "heads")
	if err := os.MkdirAll(refsDir, 0755); err != nil {
		t.Fatal(err)
	}
	sha := "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
	if err := os.WriteFile(filepath.Join(refsDir, "main"), []byte(sha+"\n"), 0644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(gitDir, "HEAD"), []byte("ref: refs/heads/main\n"), 0644); err != nil {
		t.Fatal(err)
	}
	got := readGitHead(dir)
	if got != sha {
		t.Errorf("symbolic ref: got %q, want %q", got, sha)
	}
}

func TestReadGitHead_SymbolicRef_MissingRefFile(t *testing.T) {
	dir := t.TempDir()
	gitDir := filepath.Join(dir, ".git")
	if err := os.MkdirAll(gitDir, 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(gitDir, "HEAD"), []byte("ref: refs/heads/missing-branch\n"), 0644); err != nil {
		t.Fatal(err)
	}
	got := readGitHead(dir)
	if got != "" {
		t.Errorf("missing ref file: expected empty string, got %q", got)
	}
}
