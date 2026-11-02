{
  grep,
  git,
  sed,
  commitExample,
}:

let
  scratch = "\\((([RDVS][0-9]{1,3})|(AC[0-9]{1,2}))[,)]|worked example [0-9]";
in
{
  commitMsgFormat = ''
    subject=$(head -n1 "$1")
    conventional='^((\[[A-Z]+-[0-9]+(\.[0-9]+)*\])+ )?(feat|fix|docs|deps|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9._/-]+\))?!?: .+'
    tooling='^(Merge|Revert|fixup!|squash!|amend!) '
    if ${sed} '/^# -* >8 -*$/,$d' "$1" | ${grep} -v '^#' | ${grep} -Eq -e '${scratch}'; then
      echo "✗ the commit message cites a team-scratch id (a ruling, AC or decision number, or a worked example). Say what changed instead." >&2
      exit 1
    fi
    if printf '%s' "$subject" | ${grep} -Eq -e "$conventional" -e "$tooling"; then
      exit 0
    fi
    {
      echo "✗ commit subject does not match the convention:"
      echo "    ${commitExample}"
      echo "    fix(ui): what changed"
      echo "  got: $subject"
    } >&2
    exit 1
  '';

  scratchIds = ''
    if ${git} diff --cached --unified=0 -- 'tests/' '*CONTEXT.md' | ${grep} -E '^\+[^+]' | ${grep} -Eq -e '${scratch}'; then
      echo "✗ an added line in tests/ or a CONTEXT.md cites a team-scratch id (a ruling, AC or decision number, or a worked example). Name the behaviour instead." >&2
      exit 1
    fi
  '';
}
