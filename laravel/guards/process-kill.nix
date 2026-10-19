{ grep, jq }:

import ./word-guard.nix {
  inherit grep jq;
  word = "(^|[^a-z0-9_-]|(^|[[:space:]])-[a-z0-9]*)(pkill|killall|kill-port|fkill|fuser|kill)([^a-z0-9_-]|$)";
  allowed = "kill( -[A-Za-z0-9]+| -s [A-Za-z0-9]+)?( [1-9][0-9]*)+";
  message = "Blocked: stop a process by its pid after lsof shows its cwd is your worktree (kill <pid>), or run devenv processes down from your worktree root. Use TaskStop to stop a background task of your own. For timeout use -s 9.";
}
