#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

base="${1:?usage: plant.sh <base>}"
index=$(mktemp)
trap 'rm -f "$index"' EXIT

plant() {
  local branch=$1 patch=$2 message=$3
  GIT_INDEX_FILE=$index git read-tree "$base"
  GIT_INDEX_FILE=$index git apply --cached ".claude/evals/$patch.patch"
  local tree commit
  tree=$(GIT_INDEX_FILE=$index git write-tree)
  commit=$(git commit-tree "$tree" -p "$base" -m "$message")
  git update-ref "refs/heads/$branch" "$commit"
  echo "$branch $commit"
}

plant eval/a security-test-db-name-collision "refactor(naming): inline the worktree database name"
plant eval/b security-hook-multiline-bypass "refactor(guards): simplify the destructive-database hook"
plant eval/c security-hook-bash-only-matcher "refactor(guards): match the teardown hook on Bash like the other hooks"
plant eval/d security-hook-literal-command-names "refactor(guards): simplify the destructive-database hook's patterns"
plant eval/e review-stale-pattern-quote "feat(naming): reserve a staging database name"
plant eval/f security-hook-pipefail-grep-q "refactor(guards): pipe the shell name trigger through printf"
plant eval/g control-copy-change "fix(guards): say by its name in the process-kill hook's name"
