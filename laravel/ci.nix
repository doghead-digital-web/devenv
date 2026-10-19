{
  lib,
  config,
  ...
}:

let
  cfg = config.doghead.laravel;
  prek = lib.getExe config.git-hooks.package;

  job =
    body:
    lib.concatStringsSep "\n" (
      [ "set -euo pipefail" ]
      ++ lib.optional (cfg.ciPrepare != "") (lib.removeSuffix "\n" cfg.ciPrepare)
      ++ [ body ]
    );

  jsChecks =
    {
      vite-plus = ''
        pnpm run check
        pnpm run types:check
      '';
      prettier = ''
        pnpm lint:check
        pnpm fmt:check
        pnpm types:check
      '';
    }
    .${cfg.formatter};
in
{
  scripts = {
    ci-lint.description = "Static analysis, formatting and type checks, as the CI lint job runs them";
    ci-lint.exec = job ''
      composer fmt:check
      composer refactor:check
      composer lint
      composer test:types
      composer lint:arch
      pnpm run build
      ${jsChecks}
    '';

    ci-test.description = "The PHP test suite in parallel against the test database, as the CI test job runs it";
    # pest rather than `artisan test --parallel`: Laravel's parallel command
    # rejects --ci outright. This job never runs refactor:check, so --ci is
    # what keeps a stray ->only() from narrowing it to a green subset.
    ci-test.exec = job ''
      pnpm run build
      vendor/bin/pest --parallel --ci --fail-on-risky --fail-on-warning --fail-on-deprecation --fail-on-notice --fail-on-phpunit-deprecation
    '';

    ci-browser.description = "The Playwright browser suite against the test database";
    # Chromium is a real browser binary, not a pnpm dependency.
    ci-browser.exec = job ''
      pnpm exec playwright install --with-deps chromium
      composer test:browser
    '';

    ci-coverage.description = "The line coverage gate";
    ci-coverage.exec = job ''
      pnpm run build
      composer test:coverage
    '';

    ci-mutation.description = "The mutation gate, which CI runs on a schedule only";
    ci-mutation.exec = job ''
      pnpm run build
      composer test:mutation
    '';

    ci-hooks.description = "The git hooks no other gate covers, on every tracked file";
    ci-hooks.exec = "${prek} run --all-files --skip pint --skip rector --skip ${cfg.jsHook} --skip arch --skip no-commit-to-branch";

    ci.description = "Every pull-request CI job, in order";
    ci.exec = ''
      set -euo pipefail
      ${lib.concatStringsSep "\n" cfg.ciJobs}
    '';
  };
}
