# The feature script as text, a pure function of its tools and the app, so the
# module's tests can build it without a devenv shell.
{
  jq,
  grep,
  gawk,
  appName,
}:

''
  set -euo pipefail
  name=''${1:?usage: feature <worktree-name> [brief for the feature lead]}
  brief=''${2:-}

  if ! [[ "$name" =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
    echo "feature: a worktree name is lowercase letters, digits, '.', '_' and '-', starting with a letter or digit" >&2
    exit 1
  fi

  if [[ "$name" =~ (^|[-._])test([-._][0-9]+)?$ ]]; then
    echo "feature: a worktree named '$name' would get a dev database named like a test database; pick a name that doesn't end in test or test-<N>" >&2
    exit 1
  fi

  case "''${brief#"''${brief%%[![:space:]]*}"}" in
    '!'* | '/'* | '-'*)
      echo "feature: a brief can't start with !, / or -, which Claude reads as a shell command, a slash command or an option" >&2
      exit 1
      ;;
  esac

  if printf '%s' "$brief" | LC_ALL=C ${grep} -qz '[[:cntrl:]]'; then
    echo "feature: a brief can't contain a newline, a carriage return or any other control character; a brief is one line of text for Claude" >&2
    exit 1
  fi

  if [ -z "''${REX_SESSION:-}" ]; then
    echo "feature: run this from a rex pane" >&2
    exit 1
  fi

  case "''${REX_SERVER:-}" in
    "" | unix://*) ;;
    *)
      echo "feature: REX_SERVER points at a server that is not on this machine, where the lead would start in another home directory; run this from a pane of the local rex server" >&2
      exit 1
      ;;
  esac

  names_of() {
    nix-instantiate --eval --strict --json --argstr worktreeName "$1" \
      --expr '{ worktreeName }: import ${../database-names.nix} "${appName}" worktreeName' |
      ${jq} -r '.dbName, .testDbName'
  }

  new_names=$(names_of "$name") || {
    echo "feature: the database names for '$name' do not evaluate" >&2
    exit 1
  }
  { read -r new_db; read -r new_test_db; } <<<"$new_names"

  while IFS= read -r row_path; do
    [ -n "$row_path" ] || continue
    if [ -d "$row_path/.git" ]; then other=main; else other=$(basename "$row_path" | tr '[:upper:]' '[:lower:]'); fi
    [ "$other" != "$name" ] || continue
    other_names=$(names_of "$other" 2>/dev/null) || continue
    { read -r other_db; read -r other_test_db; } <<<"$other_names"
    if [ "$other_db" = "$new_db" ] || [ "$other_test_db" = "$new_test_db" ]; then
      echo "feature: '$name' would get the same database as '$other', the worktree at $row_path; pick a name that doesn't collide once '.', '-' and '_' are folded together" >&2
      exit 1
    fi
  done < <(git worktree list --porcelain | ${gawk} '$1 == "worktree" { print $2 }')

  session_ids=$(rex ls --json | ${jq} -r --arg label "${appName}" '.sessions[] | select(.label == $label) | .session_id')
  session_count=$(printf '%s\n' "$session_ids" | ${grep} -c . || true)
  if [ "$session_count" -eq 0 ]; then
    echo "feature: no rex session is labelled ${appName}" >&2
    exit 1
  elif [ "$session_count" -gt 1 ]; then
    echo "feature: more than one rex session is labelled ${appName}" >&2
    exit 1
  fi
  session=$session_ids

  wt switch --create "$name" --no-cd --yes
  path=$(git worktree list --porcelain | ${gawk} -v branch="refs/heads/$name" '$1 == "worktree" { p = $2 } $1 == "branch" && $2 == branch { print p }')
  if [ -z "$path" ]; then
    echo "feature: wt left no worktree on branch $name; not opening a window" >&2
    exit 1
  fi
  if [ ! -d "$path" ]; then
    echo "feature: the worktree path $path is not a directory; not opening a window" >&2
    exit 1
  fi
  worktree=$(basename "$path")

  direnv allow "$path"
  echo "feature: building the devenv shell for $worktree"
  direnv exec "$path" true

  if [ -z "$brief" ]; then
    brief="You are the feature lead for $worktree, running in its own worktree at $path. The app is not running; it answers at https://$worktree.${appName}.test once devenv up runs. Find its ticket in Linear and pick up the loop where the ticket stands."
  fi

  window=$(rex window new -s "$session" "$worktree" -c "$path" --json -- \
    direnv exec "$path" claude --remote-control --agent feature-lead -n "$worktree" "$brief")
  lead_block=$(printf '%s' "$window" | ${jq} -r '.block_ids[0] // empty')
  if [[ "$lead_block" != block:* ]]; then
    echo "feature: rex opened the window but returned no block ID for the lead" >&2
    exit 1
  fi
  rex block rename -s "$session" "$lead_block" "lead-$worktree"
  lazygit_block=$(rex split -s "$session" -b "$lead_block" --split=right -c "$path" --focus=false --json -- \
    direnv exec "$path" lazygit | ${jq} -r '.block_ids[0] // empty')
  if [[ "$lazygit_block" != block:* ]]; then
    echo "feature: rex opened the lazygit block but returned no block ID for it" >&2
    exit 1
  fi
  rex split -s "$session" -b "$lazygit_block" --split=below -c "$path" --focus=false --json >/dev/null

  echo "feature: $worktree is open in rex; its feature lead is block $lead_block, labelled lead-$worktree"
  echo "feature: the app isn't running; run devenv up in the bottom-right pane when the ticket needs the browser"
''
