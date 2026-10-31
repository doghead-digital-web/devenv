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
    printf '%s\n' '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"Merging and pushing to main need Joey. Feature branches, draft PRs, PR titles and bodies, and readying a PR publish freely, but merging, retargeting a PR, pushing to main, or force-pushing outside gh stack waits for approval from Joey."}}'
    exit 0
}

cmd=$(field command) || ask
cwd=$(field cwd) || ask

flat=$(printf '%s' "$cmd" | perl -0777 -pe 's/\\\n//g; s/[\r\n]/ /g') || ask
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

reads='^gh (pr (view|list|status|checks|diff)|issue (view|list)|repo view|run (list|view|watch)|auth status|release (view|list))( |$)'
draft='^gh pr create (--draft|-d)( |$)'
stack='^gh stack (view|push|sync|link|submit|rebase|add|init|top|bottom|up|down|checkout)( |$)'
stackmain='--open|--base| main( |$)'
token='^gh auth status( .*)? (--show-token|-[A-Za-z]*t)'
rgexec='--pre|--hostname-bin'

api_get() {
    local w=("$@") i endpoints=0
    [[ "${w[0]-} ${w[1]-}" == 'gh api' ]] || return 1
    for ((i = 2; i < ${#w[@]}; i++)); do
        case "${w[i]}" in
            --paginate | --slurp | -i | --include | --silent) ;;
            --jq | -q | --cache)
                i=$((i + 1))
                [[ "${w[i]-}" == -* ]] && return 1
                ;;
            -h | --header)
                i=$((i + 1))
                [[ "${w[i]-}" == -* ]] && return 1
                printf '%s' "${w[i]-}" | grep -qi override && return 1
                ;;
            -x | --method)
                i=$((i + 1))
                [[ "${w[i]-}" == get ]] || return 1
                ;;
            -* | graphql) return 1 ;;
            *) endpoints=$((endpoints + 1)) ;;
        esac
    done
    [[ "$endpoints" == 1 ]]
}

pr_edit_ready() {
    local w=("$@") i n=0 m=0 repo='^[a-z0-9_.-]+/[a-z0-9_.-]+$'
    [[ "${w[0]-} ${w[1]-}" == 'gh pr' ]] || return 1
    case "${w[2]-}" in edit | ready) ;; *) return 1 ;; esac
    for ((i = 3; i < ${#w[@]}; i++)); do
        case "${w[i]}" in
            --repo=*) [[ "${w[i]#--repo=}" =~ $repo ]] || return 1 ;;
            --repo)
                i=$((i + 1))
                [[ "${w[i]-}" =~ $repo ]] || return 1
                ;;
            --title=* | --title | --body-file=* | --body-file)
                [[ "${w[2]}" == edit ]] || return 1
                local flag="${w[i]%%=*}" v
                if [[ "${w[i]}" == *=* ]]; then
                    v="${w[i]#*=}"
                else
                    i=$((i + 1))
                    v="${w[i]-}"
                fi
                [[ "$v" =~ ^[^-] ]] || return 1
                [[ "$flag" == --body-file && ! "$v" =~ \.md$ ]] && return 1
                m=$((m + 1))
                ;;
            -*) return 1 ;;
            *)
                [[ "${w[i]}" =~ ^[0-9]+$ ]] || return 1
                n=$((n + 1))
                ;;
        esac
    done
    [[ "$n" == 1 ]] || return 1
    [[ "${w[2]}" == ready || "$m" -gt 0 ]]
}

