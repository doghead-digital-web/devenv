#!/usr/bin/env bash
set -uo pipefail

input=$(</dev/stdin)

field() {
    INPUT="$input" perl -MJSON::PP -e '
        my $d = JSON::PP->new->utf8->decode($ENV{INPUT});
        my $v = $ARGV[0] eq "cwd" ? $d->{cwd} : $d->{tool_input}{command};
        print $v // "";
    ' "$1"
}

ask() {
    printf '%s\n' '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"Merging and pushing to main need Joey. Feature branches and draft PRs publish freely, but merging, readying a PR, pushing to main, or force-pushing outside gh stack waits for approval from Joey."}}'
    exit 0
}

cmd=$(field command) || ask
cwd=$(field cwd) || ask

flat=$(printf '%s' "$cmd" | perl -0777 -pe 's/\\\n//g; s/\n/ /g') || ask
norm=$(printf '%s' "$flat" | perl -0777 -pe 's/["\x27\\]//g; $_ = lc') || ask
words=$(printf '%s' "$flat" | perl -0777 -pe 's{\x27([^\x27]*)\x27|"((?:[^"\\]|\\.)*)"}{my $s = $1 // $2; $s =~ s/\s/\x1f/g; length $s ? $s : "\x1f"}ge; s/["\x27\\]//g; $_ = lc') || ask
unquoted=$(printf '%s' "$cmd" | sed -E "s/\"[^\"]*\"//g; s/'[^']*'//g") || ask

has() { printf '%s' "$norm" | grep -Eq "$@" || printf '%s' "$words" | grep -Eq "$@"; }

b='(^|[^[:alnum:]_.-])'
git="$b"'git([[:space:]]+[^;&|[:space:]]+)*[[:space:]]+'
allowed='^git push( -u| --set-upstream)? origin [A-Za-z0-9_.][A-Za-z0-9_./-]*$'
protected='^(main|refs/.*|heads/.*|.*HEAD)$|\.\.'
opts='([[:space:]]+(-[CcR]|--repo|--git-dir|--work-tree|--namespace|--super-prefix|--config-env|--attr-source)[[:space:]]+[^[:space:]]+|[[:space:]]+-[^[:space:]]*)*'

has -e "$b"'git'"$opts"'[[:space:]]+[^[:space:]]*[$`{]' -e "$b"'gh'"$opts"'[[:space:]]+([^-[:space:]][^[:space:]]*[[:space:]]+)?[^[:space:]]*[$`{]' && ask
has 'git_config' && ask
has "$b"'git-(push|send-pack|receive-pack)([^[:alnum:]_.-]|$)' && ask

if has "$b"'git'"$opts"'[[:space:]]+push([[:space:]]|$)'; then
    [[ "$cmd" =~ $allowed ]] || ask
    name="${cmd##* }"
    [[ "$name" =~ $protected ]] && ask
    git -C "$cwd" rev-parse --git-dir >/dev/null 2>&1 || ask
    [[ -z "$(git -C "$cwd" config --get-all remote.origin.push)" ]] || ask
    case "$(git -C "$cwd" config --get push.default)" in "" | simple | current) ;; *) ask ;; esac
    if git -C "$cwd" rev-parse -q --verify "$name" >/dev/null 2>&1; then
        [[ "$(git -C "$cwd" rev-parse --symbolic-full-name "$name" 2>/dev/null)" == "refs/heads/$name" ]] || ask
    fi
    git -C "$cwd" symbolic-ref -q "refs/heads/$name" >/dev/null 2>&1 && ask
fi

has -e "$git"'[^;&|[:space:]]*alias\.' -e "$git"'send-pack' && ask

has "$b"'gh([^[:alnum:]_.-]|$)' || exit 0

joined=$(printf '%s' "$cmd" | perl -0777 -pe 's/\\\n//g') || ask
case "$joined" in *[\;\&\`\{]* | *\$\(* | *'<('* | *'>('* | *$'\n'*) ask ;; esac
case "$norm" in *'|'*'|'*) ask ;; esac
invocation="${words%%|*}"
if [[ "$invocation" != "$words" ]]; then
    filter="${words#*|}"
    [[ "$filter" =~ ^[[:space:]]*(jq|head|tail|rg|grep|wc)([[:space:]]|$) ]] || ask
    [[ "$filter" == *--pre* ]] && ask
fi
read -ra w <<<"$invocation"
g="${w[*]}"

reads='^gh (pr (view|list|status|checks|diff)|issue (view|list)|repo view|run (list|view|watch)|auth status|release (view|list))( |$)'
draft='^gh pr create (--draft|-d)( |$)'
stack='^gh stack (view|push|sync|link|submit|rebase|add|init|top|bottom|up|down|checkout)( |$)'
stackmain='--open|--base| main( |$)'
token='^gh auth status( .*)? (--show-token|-[A-Za-z]*t)'

[[ "$g" =~ $token ]] && ask
[[ "$g" =~ $reads ]] && exit 0
if [[ "$g" =~ $draft ]]; then
    [[ "$invocation" == "$words" ]] || ask
    printf '%s' "$unquoted" | grep -Eq '[[:space:]](--draft|-d)([[:space:]]|$)' || ask
    printf '%s' "$norm" | grep -Eq -e '--draft=' -e '-d=' -e '(^|[[:space:]])-[[:alpha:]]*d[[:alpha:]]*=' && ask
    exit 0
fi
if [[ "$g" =~ $stack ]]; then
    [[ "$g" =~ $stackmain ]] && ask
    exit 0
fi
[[ "${w[0]-} ${w[1]-}" == 'gh api' ]] || ask
endpoints=0
for ((i = 2; i < ${#w[@]}; i++)); do
    case "${w[i]}" in
        --paginate | --slurp | -i | --include | --silent) ;;
        --jq | -q | --cache)
            i=$((i + 1))
            [[ "${w[i]-}" == -* ]] && ask
            ;;
        -h | --header)
            i=$((i + 1))
            [[ "${w[i]-}" == -* ]] && ask
            printf '%s' "${w[i]-}" | grep -qi override && ask
            ;;
        -x | --method)
            i=$((i + 1))
            [[ "${w[i]-}" == get ]] || ask
            ;;
        -* | graphql) ask ;;
        *) endpoints=$((endpoints + 1)) ;;
    esac
done
[[ "$endpoints" == 1 ]] || ask
exit 0
