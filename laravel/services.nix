{
  pkgs,
  lib,
  config,
  ...
}:

let
  ws = config.doghead.laravel.workspace;
  git = lib.getExe pkgs.git;
  gawk = lib.getExe pkgs.gawk;
  grep = lib.getExe pkgs.gnugrep;
  describedScripts = lib.filterAttrs (_: script: script.description != "") config.scripts;

  caddySiteContent = pkgs.writeText "${ws.appName}-${ws.worktreeName}.caddy" ''
    ${ws.backendHost} {
      root * ${ws.worktreeRoot}/public
      php_fastcgi 127.0.0.1:${toString ws.phpPort}
      encode zstd gzip
      file_server
    }

    ${ws.viteHost} {
      reverse_proxy 127.0.0.1:${toString ws.vitePort}
    }
  '';

  mysqlArgs = ''-h 127.0.0.1 -P 3306 -u "${ws.dbUser}" ''${DB_PASSWORD:+-p"$DB_PASSWORD"}'';
in
{
  dotenv.disableHint = true;

  packages = [
    pkgs.figlet
    pkgs.minio-client
    pkgs.mysql84
  ];

  enterShell = ''
    figlet "${ws.appName}"
    printf '  %-8s %s\n' app "https://${ws.backendHost}" vite "https://${ws.viteHost}" db "${ws.dbName}"
    echo
    printf '  %-12s %s\n' "devenv up" "start vite, queue, scheduler and pail"
    ${lib.concatStringsSep "\n" (
      lib.mapAttrsToList (
        name: script:
        "printf '  %-12s %s\\n' ${lib.escapeShellArg name} ${lib.escapeShellArg script.description}"
      ) describedScripts
    )}
  '';

  env = {
    APP_URL = "https://${ws.backendHost}";
    APP_HOST = ws.backendHost;

    DB_DATABASE = ws.dbName;
    DB_TEST_DATABASE = ws.testDbName;
    DB_USERNAME = ws.dbUser;

    SESSION_DOMAIN = ".${ws.backendHost}";

    REDIS_DB = toString ws.redisDbIndex;

    R2_BUCKET = ws.rustfsBucket;

    VITE_PORT = toString ws.vitePort;
    VITE_DEV_HOST = ws.viteHost;
  };

  processes = {
    # The port range is shared by every app, so whatever holds this port may
    # be another app's worktree: vite.config's strictPort fails loudly rather
    # than this process killing it.
    vite.exec = "exec pnpm run dev";
    pail.exec = "php artisan config:clear && php artisan pail --timeout=0";
    queue.exec = "php artisan config:clear && php artisan queue:listen --tries=1 --timeout=0";
    scheduler.exec = "php artisan config:clear && php artisan schedule:work";
  };

  tasks = {
    "${ws.appName}:deps" = {
      description = "Install composer and pnpm dependencies";
      before = [ "devenv:enterShell" ];
      cwd = ws.worktreeRoot;
      status = "test -d vendor && test -d node_modules";
      exec = ''
        set -euo pipefail
        [ -d vendor ] || composer install
        [ -d node_modules ] || pnpm install
      '';
    };

    "${ws.appName}:env" = {
      description = "Create .env and generate an application key";
      before = [ "devenv:enterShell" ];
      after = [ "${ws.appName}:deps" ];
      cwd = ws.worktreeRoot;
      status = "test -f .env";
      exec = ''
        set -euo pipefail
        cp .env.example .env
        php artisan key:generate
      '';
    };

    "${ws.appName}:caddy" = {
      description = "Sync this worktree's Caddy site and prune stale ones";
      before = [ "devenv:enterShell" ];
      exec = ''
        mkdir -p "${ws.caddySitesDir}"

        changed=0

        if ! cmp -s "${caddySiteContent}" "${ws.caddySite}"; then
          install -m 644 "${caddySiteContent}" "${ws.caddySite}"
          changed=1
        fi

        # A site is stale once no worktree of this repository has its name,
        # wherever the worktrees live. If git cannot list them, prune nothing.
        if live=$(${git} -C "${ws.worktreeRoot}" worktree list --porcelain); then
          live=$(printf '%s\n' "$live" | ${gawk} '$1 == "worktree" { print substr($0, 10) }' |
            while IFS= read -r path; do
              if [ -d "$path/.git" ]; then echo main; else basename "$path" | tr '[:upper:]' '[:lower:]'; fi
            done)
        else
          live=
        fi

        for f in "${ws.caddySitesDir}"/${ws.appName}-*.caddy; do
          [ -e "$f" ] || continue
          [ -n "$live" ] || break
          name=$(basename "$f" .caddy); name=''${name#${ws.appName}-}
          [ "$name" = "${ws.worktreeName}" ] && continue
          [ "$name" = "main" ] && continue
          if ! printf '%s\n' "$live" | ${grep} -Fxq -- "$name"; then
            echo "→ removing stale caddy site for '$name'"
            rm -f "$f"
            changed=1
          fi
        done

        if [ "$changed" -eq 0 ]; then
          exit 0
        fi

        if ! curl -fsS --max-time 2 http://localhost:2019/config/ >/dev/null 2>&1; then
          echo "⚠ caddy admin API not reachable. Try: sudo launchctl kickstart -k system/org.nixos.caddy"
          exit 0
        fi

        if curl -fsS -X POST -H "Content-Type: text/caddyfile" \
             --data-binary @/etc/caddy/Caddyfile \
             "http://localhost:2019/load?adapter=caddyfile" >/dev/null; then
          echo "✓ caddy reloaded (${ws.backendHost}, ${ws.viteHost})"
        else
          echo "⚠ caddy admin API rejected reload — check /etc/caddy/Caddyfile syntax"
        fi
      '';
    };

    "${ws.appName}:databases" = {
      description = "Create the worktree and test databases";
      before = [ "devenv:enterShell" ];
      status = ''
        mysql ${mysqlArgs} \
          -e 'USE `${ws.dbName}`; USE `${ws.testDbName}`;' >/dev/null 2>&1
      '';
      exec = ''
        if ! mysqladmin -h 127.0.0.1 -P 3306 ping >/dev/null 2>&1; then
          echo "⚠ MySQL is not accepting connections on 127.0.0.1:3306. Try: sudo launchctl kickstart -k system/org.nixos.mysql"
          exit 0
        fi

        for db in "${ws.dbName}" "${ws.testDbName}"; do
          mysql ${mysqlArgs} \
            -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;" >/dev/null 2>&1 || true
        done
      '';
    };

    "${ws.appName}:rustfs" = {
      description = "Ensure the RustFS bucket for this worktree exists";
      before = [ "devenv:enterShell" ];
      status = "mc ls ${ws.appName}-local/${ws.rustfsBucket} >/dev/null 2>&1";
      exec = ''
        if ! curl -fsS --max-time 2 "http://127.0.0.1:${toString ws.rustfsPort}/health" >/dev/null 2>&1; then
          echo "⚠ RustFS not reachable on 127.0.0.1:${toString ws.rustfsPort}; skipping bucket creation."
          exit 0
        fi

        mc alias set ${ws.appName}-local "http://127.0.0.1:${toString ws.rustfsPort}" "${ws.rustfsAccessKey}" "${ws.rustfsSecretKey}" >/dev/null 2>&1

        if mc mb --ignore-existing "${ws.appName}-local/${ws.rustfsBucket}" >/dev/null 2>&1; then
          echo "✓ rustfs bucket ready (${ws.rustfsBucket})"
        fi
      '';
    };

    "${ws.appName}:migrate" = {
      description = "Apply outstanding database migrations";
      before = [ "devenv:enterShell" ];
      after = [
        "${ws.appName}:env"
        "${ws.appName}:databases"
      ];
      cwd = ws.worktreeRoot;
      showOutput = true;
      exec = "php artisan migrate --force";
    };

    "${ws.appName}:seed" = {
      description = "Seed a worktree database that has no users yet";
      before = [ "devenv:enterShell" ];
      after = [ "${ws.appName}:migrate" ];
      cwd = ws.worktreeRoot;
      showOutput = true;
      status = ''
        [ "$(mysql ${mysqlArgs} -N -e 'SELECT COUNT(*) FROM users' "${ws.dbName}" 2>/dev/null || echo 0)" != 0 ]
      '';
      exec = "php artisan db:seed --force";
    };
  };
}
