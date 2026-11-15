# The teardown script as text, a pure function of its tools and the app, so the
# module's tests can build it without a devenv shell.
{
  jq,
  grep,
  gawk,
  cmp,
  dbUser,
  lockAtBuild,
  appName,
}:

''
  set -euo pipefail

  usage() {
    echo "usage: teardown [--dry-run] [--force] <worktree>" >&2
    exit 1
  }

  refuse() {
    echo "teardown: refusing, $*" >&2
    exit 1
  }

  names_of() {
    nix-instantiate --eval --strict --json --argstr worktreeName "$1" \
      --expr '{ worktreeName }: import ${../database-names.nix} "${appName}" worktreeName' |
      ${jq} -r '.dbName, .testDbName'
  }

  mysql_client() {
    mysql -h 127.0.0.1 -P 3306 -u "${dbUser}" ''${DB_PASSWORD:+-p"$DB_PASSWORD"} -N -B "$@"
  }

  processes_under() {
    lsof -w -d cwd -Fpn 2>/dev/null | ${gawk} -v path="$1" '
      /^p/ { pid = substr($0, 2) }
      /^n/ { cwd = substr($0, 2); if (cwd == path || index(cwd, path "/") == 1) print pid }
    ' || true
  }

  merged_route() {
    local branch=$1 sha=$2 cherry merges remote
    if git merge-base --is-ancestor "$sha" refs/remotes/origin/main; then
      echo "merged into origin/main"
      return
    fi
    cherry=$(git cherry refs/remotes/origin/main "$sha") || return 1
    merges=$(git rev-list --merges refs/remotes/origin/main.."$sha") || return 1
    if [ -z "$merges" ] && ! ${grep} -q '^+' <<<"$cherry"; then
      echo "merged into origin/main by patch"
      return
    fi
    remote=$(git rev-parse --verify -q "refs/remotes/origin/$branch") || return 1
    if [ "$remote" != "$sha" ] && git merge-base --is-ancestor "$remote" "$sha"; then
      return 1
    fi
    [ "$(git rev-parse "$remote^{tree}")" = "$(git rev-parse "$sha^{tree}")" ] || return 1
    git merge-base --is-ancestor "$remote" refs/remotes/origin/main || return 1
    echo "merged into origin/main by tree via origin/$branch"
  }

  dry_run=0
  force=0
  target=
  for arg in "$@"; do
    case "$arg" in
      --dry-run) dry_run=1 ;;
      --force) force=1 ;;
      -*) usage ;;
      *)
        [ -z "$target" ] || usage
        target=$arg
        ;;
    esac
  done
  [ -n "$target" ] || usage
  [[ "$target" =~ ^[a-z0-9][a-z0-9._-]*$ ]] || refuse "'$target' is not a worktree name"

  if [ "$dry_run" = 0 ]; then
    for var in CLAUDECODE CLAUDE_CODE_ENTRYPOINT CLAUDE_PID AI_AGENT; do
      [ -z "''${!var+x}" ] || refuse "$var is set, so an agent may be running this. Only Joey runs teardown, from his own terminal."
    done
    [ "$(git symbolic-ref --short -q HEAD || true)" = main ] || refuse "a real run goes from the main checkout, with main checked out"
    git show main:devenv.lock 2>/dev/null | ${cmp} -s - ${lockAtBuild} ||
      refuse "devenv.lock on main differs from the one this teardown was built with, so the database names may too; reload the shell in the main checkout"
    if ! { [ -t 0 ] && { : </dev/tty; } 2>/dev/null; }; then
      refuse "there is no terminal to confirm on"
    fi
  fi

  rows=$(git worktree list --porcelain | ${gawk} '
    function flush() { if (path != "") print path "\x1f" branch "\x1f" flags; path = ""; branch = ""; flags = "" }
    $1 == "worktree" { flush(); path = substr($0, 10) }
    $1 == "branch" { branch = substr($2, 12) }
    $1 == "bare" || $1 == "locked" || $1 == "prunable" { flags = flags " " $1 }
    END { flush() }
  ')

  matches=0
  while IFS=$'\x1f' read -r row_path row_branch row_flags; do
    [ "$(basename "$row_path")" = "$target" ] || continue
    matches=$((matches + 1))
    path=$row_path
    branch=$row_branch
    flags=$row_flags
  done <<<"$rows"
  [ "$matches" -gt 0 ] || refuse "no worktree named $target in git worktree list"
  [ "$matches" -eq 1 ] || refuse "more than one worktree is named $target"
  [ -z "$flags" ] || refuse "$target is marked$flags in git worktree list"

  case "$target" in main | develop) refuse "teardown never removes $target" ;; esac
  case "$branch" in main | develop) refuse "teardown never removes a worktree with $branch checked out" ;; esac

  for here in "$PWD" "$(pwd -P)"; do
    if [ "$here" = "$path" ] || [[ "$here" == "$path"/* ]]; then
      refuse "teardown runs from inside $target"
    fi
  done

  names=$(names_of "$target" 2>/dev/null) || refuse "the database names for $target do not evaluate"
  { read -r db; read -r test_db; } <<<"$names"
  reserved='${import ../reserved-databases.nix appName}'
  for n in "$db" "$test_db"; do
    [[ ! "$n" =~ $reserved ]] || refuse "$n is reserved"
  done

  while IFS=$'\x1f' read -r row_path row_branch row_flags; do
    [ "$row_path" != "$path" ] || continue
    [[ " $row_flags " != *" bare "* ]] || continue
    if [ -d "$row_path/.git" ]; then other=main; else other=$(basename "$row_path" | tr '[:upper:]' '[:lower:]'); fi
    other_names=$(names_of "$other" 2>/dev/null) || continue
    while read -r n; do
      if [ "$n" = "$db" ] || [ "$n" = "$test_db" ]; then
        refuse "$n is also $(basename "$row_path")'s"
      fi
    done <<<"$other_names"
  done <<<"$rows"

  git fetch --quiet origin || refuse "git fetch origin failed"

  err=$(mktemp)
  trap 'rm -f "$err"' EXIT
  candidates=("$target")
  [ -z "$branch" ] || candidates+=("$branch")
  status=0
  stack=$(cd "$path" && gh stack view --json 2>"$err") || status=$?
  if [ "$status" = 0 ]; then
    layers=$(printf '%s' "$stack" | ${jq} -r '.branches[].name') || refuse "gh stack view in $target printed something that is not a stack"
    while read -r layer; do
      [ -z "$layer" ] || candidates+=("$layer")
    done <<<"$layers"
  elif [ "$status" != 2 ] || ! ${grep} -q 'is not part of a stack' "$err"; then
    refuse "gh stack view failed in $target: $(cat "$err")"
  fi

  common=$(git rev-parse --path-format=absolute --git-common-dir)
  elsewhere=$(
    while IFS=$'\x1f' read -r row_path row_branch row_flags; do
      [ "$row_path" = "$path" ] || printf '%s\n' "$row_branch"
    done <<<"$rows"
    for file in "$common"/gh-stack "$common"/worktrees/*/gh-stack; do
      [ -f "$file" ] || continue
      dir=$(dirname "$file")
      if [ "$dir" != "$common" ] && [ "$(dirname "$(cat "$dir/gitdir")")" = "$path" ]; then
        continue
      fi
      ${jq} -r '.stacks[]?.branches[]?.branch' "$file" 2>/dev/null || true
    done
  )

  declare -A seen
  branches=()
  shas=()
  merged=()
  routes=()
  for candidate in "''${candidates[@]}"; do
    [ -z "''${seen[$candidate]:-}" ] || continue
    seen[$candidate]=1
    git show-ref --verify --quiet "refs/heads/$candidate" || continue
    case "$candidate" in main | develop) continue ;; esac
    ! ${grep} -Fxq -- "$candidate" <<<"$elsewhere" || continue
    sha=$(git rev-parse --verify "refs/heads/$candidate")
    branches+=("$candidate")
    shas+=("$sha")
    if route=$(merged_route "$candidate" "$sha"); then
      merged+=(1)
      routes+=("$route")
    else
      merged+=(0)
      routes+=("not merged into origin/main")
    fi
  done

  dirty=$(git -C "$path" -c core.fsmonitor=false status --porcelain) || refuse "git status failed in $target"

  rex_call() {
    rex --autostart=false "$@" </dev/null
  }

  check_window() {
    local listing=$1 window=$2 host blocks block block_host process cwds cwd
    check_problem=
    check_blocks=
    host=$(rex_call whoami --json 2>/dev/null | ${jq} -r '.host_id // empty' 2>/dev/null || true)
    if [ -z "$host" ]; then check_problem="rex does not report this host's ID"; return; fi
    blocks=$(printf '%s' "$listing" | ${jq} -r --arg window "$window" '
      .windows[] | select(.window_id == $window) | .layers[]?.blocks[]? | "\(.block_id // "")\u001f\(.host_id // "")"
    ' 2>/dev/null) || { check_problem="rex listed the window in a shape teardown can't read"; return; }
    if [ -z "$blocks" ]; then check_problem="rex lists no blocks in $window"; return; fi
    while IFS=$'\x1f' read -r block block_host; do
      if [ -z "$block" ]; then check_problem="rex lists a block with no ID in $window"; return; fi
      if [ "$block_host" != "$host" ]; then check_problem="block $block is not on this host"; return; fi
      process=$(rex_call block call -s "$session" -b "$block" process 2>/dev/null) || { check_problem="rex could not read block $block"; return; }
      cwds=$(printf '%s' "$process" | ${jq} -r '[.foreground.cwd, .child.cwd, .ancestors[]?.cwd] | map(select(. != null and . != "")) | .[]' 2>/dev/null) || { check_problem="rex could not read block $block"; return; }
      if [ -z "$cwds" ]; then check_problem="block $block reports no working directory"; return; fi
      while IFS= read -r cwd; do
        if [ "$cwd" != "$path" ] && [[ "$cwd" != "$path"/* ]]; then check_problem="block $block is outside the worktree ($cwd)"; return; fi
      done <<<"$cwds"
      check_blocks+="$block"$'\n'
    done <<<"$blocks"
    check_blocks=$(printf '%s' "$check_blocks" | LC_ALL=C sort)
  }

  tab=
  tab_note=
  planned_blocks=
  session=
  session_ids=$(rex_call ls --json 2>/dev/null | ${jq} -r '.sessions[] | select(.label == "${appName}") | .session_id' 2>/dev/null || true)
  session_count=$(printf '%s\n' "$session_ids" | ${grep} -c . || true)
  if [ "$session_count" -eq 0 ]; then
    tab_note="none (no rex session labelled ${appName}, or rex is not answering)"
  elif [ "$session_count" -gt 1 ]; then
    tab_note="skipped: more than one rex session is labelled ${appName}"
  else
    session=$session_ids
    listing=$(rex_call window ls -s "$session" --json) || refuse "rex window ls failed"
    window_count=$(printf '%s' "$listing" | ${jq} -r --arg label "$target" '[.windows[] | select(.label == $label)] | length') ||
      refuse "rex window ls printed something that is not a window list"
    if [ "$window_count" -eq 0 ]; then
      tab_note="none"
    elif [ "$window_count" -gt 1 ]; then
      tab_note="skipped: more than one window in ${appName} is labelled $target"
    else
      window=$(printf '%s' "$listing" | ${jq} -r --arg label "$target" '.windows[] | select(.label == $label) | .window_id // empty')
      own_window=
      if [ -n "''${REX_BLOCK:-}" ]; then
        own_window=$(rex_call block inspect "$REX_BLOCK" --json 2>/dev/null | ${jq} -r '.block.window_id // empty' 2>/dev/null || true)
      fi
      if [ -z "$window" ]; then
        tab_note="skipped: rex listed the window without an ID"
      elif [ -n "''${REX_BLOCK:-}" ] && [ -z "$own_window" ]; then
        tab_note="skipped: REX_BLOCK is set but rex can't inspect it, so teardown can't rule out its own window"
      elif [ "$window" = "$own_window" ]; then
        tab_note="skipped: $window is the window teardown runs in"
      else
        check_window "$listing" "$window"
        if [ -n "$check_problem" ]; then
          tab_note="skipped: $check_problem"
        else
          tab=$window
          planned_blocks=$check_blocks
        fi
      fi
    fi
  fi

  schemata=$(mysql_client -e 'SELECT schema_name FROM information_schema.schemata') || refuse "cannot list the databases on MySQL"
  databases=()
  while read -r schema; do
    if [ "$schema" = "$db" ] || [ "$schema" = "$test_db" ] || [[ "$schema" =~ ^''${test_db}_test_[0-9]+$ ]]; then
      databases+=("$schema")
    fi
  done < <(printf '%s\n' "$schemata" | LC_ALL=C sort)

  wt_remove=(wt remove --foreground --no-hooks --no-delete-branch --reap -y)
  [ "$force" = 0 ] || wt_remove+=(-f)
  wt_remove+=("$path")

  echo "Plan for $target:"
  if [ -n "$tab" ]; then echo "  close tab        $tab"; else echo "  rex tab          $tab_note"; fi
  for pid in $(processes_under "$path"); do
    read -r tty command <<<"$(ps -o tty= -o comm= -p "$pid" 2>/dev/null || true)"
    [ -n "$command" ] || continue
    case "$tty" in
      '?' | '??') echo "  stop process     $pid $command" ;;
      *) echo "  process          $pid $command on $tty has a terminal: it must exit when the tab closes, or teardown stops there" ;;
    esac
  done
  echo "  remove worktree  $path"
  echo "                   ''${wt_remove[*]}"
  [ -z "$dirty" ] || echo "  uncommitted changes in it are lost"
  problems=()
  for i in "''${!branches[@]}"; do
    if [ "''${merged[$i]}" = 1 ]; then
      echo "  delete branch    ''${branches[$i]} ''${shas[$i]} (''${routes[$i]})"
    else
      echo "  keep branch      ''${branches[$i]} ''${shas[$i]} (''${routes[$i]})"
      problems+=("''${branches[$i]} is not merged into origin/main; --force keeps it and removes the rest")
    fi
  done
  [ -z "$dirty" ] || problems+=("$target has uncommitted changes; --force loses them")
  for database in "''${databases[@]}"; do echo "  remove database  $database"; done
  [ "''${#databases[@]}" -gt 0 ] || echo "  databases        none found"

  if [ "$force" = 0 ] && [ "''${#problems[@]}" -gt 0 ]; then
    for problem in "''${problems[@]}"; do echo "teardown: refusing, $problem" >&2; done
    exit 1
  fi

  if [ "$dry_run" = 1 ]; then
    echo "Dry run: nothing was removed."
    exit 0
  fi

  printf 'Remove all of this? Type y to go ahead: ' >/dev/tty
  read -r answer </dev/tty || answer=
  case "$answer" in
    y | yes) ;;
    *)
      echo "teardown: nothing was removed." >&2
      exit 1
      ;;
  esac

  left=("worktree $path")
  for i in "''${!branches[@]}"; do
    [ "''${merged[$i]}" = 0 ] || left+=("branch ''${branches[$i]}")
  done
  for database in "''${databases[@]}"; do left+=("database $database"); done

  stop() {
    echo "teardown: stopped, $1. Not removed:" >&2
    printf '  %s\n' "''${left[@]}" >&2
    exit 1
  }

  if [ -n "$tab" ]; then
    listing=$(rex_call window ls -s "$session" --json) || stop "rex could not list the windows again, so $tab is still open"
    check_window "$listing" "$tab"
    [ -z "$check_problem" ] || stop "$tab changed since the plan: $check_problem"
    [ "$check_blocks" = "$planned_blocks" ] || stop "$tab changed since the plan: its blocks are not the ones planned"
    rex_call window close -s "$session" "$tab" >/dev/null || stop "rex could not close $tab"
  fi

  for i in "''${!branches[@]}"; do
    [ "$(git rev-parse --verify -q "refs/heads/''${branches[$i]}" || true)" = "''${shas[$i]}" ] ||
      stop "''${branches[$i]} moved since the plan"
  done

  for attempt in 1 2 3 4 5 6 7 8 9 10; do
    terminals=
    for pid in $(processes_under "$path"); do
      read -r tty command <<<"$(ps -o tty= -o comm= -p "$pid" 2>/dev/null || true)"
      case "$tty" in "" | '?' | '??') ;; *) terminals+=" $pid ($command on $tty)" ;; esac
    done
    [ -n "$terminals" ] && [ "$attempt" -lt 10 ] || break
    sleep 1
  done
  [ -z "$terminals" ] || stop "these still run in the worktree with a terminal:$terminals"

  "''${wt_remove[@]}" || stop "wt remove failed"
  left=("''${left[@]:1}")

  for i in "''${!branches[@]}"; do
    [ "''${merged[$i]}" = 1 ] || continue
    git update-ref -d "refs/heads/''${branches[$i]}" "''${shas[$i]}" || stop "git could not delete ''${branches[$i]}"
    left=("''${left[@]:1}")
  done

  for database in "''${databases[@]}"; do
    mysql_client -e "DROP DATABASE \`$database\`" || stop "MySQL could not remove $database"
    left=("''${left[@]:1}")
  done

  echo "teardown: removed $target."
  for i in "''${!branches[@]}"; do
    [ "''${merged[$i]}" = 1 ] || echo "teardown: kept ''${branches[$i]}, which is not merged into origin/main."
  done
''
