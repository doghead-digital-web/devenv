{
  jq,
  grep,
  appName,
  dbName,
  testDbName,
  worktreeRoot,
}:

let
  assertMsg = pred: msg: pred || builtins.throw msg;

  root =
    assert assertMsg (builtins.match "/[A-Za-z0-9._/-]+" worktreeRoot != null)
      "The worktree root ${worktreeRoot} is not only letters, digits, '.', '_', '-' and '/', so the hook cannot name its artisan exactly. Move the worktree.";
    worktreeRoot;

  testDb = "${testDbName}(_test_[0-9]+)?";
  isReserved = builtins.match "${import ../reserved-databases.nix appName}.*" dbName != null;
  devForm = seed: "DB_DATABASE=${dbName} php ${root}/artisan migrate:fresh ${seed}";

  testFormCheck = ''
    cmd=$(${jq} -r '.tool_input.command // empty')
    printf '%s' "$cmd" | ${grep} -Eqi -e '(^|[^a-z0-9_.:-])(m(i(g(r(a(te?)?)?)?)?)?)?:(f(r(e(sh?)?)?)?|r(e(f(r(e(sh?)?)?)?|s(et?)?)?|o(l(l(b(a(ck?)?)?)?)?)?)?)([^a-z0-9_:-]|$)' -e '(^|[^a-z0-9_.:-])(db?)?:w(i(pe?)?)?([^a-z0-9_:-]|$)' -e 'drop([[:space:]]|/\*)' || exit 0
    if [ "$(printf '%s' "$cmd" | tr -d '\n\r')" != "$cmd" ]; then
      echo "Blocked: this wipes a database, and a command that does must be a single line." >&2
      exit 2
    fi
    printf '%s' "$cmd" | ${grep} -Eqx -e 'DB_DATABASE=(${testDb}|"\$\{DB_TEST_DATABASE:\?\}") APP_ENV=testing php artisan (migrate:(fresh|reset|refresh|rollback)|db:wipe)( (--force|--seed|--step=[0-9]+|--no-interaction|-n))*' && exit 0
  '';

  devFormCheck = ''
    if [ "$cmd" = '${devForm "--seed"}' ] || [ "$cmd" = '${devForm "--seeder=DemoSeeder"}' ]; then
      if [ -e '${root}/bootstrap/cache/config.php' ]; then
        echo "Blocked: bootstrap/cache/config.php exists, so Laravel would ignore DB_DATABASE and wipe the cached database. Run php artisan config:clear first." >&2
        exit 2
      fi
      if [ -f '${root}/.env' ] && ${grep} -Eq "^[[:space:]]*(export[[:space:]]+)?[\"']?(DB_URL|APP_CONFIG_CACHE)[\"']?[[:space:]]*=" '${root}/.env'; then
        echo "Blocked: .env sets DB_URL or APP_CONFIG_CACHE, which would point this command away from ${dbName}. Remove that line from .env first." >&2
        exit 2
      fi
      if [ -n "''${DB_URL:-}''${APP_CONFIG_CACHE:-}''${APP_ENV:-}" ]; then
        echo "Blocked: this session sets DB_URL, APP_CONFIG_CACHE or APP_ENV, which would point this command away from ${dbName}. Start the session from the worktree's devenv shell, which sets none of them." >&2
        exit 2
      fi
      exit 0
    fi
  '';

  devFormMessage = " This worktree may also rebuild its own dev database with one line, DB_DATABASE=${dbName} php ${root}/artisan migrate:fresh --seed, or the same line ending in --seeder=DemoSeeder in its place.";

  refusal = ''
    echo "Blocked: this wipes a database. The only allowed form is one line, DB_DATABASE=\"\''${DB_TEST_DATABASE:?}\" APP_ENV=testing php artisan migrate:fresh --force (or ${testDbName} or a _test_<N> copy named literally). Raw drops through mysql or mysqladmin are always blocked. Joey removes a finished worktree's databases with teardown <worktree> from the main checkout; give him that command at hand-off.${
      if isReserved then "" else devFormMessage
    }" >&2
    exit 2
  '';
in
{
  matcher = "^(Bash|Monitor)$";
  command = testFormCheck + (if isReserved then "" else devFormCheck) + refusal;
}
