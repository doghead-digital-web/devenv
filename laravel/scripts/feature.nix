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
      echo "feature: a brief can't start with !, / or -, which Claude reads as a shell or slash command and herdr as an option" >&2
      exit 1
      ;;
  esac

  if printf '%s' "$brief" | LC_ALL=C ${grep} -qz '[[:cntrl:]]'; then
    echo "feature: a brief can't contain a newline, a carriage return or any other control character; herdr agent prompt would send it literally" >&2
    exit 1
  fi

  if [ "''${HERDR_ENV:-}" != 1 ]; then
    echo "feature: run this from a herdr pane" >&2
    exit 1
  fi

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

  workspace_ids=$(herdr workspace list | ${jq} -r --arg label "${appName}" '.result.workspaces[] | select(.label == $label) | .workspace_id')
  workspace_count=$(printf '%s\n' "$workspace_ids" | ${grep} -c . || true)
  if [ "$workspace_count" -eq 0 ]; then
    echo "feature: no herdr workspace is labelled ${appName}" >&2
    exit 1
  elif [ "$workspace_count" -gt 1 ]; then
    echo "feature: more than one herdr workspace is labelled ${appName}" >&2
    exit 1
  fi
  workspace=$workspace_ids

  wt switch --create "$name" --no-cd --yes
  path=$(git worktree list --porcelain | ${gawk} -v branch="refs/heads/$name" '$1 == "worktree" { p = $2 } $1 == "branch" && $2 == branch { print p }')
  worktree=$(basename "$path")

  direnv allow "$path"
  echo "feature: building the devenv shell for $worktree"
  direnv exec "$path" true

  tab=$(herdr tab create --workspace "$workspace" --cwd "$path" --label "$worktree" --focus)
  agent_pane=$(printf '%s' "$tab" | ${jq} -r '.result.root_pane.pane_id')
  lazygit_pane=$(herdr pane split "$agent_pane" --direction right --cwd "$path" --no-focus | ${jq} -r '.result.pane.pane_id')
  server_pane=$(herdr pane split "$lazygit_pane" --direction down --cwd "$path" --no-focus | ${jq} -r '.result.pane.pane_id')

  for pane in "$agent_pane" "$lazygit_pane" "$server_pane"; do
    herdr pane wait-output "$pane" --match 'direnv: export' --source recent --timeout 120000 >/dev/null
  done

  herdr pane run "$lazygit_pane" lg >/dev/null

  if [ -z "$brief" ]; then
    brief="You are the feature lead for $worktree, running in its own worktree at $path. The app is not running; it answers at https://$worktree.${appName}.test once devenv up runs. Find its ticket in Linear and pick up the loop where the ticket stands."
  fi
  agent_name=$(printf 'lead-%s' "$worktree" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-' | cut -c1-32)
  herdr agent start "$agent_name" --kind claude --pane "$agent_pane" --timeout 120000 -- \
    --agent feature-lead -n "$worktree" --remote-control >/dev/null
  herdr agent prompt "$agent_name" "$brief" >/dev/null

  echo "feature: $worktree is open in herdr as agent $agent_name"
  echo "feature: the app isn't running; run devenv up in the bottom-right pane when the ticket needs the browser"
''
