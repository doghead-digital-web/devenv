{ lib, ... }:

let
  inherit (lib) mkOption types;

  stringList =
    default:
    mkOption {
      type = types.listOf types.str;
      inherit default;
    };
in
{
  imports = [
    ./workspace.nix
    ./languages.nix
    ./services.nix
    ./git-hooks.nix
    ./ci.nix
    ./agents.nix
    ./worktrees.nix
  ];

  options.doghead.laravel = {
    appName = mkOption {
      type = types.strMatching "[a-z][a-z0-9]*";
      description = ''
        The app's short name. It names the databases (`<appName>_<worktree>`),
        the hosts (`<worktree>.<appName>.test`), the Caddy sites, the RustFS
        bucket and the herdr workspace `feature` and `teardown` look for.
      '';
      example = "harvst";
    };

    commitExample = mkOption {
      type = types.str;
      description = "The ticketed commit subject the commit-msg hook shows when it refuses one.";
      example = "[HRVST-62.4] feat(feedback): what changed";
    };

    formatter = mkOption {
      type = types.enum [
        "vite-plus"
        "prettier"
      ];
      default = "vite-plus";
      description = ''
        The JS lint and format toolchain. `vite-plus` runs `vp check` as the
        git hook and `pnpm run check` in ci-lint; `prettier` runs prettier as
        the hook and `pnpm lint:check` and `pnpm fmt:check` in ci-lint.
      '';
    };

    ciPrepare = mkOption {
      type = types.lines;
      default = "";
      description = "Shell lines every ci-* job runs first, such as generating route modules.";
      example = "composer types:generate";
    };

    ciJobs = stringList [
      "ci-lint"
      "ci-hooks"
      "ci-test"
      "ci-browser"
      "ci-coverage"
    ];

    builderGate = stringList [ "ci:check" ];

    mcpServers = mkOption {
      type = types.attrsOf (
        types.submodule {
          options = {
            command = mkOption { type = types.str; };
            args = mkOption {
              type = types.listOf types.str;
              default = [ ];
            };
          };
        }
      );
      default = { };
      description = "MCP servers written to `.mcp.json` for Claude Code, on top of the module's own set.";
    };

    yolo.enable = lib.mkEnableOption "the `claude-yolo` script, which starts Claude Code with every hook and permission prompt off";

    qualityGate = {
      enable = lib.mkEnableOption "the guard that blocks agents from editing a quality gate's configuration";

      files = stringList [
        "phpstan.neon"
        "pint.json"
        "rector.php"
        "phpunit.xml"
        "eslint.config.js"
        ".prettierrc"
        ".prettierignore"
        "tsconfig.json"
        "vitest.config.ts"
        "devenv.nix"
        "devenv.yaml"
        "devenv.lock"
        "devenv.local.nix"
        "devenv.local.yaml"
        ".envrc"
        ".claude/settings.json"
        ".claude/settings.local.json"
      ];

      dirs = stringList [
        "tests/unit/nix"
        ".github/workflows"
        "nix"
      ];

      composerFlooredScripts = stringList [
        "test:types"
        "test:coverage"
        "test:mutation"
      ];

      composerLockedScripts = stringList [
        "ci:check"
        "test"
        "lint"
        "lint:arch"
        "fmt:check"
        "refactor:check"
        "test:browser"
        "types:generate"
      ];

      composerLockedTopLevelPaths = mkOption {
        type = types.listOf (types.listOf types.str);
        default = [
          [
            "config"
            "bin-dir"
          ]
          [ "repositories" ]
          [ "extra" ]
        ];
      };

      packageLockedScripts = stringList [
        "build"
        "build:ssr"
        "fmt:check"
        "lint:check"
        "test:unit"
        "types:check"
      ];

      packageLockedTopLevelPaths = mkOption {
        type = types.listOf (types.listOf types.str);
        default = [ [ "prettier" ] ];
      };
    };
  };
}
