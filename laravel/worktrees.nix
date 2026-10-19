{
  pkgs,
  lib,
  config,
  ...
}:

let
  ws = config.doghead.laravel.workspace;

  tools = {
    jq = lib.getExe pkgs.jq;
    grep = lib.getExe pkgs.gnugrep;
    gawk = lib.getExe pkgs.gawk;
    inherit (ws) appName;
  };

  # The database names come from this module, pinned by the app's devenv.lock,
  # so a teardown built from another lock could name other databases.
  lockFile = "${ws.worktreeRoot}/devenv.lock";
  lockAtBuild = pkgs.writeText "devenv.lock" (
    if builtins.pathExists lockFile then builtins.readFile lockFile else ""
  );
in
{
  scripts.feature.description = "Open a worktree for a feature in a new herdr tab: its feature lead, lazygit and a shell for devenv up";
  scripts.feature.exec = import ./scripts/feature.nix tools;

  scripts.teardown.description = "Undo what feature set up for a worktree: its herdr tab, processes, worktree, merged branches and databases";
  scripts.teardown.exec = import ./scripts/teardown.nix (
    tools
    // {
      cmp = lib.getExe' pkgs.diffutils "cmp";
      inherit (ws) dbUser;
      inherit lockAtBuild;
    }
  );
}
