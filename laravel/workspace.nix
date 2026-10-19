{ lib, config, ... }:

let
  inherit (config.doghead.laravel) appName;

  worktreeRoot = config.devenv.root;

  hasInlineGitDir = ((builtins.readDir worktreeRoot).".git" or null) == "directory";
  worktreeName = if hasInlineGitDir then "main" else lib.toLower (builtins.baseNameOf worktreeRoot);

  hexDigit = c: lib.lists.findFirstIndex (d: d == c) null (lib.stringToCharacters "0123456789abcdef");

  # Every app shares one port range, so the slot hashes the app with the
  # worktree: two apps' `main` checkouts land on different slots.
  slotHash = builtins.hashString "sha256" "${appName}/${worktreeName}";
  hashValue = lib.foldl' (acc: c: acc * 16 + hexDigit c) 0 (
    lib.stringToCharacters (builtins.substring 0 4 slotHash)
  );

  indexFile = "${worktreeRoot}/.devenv-index";
  slot =
    if builtins.pathExists indexFile then
      lib.toInt (lib.trim (builtins.readFile indexFile))
    else
      (lib.mod hashValue 500) + 1;

  isCi = builtins.getEnv "CI" != "";

  databaseNames = import ./database-names.nix appName worktreeName;
in
{
  options.doghead.laravel.workspace = lib.mkOption {
    type = lib.types.attrs;
    readOnly = true;
    description = ''
      Naming, ports, hosts, and paths derived from the app and the current git
      worktree. Every other module reads its values from here.
    '';
  };

  config = {
    assertions = [
      {
        assertion = slot >= 1 && slot <= 500;
        message = "The port slot for ${appName}/${worktreeName} is ${toString slot}; .devenv-index must hold a number from 1 to 500.";
      }
    ];

    doghead.laravel.workspace = rec {
      inherit appName worktreeName worktreeRoot;

      phpPort = 8500 + slot;
      vitePort = 5673 + slot;
      rustfsPort = 9000;
      redisDbIndex = (lib.mod hashValue 63) + 1;

      dbUser = "root";
      inherit (databaseNames) dbName;
      testDbName = if isCi then "${appName}_test" else databaseNames.testDbName;
      reservedDatabasePattern = import ./reserved-databases.nix appName;

      backendHost = "${worktreeName}.${appName}.test";
      viteHost = "assets.${backendHost}";

      rustfsAccessKey = "rustfsadmin";
      rustfsSecretKey = "rustfsadmin";
      rustfsBucket = "${appName}-" + lib.replaceStrings [ "." "/" "_" ] [ "-" "-" "-" ] worktreeName;

      caddySitesDir = "${builtins.getEnv "HOME"}/.config/caddy/sites";
      caddySite = "${caddySitesDir}/${appName}-${worktreeName}.caddy";
    };
  };
}
