# A guard that blocks a Bash or Monitor command naming a word, unless the
# command is one line matching an allowed form or a plain search.
{
  grep,
  jq,
  word,
  allowed,
  message,
}:

let
  inherit (import ./search.nix) search refusals;
  escape = s: "'" + builtins.replaceStrings [ "'" ] [ "'\\''" ] s + "'";
in
{
  matcher = "^(Bash|Monitor)$";
  command = ''
    cmd=$(${jq} -r '.tool_input.command // empty')
    printf '%s' "$cmd" | ${grep} -Eqi ${escape word} || exit 0
    if [ "$(printf '%s' "$cmd" | tr -d '\n\r')" = "$cmd" ]; then
      printf '%s' "$cmd" | ${grep} -Eqx ${escape allowed} && exit 0
      if printf '%s' "$cmd" | ${grep} -Eq ${escape search} &&
        ! printf '%s' "$cmd" | ${grep} -Eq ${
          builtins.concatStringsSep " " (map (pattern: "-e ${escape pattern}") refusals)
        }; then
        exit 0
      fi
    fi
    echo ${escape message} >&2
    exit 2
  '';
}
