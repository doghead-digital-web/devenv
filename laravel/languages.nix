{
  pkgs,
  lib,
  config,
  ...
}:

let
  ws = config.doghead.laravel.workspace;
  localIni = "${ws.worktreeRoot}/php.local.ini";
in
{
  languages.php = {
    enable = true;
    version = "8.5";
    extensions = [
      "bcmath"
      "calendar"
      "gd"
      "imagick"
      "zip"
      "pdo_mysql"
      "redis"
      "intl"
      "xdebug"
      "pcov"
    ];
    ini = ''
      ${builtins.readFile ./php.ini}
      ${lib.optionalString (builtins.pathExists localIni) (builtins.readFile localIni)}
    '';
    fpm.pools.web = {
      settings = {
        "listen" = "127.0.0.1:${toString ws.phpPort}";
        "pm" = "dynamic";
        "pm.max_children" = 10;
        "pm.start_servers" = 2;
        "pm.min_spare_servers" = 1;
        "pm.max_spare_servers" = 3;
        "clear_env" = "no";
      };
    };
  };

  languages.javascript = {
    enable = true;
    package = pkgs.nodejs_24;
    pnpm.enable = true;
    corepack.enable = true;
  };
}
