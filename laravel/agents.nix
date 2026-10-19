{
  pkgs,
  lib,
  config,
  ...
}:

let
  cfg = config.doghead.laravel;
  ws = cfg.workspace;

  jq = lib.getExe pkgs.jq;
  grep = lib.getExe pkgs.gnugrep;
  realpath = lib.getExe' pkgs.coreutils "realpath";
  stat = lib.getExe' pkgs.coreutils "stat";

  destructiveDatabaseGuard = import ./guards/destructive-database.nix {
    inherit jq grep;
    inherit (ws)
      appName
      dbName
      testDbName
      worktreeRoot
      ;
  };

  processKillGuard = import ./guards/process-kill.nix { inherit grep jq; };
  teardownGuard = import ./guards/teardown.nix { inherit grep jq; };
  searchGuard = import ./guards/search.nix;

  qualityGateGuard = import ./guards/quality-gate.nix {
    inherit
      jq
      grep
      realpath
      stat
      ;
    teardownSearch = searchGuard.search;
    teardownSearchRefusalsJson = builtins.toJSON searchGuard.refusals;
    fileEntries = cfg.qualityGate.files;
    dirEntries = cfg.qualityGate.dirs;
    inherit (cfg.qualityGate)
      composerFlooredScripts
      composerLockedScripts
      composerLockedTopLevelPaths
      packageLockedScripts
      packageLockedTopLevelPaths
      ;
  };

  publishingGuard = pkgs.writeShellScript "ask-before-publishing" (
    "export PATH=${
      lib.makeBinPath [
        pkgs.coreutils
        pkgs.gnused
        pkgs.perl
        pkgs.gnugrep
        pkgs.git
      ]
    }:$PATH\n"
    + builtins.readFile ./guards/ask-before-publishing.sh
  );
in
{
  doghead.laravel.mcpServers = {
    shadcn = {
      command = "pnpm";
      args = [
        "dlx"
        "shadcn@latest"
        "mcp"
      ];
    };
    magicuidesign = {
      command = "pnpm";
      args = [
        "dlx"
        "@magicuidesign/mcp@latest"
      ];
    };
    "laravel-boost" = {
      command = "php";
      args = [
        "artisan"
        "boost:mcp"
      ];
    };
    playwright = {
      command = "pnpm";
      args = [
        "dlx"
        "@playwright/mcp@latest"
      ];
    };
    chrome = {
      command = "pnpm";
      args = [
        "dlx"
        "chrome-devtools-mcp@latest"
      ];
    };
    linear = {
      command = "pnpm";
      args = [
        "dlx"
        "mcp-remote"
        "https://mcp.linear.app/mcp"
      ];
    };
  };

  claude.code = {
    enable = true;

    mcpServers = lib.mapAttrs (_: server: {
      type = "stdio";
      inherit (server) command args;
    }) cfg.mcpServers;

    hooks = {
      git-hooks-run = {
        name = "Run git-hooks on the edited file";
        command = ''
          file=$(${jq} -r '.tool_input.file_path // empty')
          [ -f "$file" ] || exit 0
          case "$file" in "$DEVENV_ROOT"/*) ;; *) exit 0 ;; esac
          cd "$DEVENV_ROOT" && ${lib.getExe config.git-hooks.package} run --skip arch --skip no-commit-to-branch --files "$file" >&2
        '';
      };

      block-destructive-database = {
        name = "Block destructive database commands outside the worktree's own databases";
        hookType = "PreToolUse";
        inherit (destructiveDatabaseGuard) matcher command;
      };

      block-teardown = {
        name = "Block agents from tearing a worktree down";
        hookType = "PreToolUse";
        inherit (teardownGuard) matcher command;
      };

      block-process-kill = {
        name = "Block agents from stopping a process by name";
        hookType = "PreToolUse";
        inherit (processKillGuard) matcher command;
      };

      ask-before-publishing = {
        name = "Ask Joey before a merge or a push to main";
        hookType = "PreToolUse";
        matcher = "^(Bash|Monitor)$";
        command = "${publishingGuard}";
      };

      builder-ci-gate = {
        name = "Hold the builder's tasks open until ${
          lib.concatMapStringsSep " and " (script: "composer ${script}") cfg.builderGate
        } ${if lib.length cfg.builderGate == 1 then "passes" else "pass"}";
        hookType = "TaskCompleted";
        command = ''
          input=$(cat)
          [ "$(printf '%s' "$input" | ${jq} -r '.teammate_name // empty')" = "product-builder" ] || exit 0
          cd "$(printf '%s' "$input" | ${jq} -r '.cwd')" || exit 0
          if stack=$(gh stack view --json 2>/dev/null); then
            current=$(printf '%s' "$stack" | ${jq} -r '.currentBranch')
            top=$(printf '%s' "$stack" | ${jq} -r '.branches[-1].name')
            if [ "$current" != "$top" ]; then
              printf 'You are on %s, not the top of the stack, so this task stays open. Run gh stack top and complete the task again.\n' "$current" >&2
              exit 2
            fi
          fi
          for script in ${lib.escapeShellArgs cfg.builderGate}; do
            if ! output=$(composer "$script" 2>&1); then
              printf 'composer %s is failing, so this task stays open. Fix it and complete the task again.\n\n%s\n' "$script" "$(printf '%s' "$output" | tail -n 40)" >&2
              exit 2
            fi
          done
        '';
      };
    }
    // lib.optionalAttrs cfg.qualityGate.enable {
      block-quality-gate-edits = {
        name = "Block edits to a quality gate's configuration";
        hookType = "PreToolUse";
        matcher = qualityGateGuard.editMatcher;
        command = qualityGateGuard.editCommand;
      };
      block-quality-gate-shell-writes = {
        name = "Block shell writes to a quality gate's configuration";
        hookType = "PreToolUse";
        matcher = qualityGateGuard.shellMatcher;
        command = qualityGateGuard.shellCommand;
      };
    };
  };
}