read_only() {
    local g="$*" f a i v n
    case "$1" in
        gh)
            [[ "$g" =~ $token ]] && return 1
            [[ "$g" =~ $reads || "$g" =~ ^gh\ stack\ view( |$) ]] || api_get "$@"
            ;;
        git)
            case "${2-}" in
                status | rev-parse | merge-tree) ;;
                log | show | diff) [[ "$g" != *--output* ]] ;;
                branch)
                    for f in "${@:3}"; do
                        case "$f" in
                            -r | --remotes | -a | --all | -v | -vv | --verbose | -l | --list | --show-current | --no-color) ;;
                            -*) return 1 ;;
                            *) [[ " $g " == *' --list '* || " $g " == *' -l '* ]] || return 1 ;;
                        esac
                    done
                    ;;
                fetch)
                    for f in "${@:3}"; do
                        case "$f" in
                            -p | --prune | --all | -q | --quiet | -v | --verbose | -t | --tags | --no-tags | --dry-run) ;;
                            -*) return 1 ;;
                        esac
                    done
                    ;;
                *) return 1 ;;
            esac
            ;;
        rg) [[ ! "$g" =~ $rgexec ]] ;;
        grep | jq | head | tail | wc | cat | ls | date | echo | cut | tr) ;;
        printf) [[ "${2-}" != -* ]] ;;
        base64)
            n=0
            for f in "${@:2}"; do
                case "$f" in
                    -d | -D | --decode) n=$((n + 1)) ;;
                    *) return 1 ;;
                esac
            done
            [[ "$n" -gt 0 ]]
            ;;
        sort)
            a=("$@")
            for ((i = 1; i < ${#a[@]}; i++)); do
                v="${a[i]}"
                if [[ "$v" =~ ^-[bdfghiMnrRsuVz]+$ ]]; then
                    :
                elif [[ "$v" == -k || "$v" == -t ]]; then
                    i=$((i + 1))
                    [[ -n "${a[i]-}" ]] || return 1
                elif [[ "$v" =~ ^-k.+$ || "$v" =~ ^-t.$ ]]; then
                    :
                elif [[ "$v" == --reverse || "$v" == --numeric-sort || "$v" == --general-numeric-sort || "$v" == --human-numeric-sort || "$v" == --version-sort || "$v" == --month-sort || "$v" == --unique || "$v" == --ignore-case || "$v" == --ignore-leading-blanks || "$v" == --dictionary-order || "$v" == --stable || "$v" == --zero-terminated || "$v" == --key=* || "$v" == --field-separator=* ]]; then
                    :
                elif [[ "$v" == -* ]]; then
                    return 1
                fi
            done
            ;;
        uniq)
            a=("$@")
            n=0
            for ((i = 1; i < ${#a[@]}; i++)); do
                v="${a[i]}"
                case "$v" in
                    -c | -d | -u | -i | -D | --count | --repeated | --unique | --ignore-case) ;;
                    -f | -s)
                        i=$((i + 1))
                        [[ "${a[i]-}" =~ ^[0-9]+$ ]] || return 1
                        ;;
                    -f* | -s*) [[ "${v:2}" =~ ^[0-9]+$ ]] || return 1 ;;
                    -*) return 1 ;;
                    *) n=$((n + 1)) ;;
                esac
            done
            [[ "$n" == 0 ]]
            ;;
        *) return 1 ;;
    esac
}

chain_reads() {
    local pieces w
    pieces=$(CMD="$cmd" perl -e '
        my $s = $ENV{CMD};
        exit 1 if $s =~ /[`\$\{\}<>\\\r]/;
        my @pieces = ([]);
        my $w;
        my $need_new_piece = 0;
        my $end = sub { push @{ $pieces[-1] }, length $w ? $w : "\x1f" if defined $w; undef $w };
        for ($s) {
            until (/\G\z/gc) {
                if (/\G[ \t]+/gc) { $end->() }
                elsif (/\G\n/gc) { $end->(); $need_new_piece = 1 if @{ $pieces[-1] } }
                elsif (/\G(?:;|&&|\|\|?)/gc) { exit 1 if $need_new_piece; $end->(); @{ $pieces[-1] } or exit 1; push @pieces, [] }
                elsif (/\G([A-Za-z0-9_.\/:=,@%+-]+)/gc) { push(@pieces, []), ($need_new_piece = 0) if $need_new_piece; $w .= $1 }
                elsif (/\G\x27([^\x27]*)\x27/gc || /\G"([^"]*)"/gc) { exit 1 if $1 =~ /\n/; push(@pieces, []), ($need_new_piece = 0) if $need_new_piece; (my $q = $1) =~ s/\s/\x1f/g; $w .= $q }
                else { exit 1 }
            }
        }
        $end->();
        @{ $pieces[-1] } or exit 1;
        for (@pieces) {
            @$_ = map { lc } @$_ if lc $_->[0] eq "gh";
            print "@$_\n";
        }
    ') || return 1
    while read -ra w; do
        read_only "${w[@]}" || return 1
    done <<<"$pieces"
}

chain_reads && exit 0

joined=$(printf '%s' "$cmd" | perl -0777 -pe 's/\\\n//g') || ask
case "$joined" in *[\;\&\`\{]* | *\$\(* | *'<('* | *'>('* | *$'\n'*) ask ;; esac
case "$norm" in *'|'*'|'*) ask ;; esac
invocation="${words%%|*}"
if [[ "$invocation" != "$words" ]]; then
    filter="${words#*|}"
    [[ "$filter" =~ ^[[:space:]]*(jq|head|tail|rg|grep|wc)([[:space:]]|$) ]] || ask
    [[ "$filter" =~ $rgexec || "${norm#*|}" =~ $rgexec ]] && ask
fi
read -ra w <<<"$invocation"
g="${w[*]}"

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
if pr_edit_ready "${w[@]}"; then
    [[ "$invocation" == "$words" ]] || ask
    case "$cmd" in *[\$\`\{\}\<\>\\]* | *[[:cntrl:]]*) ask ;; esac
    case "$unquoted" in *[\*\?\[\~]*) ask ;; esac
    exit 0
fi
api_get "${w[@]}" || ask
exit 0
