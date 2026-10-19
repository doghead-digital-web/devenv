{
  jq,
  grep,
  realpath,
  stat,
  teardownSearch,
  teardownSearchRefusalsJson,
  fileEntries,
  dirEntries,
  composerFlooredScripts,
  composerLockedScripts,
  composerLockedTopLevelPaths,
  packageLockedScripts,
  packageLockedTopLevelPaths,
}:

let
  teardownSearchRefusals = builtins.fromJSON teardownSearchRefusalsJson;

  escapeShellSingleQuoted = s: "'" + (builtins.replaceStrings [ "'" ] [ "'\"'\"'" ] s) + "'";

  rgRefusalFlags = builtins.concatStringsSep " " (
    map (p: "-e " + escapeShellSingleQuoted p) teardownSearchRefusals
  );

  teardownSearchQuoted = escapeShellSingleQuoted teardownSearch;

  escapeRegex =
    s:
    builtins.replaceStrings
      [
        "\\"
        "."
        "*"
        "+"
        "?"
        "("
        ")"
        "["
        "]"
        "{"
        "}"
        "^"
        "$"
        "|"
      ]
      [
        "\\\\"
        "\\."
        "\\*"
        "\\+"
        "\\?"
        "\\("
        "\\)"
        "\\["
        "\\]"
        "\\{"
        "\\}"
        "\\^"
        "\\$"
        "\\|"
      ]
      s;

  composerEntry = "composer.json";
  packageEntry = "package.json";

  filePattern = builtins.concatStringsSep "|" (map (e: "/" + escapeRegex e + "$") fileEntries);
  dirPattern = builtins.concatStringsSep "|" (map (e: "/" + escapeRegex e + "($|/)") dirEntries);
  contextExemptPattern = builtins.concatStringsSep "|" (
    map (e: "/" + escapeRegex e + "/context\\.md$") dirEntries
  );
  composerPattern = "/" + escapeRegex composerEntry + "$";
  packagePattern = "/" + escapeRegex packageEntry + "$";

  basenameOf = e: builtins.elemAt (builtins.split "/" e) (builtins.length (builtins.split "/" e) - 1);

  shellWordPattern = word: "(^|[^a-z0-9_-])" + escapeRegex word + "([^a-z0-9_-]|$)";

  shellNamePatterns = builtins.concatStringsSep "|" (
    map (e: shellWordPattern (basenameOf e)) (fileEntries ++ dirEntries)
    ++ [
      (shellWordPattern composerEntry)
      (shellWordPattern packageEntry)
    ]
  );

  composerConfigPattern = ''(^|[^a-z0-9_.-])composer(\.phar)?["']?([[:space:]]+[^[:space:]]+)*[[:space:]]+["']?(c(o(n(f(ig?)?)?)?)?|rep(o(s(i(t(o(ry?)?)?)?)?)?)?|po(l(i(cy?)?)?)?)["']?([[:space:];&|)<>]|$)'';

  composerConfigPatternQuoted = escapeShellSingleQuoted composerConfigPattern;

  packagePkgCommandPattern = "(^|[^a-z0-9_-])(pnpm|npm|yarn)([[:space:]]+[^[:space:]]+)*[[:space:]]+(pkg|set-script)([^a-z0-9_-]|$)";

  gitWordPattern = shellWordPattern "git";
  applyOrAmWordPattern = builtins.concatStringsSep "|" [
    (shellWordPattern "apply")
    (shellWordPattern "am")
  ];
  patchPattern = shellWordPattern "patch";
  gitDashedBinaryPattern = "(^|[^a-z0-9_])git-(apply|am)([^a-z0-9_-]|$)";

  nonAsciiPattern = "[^ -~]";

  dotSegmentPattern = ''/\.\.?(/|$)'';

  refuseFunctions = ''
    refuse() {
      echo "Blocked: this is a quality gate's configuration. Only Joey edits it, by hand. Stop, and bring him the exact change you want." >&2
      exit 2
    }
    refuseFallback() {
      echo "Blocked: this could not be safely evaluated, so it is refused by default. Only Joey edits a quality gate's configuration, by hand. Stop, and bring him the exact change you want." >&2
      exit 2
    }
    refuseLinkCount() {
      echo "Blocked: this file has more than one hard link (link count $1), which could be an alias for a quality gate's configuration under a different name. Stop, and bring Joey the exact change you want." >&2
      exit 2
    }
  '';

  buildAfterJq = ''
    def applyOne(text; old; new; all):
      if old == "" then null
      else
        (text / old) as $parts
        | if ($parts | length) == 1 then null
          elif (all | not) and ($parts | length) > 2 then null
          else $parts | join(new)
          end
      end;
    ($input.tool_name) as $tool
    | ($input.tool_input) as $ti
    | (
        if $tool == "Write" then $ti.content
        elif $tool == "Edit" then
          applyOne($before; $ti.old_string; $ti.new_string; ($ti.replace_all // false))
        elif $tool == "MultiEdit" then
          (reduce ($ti.edits // [])[] as $e (
            {ok: true, after: $before};
            if .ok then
              (applyOne(.after; $e.old_string; $e.new_string; ($e.replace_all // false))) as $r
              | if $r == null then {ok: false, after: null} else {ok: true, after: $r} end
            else . end
          )) | if .ok then .after else null end
        else null
        end
      ) as $after
    | if $after == null then {ok: false}
      else {ok: true, after: $after}
      end
  '';

  compareFloorsJq = ''
    def scriptText(scripts; name):
      scripts[name] as $v
      | if $v == null then null
        elif ($v | type) == "array" then ($v | join("\n"))
        else ($v | tostring)
        end;
    def floorOf(text):
      if text == null then null
      else
        ([text | scan("--min=([0-9]+)")]) as $m
        | if ($m | length) != 1 then null else ($m[0][0] | tonumber) end
      end;
    ($before | fromjson) as $beforeJson
    | ($after | fromjson) as $afterJson
    | ${builtins.toJSON composerFlooredScripts}
    | map(
        . as $name
        | scriptText($beforeJson.scripts; $name) as $bt
        | if $bt == null then null
          else
            floorOf($bt) as $bf
            | if $bf == null then null
              else
                scriptText($afterJson.scripts; $name) as $at
                | floorOf($at) as $af
                | {
                    name: $name,
                    violated: (
                      if $at == $bt then false
                      else
                        ($af == null)
                        or ($af < $bf)
                        or ($at != ($bt | sub("--min=" + ($bf | tostring); "--min=" + ($af | tostring))))
                      end
                    ),
                  }
              end
          end
      )
    | map(select(. != null))
    | map(select(.violated))
    | length > 0
  '';

  countStreamLeavesJq = "select(length == 2)";
  countParsedLeavesJq = ''[paths(type != "object" and type != "array" or length == 0)] | length'';

  aliasOrCaseShadowJq = ''
    ($after | fromjson) as $afterJson
    | $lockedNames as $locked
    | ($locked | map(ascii_downcase)) as $lockedLower
    | (
        [($afterJson["scripts-aliases"] // {}) | to_entries[] | .value[]? | ascii_downcase]
        | any(. as $t | $lockedLower | index($t) != null)
      ) as $aliasShadow
    | (
        (($afterJson.scripts // {}) | keys) as $scriptKeys
        | $locked
        | any(
            . as $f
            | $scriptKeys | any(. as $k | ($k | ascii_downcase) == ($f | ascii_downcase) and $k != $f)
          )
      ) as $caseShadow
    | $aliasShadow or $caseShadow
  '';

  lockedPathsUnchangedJq = ''
    ($before | fromjson) as $beforeJson
    | ($after | fromjson) as $afterJson
    | $lockedPaths
    | any(. as $path | ($beforeJson | getpath($path)) != ($afterJson | getpath($path)))
  '';

  composerAllLockedNames = composerFlooredScripts ++ composerLockedScripts;
  composerAllLockedNamesJson = builtins.toJSON composerAllLockedNames;
  composerLockedPathsJson = builtins.toJSON (
    map (name: [
      "scripts"
      name
    ]) composerLockedScripts
    ++ composerLockedTopLevelPaths
  );

  packagePrePostPaths = builtins.concatLists (
    map (name: [
      [
        "scripts"
        ("pre" + name)
      ]
      [
        "scripts"
        ("post" + name)
      ]
    ]) packageLockedScripts
  );
  packageLockedPathsJson = builtins.toJSON (
    map (name: [
      "scripts"
      name
    ]) packageLockedScripts
    ++ packageLockedTopLevelPaths
    ++ packagePrePostPaths
  );

  editCommand = ''
    export LC_ALL=C
    set -euo pipefail
    ${refuseFunctions}
    trap refuseFallback ERR

    countsMatch() {
      local text a b
      text=$(cat)
      a=$(printf '%s' "$text" | ${jq} --stream -c '${countStreamLeavesJq}' | wc -l | tr -d '[:space:]')
      b=$(printf '%s' "$text" | ${jq} '${countParsedLeavesJq}')
      [ "$a" = "$b" ]
    }

    classifyPath() {
      local p="$1"
      if ${grep} -Eqi -e '${contextExemptPattern}' <<<"$p"; then
        echo context-exempt
      elif ${grep} -Eqi -e '${composerPattern}' <<<"$p"; then
        echo composer
      elif ${grep} -Eqi -e '${packagePattern}' <<<"$p"; then
        echo package
      elif ${grep} -Eqi -e '${filePattern}' -e '${dirPattern}' <<<"$p"; then
        echo protected
      else
        echo none
      fi
    }

    input=$(cat)
    tool=$(printf '%s' "$input" | ${jq} -r '.tool_name // empty')
    rawPath=$(printf '%s' "$input" | ${jq} -r '.tool_input.file_path // .tool_input.notebook_path // empty')

    [ -n "$rawPath" ] || refuseFallback

    case "$rawPath" in
      "~") rawPath="$HOME" ;;
      "~/"*) rawPath="$HOME/''${rawPath#"~/"}" ;;
    esac

    case "$rawPath" in
      /*) ;;
      *) refuseFallback ;;
    esac

    ${grep} -Eq -e '${dotSegmentPattern}' <<<"$rawPath" && refuseFallback

    resolved=$(${realpath} -m "$rawPath")
    resolvedGiven=$(${realpath} -m -s "$rawPath")

    ${grep} -Eq -e '${nonAsciiPattern}' <<<"$resolved" && refuseFallback
    ${grep} -Eq -e '${nonAsciiPattern}' <<<"$resolvedGiven" && refuseFallback

    if [ -e "$resolved" ] && [ -f "$resolved" ]; then
      linkCount=$(${stat} --format=%h "$resolved")
      [ "$linkCount" = "1" ] || refuseLinkCount "$linkCount"
    fi

    classResolved=$(classifyPath "$resolved")
    classGiven=$(classifyPath "$resolvedGiven")
    [ "$classResolved" = "$classGiven" ] || refuse

    case "$classResolved" in
      context-exempt | none)
        exit 0
        ;;
      protected)
        refuse
        ;;
      composer)
        case "$tool" in
          Write | Edit | MultiEdit) ;;
          *) refuse ;;
        esac

        buildResult=$(${jq} -n --argjson input "$input" --rawfile before "$resolved" '${buildAfterJq}')
        ok=$(printf '%s' "$buildResult" | ${jq} -r '.ok')
        [ "$ok" = "true" ] || refuse
        after=$(printf '%s' "$buildResult" | ${jq} -r '.after')

        cat "$resolved" | countsMatch || refuse
        printf '%s' "$after" | countsMatch || refuse

        violated=$(${jq} -n --rawfile before "$resolved" --arg after "$after" '${compareFloorsJq}')
        [ "$violated" = "false" ] || refuse

        lockViolated=$(${jq} -n --rawfile before "$resolved" --arg after "$after" --argjson lockedPaths '${composerLockedPathsJson}' '${lockedPathsUnchangedJq}')
        [ "$lockViolated" = "false" ] || refuse

        shadowed=$(${jq} -n --arg after "$after" --argjson lockedNames '${composerAllLockedNamesJson}' '${aliasOrCaseShadowJq}')
        [ "$shadowed" = "false" ] || refuse

        exit 0
        ;;
      package)
        case "$tool" in
          Write | Edit | MultiEdit) ;;
          *) refuse ;;
        esac

        buildResult=$(${jq} -n --argjson input "$input" --rawfile before "$resolved" '${buildAfterJq}')
        ok=$(printf '%s' "$buildResult" | ${jq} -r '.ok')
        [ "$ok" = "true" ] || refuse
        after=$(printf '%s' "$buildResult" | ${jq} -r '.after')

        cat "$resolved" | countsMatch || refuse
        printf '%s' "$after" | countsMatch || refuse

        lockViolated=$(${jq} -n --rawfile before "$resolved" --arg after "$after" --argjson lockedPaths '${packageLockedPathsJson}' '${lockedPathsUnchangedJq}')
        [ "$lockViolated" = "false" ] || refuse

        exit 0
        ;;
    esac
  '';

  shellCommand = ''
    export LC_ALL=C
    set -euo pipefail
    ${refuseFunctions}
    trap refuseFallback ERR

    input=$(cat)
    cmd=$(printf '%s' "$input" | ${jq} -r '.tool_input.command // empty')
    [ -n "$cmd" ] || exit 0

    [ "''${#cmd}" -le 65536 ] || refuseFallback

    triggered=0
    ${grep} -Eqi -e '${shellNamePatterns}' <<<"$cmd" && triggered=1
    ${grep} -Eqi -e ${composerConfigPatternQuoted} <<<"$cmd" && triggered=1
    ${grep} -Eqi -e '${packagePkgCommandPattern}' <<<"$cmd" && triggered=1
    ${grep} -Eqi -e '${patchPattern}' <<<"$cmd" && triggered=1
    ${grep} -Eqi -e '${gitDashedBinaryPattern}' <<<"$cmd" && triggered=1
    if ${grep} -Eqi -e '${gitWordPattern}' <<<"$cmd" && ${grep} -Eqi -e '${applyOrAmWordPattern}' <<<"$cmd"; then
      triggered=1
    fi
    ${grep} -Eq -e '${nonAsciiPattern}' <<<"$cmd" && triggered=1

    [ "$triggered" = 1 ] || exit 0

    if [ "$(printf '%s' "$cmd" | tr -d '\n\r')" != "$cmd" ]; then
      refuse
    fi

    if ${grep} -Eq '[;&|<>$`(){}"\\]' <<<"$cmd"; then
      refuse
    fi

    plainToken='([A-Za-z0-9_.][A-Za-z0-9_./:=,*@+-]*|'"'"'[^'"'"'-][^'"'"']*'"'"')'

    if ${grep} -Eqx "^(cat|jq)( $plainToken)+\$" <<<"$cmd"; then
      exit 0
    fi

    if ${grep} -Eqx "^git add( $plainToken)+\$" <<<"$cmd"; then
      exit 0
    fi

    if ${grep} -Eqx "^git (diff|log)( --)?( $plainToken)*\$" <<<"$cmd"; then
      exit 0
    fi

    if ${grep} -Eqx "^git apply --(cached|check)( $plainToken)+\$" <<<"$cmd"; then
      exit 0
    fi

    if ${grep} -Eq ${teardownSearchQuoted} <<<"$cmd" && ! ${grep} -Eq ${rgRefusalFlags} <<<"$cmd"; then
      exit 0
    fi

    if ${grep} -Eqx '^vendor/bin/pest( --compact)? tests/Unit/Nix(/[A-Za-z]+Test\.php)?( --filter=[A-Za-z0-9_]+)?$' <<<"$cmd"; then
      exit 0
    fi

    refuse
  '';
in
{
  editMatcher = "^(Edit|Write|MultiEdit|NotebookEdit)$";
  inherit editCommand;
  shellMatcher = "^(Bash|Monitor)$";
  inherit shellCommand;
}
