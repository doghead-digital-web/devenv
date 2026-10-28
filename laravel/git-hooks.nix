{
  pkgs,
  lib,
  config,
  ...
}:

let
  cfg = config.doghead.laravel;

  grep = lib.getExe pkgs.gnugrep;
  git = lib.getExe config.git-hooks.gitPackage;
  sed = lib.getExe pkgs.gnused;
  cmp = lib.getExe' pkgs.diffutils "cmp";
  install = lib.getExe' pkgs.coreutils "install";

  hookTypes = lib.remove "manual" config.git-hooks.installStages;

  # Every worktree commits through the one hooks folder of the bare repository.
  # devenv's own install writes hooks naming the worktree whose shell loaded
  # last; this shim finds the committing worktree and runs its own prek.
  hookShim =
    hookType:
    pkgs.writeText "prek-${hookType}-shim" ''
      #!/bin/sh
      # ID: 182c10f181da4464a3eec51b83331688

      HERE="$(cd "$(dirname "$0")" && pwd)"
      ROOT="$(git rev-parse --show-toplevel)"
      PREK="$ROOT/.devenv/profile/bin/prek"

      if [ ! -x "$PREK" ]; then
          PREK="prek"
      fi

      exec "$PREK" hook-impl --hook-dir "$HERE" --script-version 4 --hook-type=${hookType} --config="$ROOT/.pre-commit-config.yaml" -- "$@"
    '';

  installHookShim = hookType: ''
    if ! { ${cmp} -s ${hookShim hookType} "$hooks/${hookType}" && [ -x "$hooks/${hookType}" ]; }; then
      ${install} -m 755 ${hookShim hookType} "$hooks/${hookType}"
    fi
    rm -f "$hooks/${hookType}.legacy"
  '';

  commitHooks = import ./scripts/commit-hooks.nix {
    inherit grep git sed;
    inherit (cfg) commitExample;
  };
  commitMsgFormat = pkgs.writeShellScript "commit-msg-format" commitHooks.commitMsgFormat;
  scratchIds = pkgs.writeShellScript "scratch-ids" commitHooks.scratchIds;

  jsHooks = {
    vite-plus.vp-check = {
      enable = true;
      name = "vp check";
      entry = "pnpm exec vp check --fix --no-error-on-unmatched-pattern";
      files = "^(resources/|[^/]+\\.config\\.ts$)";
      language = "system";
    };
    prettier.prettier = {
      enable = true;
      name = "prettier";
      entry = "pnpm exec prettier --write --ignore-unknown";
      files = "^(resources/|[^/]+\\.config\\.ts$)";
      language = "system";
    };
  };
in
{
  options.doghead.laravel.jsHook = lib.mkOption {
    type = lib.types.str;
    readOnly = true;
    internal = true;
    description = "The name of the formatter's git hook, which ci-hooks skips because ci-lint covers it.";
  };

  config = {
    doghead.laravel.jsHook = lib.head (lib.attrNames jsHooks.${cfg.formatter});

    tasks."devenv:git-hooks:install".exec = lib.mkForce ''
      hooks=$(${git} rev-parse --path-format=absolute --git-path hooks) || exit 0
      mkdir -p "$hooks"
      ${lib.concatMapStrings installHookShim hookTypes}
    '';

    git-hooks.hooks = jsHooks.${cfg.formatter} // {
      pint = {
        enable = true;
        name = "pint";
        entry = "vendor/bin/pint";
        files = "\\.php$";
        excludes = [
          "\\.blade\\.php$"
          "^(bootstrap/cache|storage)/"
          "^(vendor|node_modules)/"
        ];
        language = "system";
      };
      rector = {
        enable = true;
        name = "rector";
        entry = "vendor/bin/rector process";
        files = "^((app|config|public|resources|routes|tests)/.*|bootstrap/(app|providers))\\.php$";
        language = "system";
      };
      arch = {
        enable = true;
        name = "arch";
        entry = "composer lint:arch";
        files = "\\.php$";
        language = "system";
        pass_filenames = false;
      };
      commit-msg-format = {
        enable = true;
        name = "commit message format";
        entry = "${commitMsgFormat}";
        language = "system";
        stages = [ "commit-msg" ];
      };
      scratch-ids = {
        enable = true;
        name = "no team-scratch ids";
        entry = "${scratchIds}";
        language = "system";
        pass_filenames = false;
        files = "(^tests/|CONTEXT\\.md$)";
      };
      no-commit-to-branch.enable = true;
      check-merge-conflicts.enable = true;
      ripsecrets.enable = true;
      nixfmt.enable = true;
      actionlint.enable = true;
      zizmor.enable = true;
      shellcheck = {
        enable = true;
        args = [ "--severity=warning" ];
        excludes = [ "^\\.envrc$" ];
      };
      pnpm-audit = {
        enable = true;
        name = "pnpm packages audit";
        entry = "pnpm audit";
        language = "system";
        pass_filenames = false;
        stages = [ "pre-push" ];
      };
      composer-audit = {
        enable = true;
        name = "composer packages audit";
        entry = "composer audit";
        language = "system";
        pass_filenames = false;
        stages = [ "pre-push" ];
      };
    };
  };
}
