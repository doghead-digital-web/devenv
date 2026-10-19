{ grep, jq }:

import ./word-guard.nix {
  inherit grep jq;
  word = "(^|[^a-z0-9_-]|(^|[[:space:]])-[a-z0-9]*)teardown([^a-z0-9_-]|$)";
  allowed = "(direnv exec \\. )?teardown --dry-run [a-z0-9][a-z0-9._-]*";
  message = "Blocked: teardown removes a worktree, its branches and its databases, and only Joey runs it. Give Joey the exact command at hand-off, teardown <worktree>, run from the main checkout. The one form an agent may run is a single-line teardown --dry-run <worktree>, or direnv exec . teardown --dry-run <worktree>.";
}
