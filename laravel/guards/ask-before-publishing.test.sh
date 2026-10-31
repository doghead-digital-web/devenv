#!/usr/bin/env bash
set -uo pipefail

# Optional: an app's generated .claude/settings.json, to check that the shell
# runs a /nix/store copy of this script and that the copy works with no PATH.
SETTINGS=${1:-}
HOOK="$(cd "$(dirname "$0")" && pwd)/ask-before-publishing.sh"
RUNNER=(bash "$HOOK")
tag=

export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
FIXTURES=$(mktemp -d)
trap 'rm -rf "$FIXTURES"' EXIT
for repo in clean refspec upstream; do
    git init -q "$FIXTURES/$repo"
done
mkdir "$FIXTURES/notrepo"
git -C "$FIXTURES/refspec" config remote.origin.push refs/heads/feat:refs/heads/main
git -C "$FIXTURES/upstream" config push.default upstream
git init -q -b main "$FIXTURES/symref"
git -C "$FIXTURES/symref" -c user.name=t -c user.email=t@t commit -q --allow-empty -m init
git -C "$FIXTURES/symref" branch feat
git -C "$FIXTURES/symref" symbolic-ref refs/heads/alias refs/heads/main

pass=0
fail=0

check() {
    local want="$1" cmd="$2" repo="${3:-clean}" payload="${4:-}" out code got
    [[ -n "$payload" ]] || payload=$(CMD="$cmd" CWD="$FIXTURES/$repo" perl -MJSON::PP -e '
        print JSON::PP->new->utf8->encode({ cwd => $ENV{CWD}, tool_name => "Bash", tool_input => { command => $ENV{CMD} } });')
    out=$(printf '%s' "$payload" | "${RUNNER[@]}" 2>/dev/null)
    code=$?
    if [[ "$code" != 0 ]]; then
        got="exit $code"
    elif [[ -z "$out" ]]; then
        got=pass
    elif printf '%s' "$out" | perl -MJSON::PP -e '
        my $d = JSON::PP->new->utf8->decode(join "", <STDIN>);
        my $o = $d->{hookSpecificOutput};
        exit !($o->{hookEventName} eq "PreToolUse" && $o->{permissionDecision} eq "ask" && $o->{permissionDecisionReason} =~ /merg.*main.*Joey/i);
    '; then
        got=ask
    elif printf '%s' "$out" | perl -MJSON::PP -e '
        my $d = JSON::PP->new->utf8->decode(join "", <STDIN>);
        my $o = $d->{hookSpecificOutput};
        exit !($o->{hookEventName} eq "PreToolUse" && $o->{permissionDecision} eq "deny" && $o->{permissionDecisionReason} =~ /run every gh command alone/i);
    '; then
        got=deny
    else
        got="bad output: $out"
    fi
    if [[ "$got" == "$want" ]]; then
        printf 'ok   %-4s %s%s%s\n' "$want" "$cmd" "${3:+  ($repo fixture)}" "$tag"
        pass=$((pass + 1))
    else
        printf 'FAIL %-4s %s%s%s (got %s)\n' "$want" "$cmd" "${3:+  ($repo fixture)}" "$tag" "$got"
        fail=$((fail + 1))
    fi
}

check_store() {
    local store
    store=$(perl -MJSON::PP -e '
        local $/;
        my $s = JSON::PP->new->decode(<STDIN>);
        for my $entry (@{ $s->{hooks}{PreToolUse} // [] }) {
            for my $h (@{ $entry->{hooks} // [] }) {
                print $h->{command} if $h->{command} =~ m{-ask-before-publishing$};
            }
        }
    ' <"$SETTINGS")
    if [[ "$store" =~ ^/nix/store/[^[:space:]]+$ ]] && [[ "$(cat "$store")" == *"$(cat "$HOOK")" ]]; then
        printf 'ok   settings.json runs the current script from %s\n' "$store"
        pass=$((pass + 1))
    else
        printf 'FAIL settings.json must run a /nix/store copy of the current script (got %s); reload the devenv shell\n' "${store:-nothing}"
        fail=$((fail + 1))
        return
    fi
    RUNNER=(env -i HOME="$HOME" GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 "$store")
    tag="  (store copy, empty PATH)"
    check ask 'git push origin main'
    check ask 'gh stack link "main" feat'
    check ask 'gh pr create --title "fix -d flag" --body y'
    check ask 'gh api -X PUT repos/o/r/pulls/12/merge'
    check pass 'git push -u origin feat'
    check ask $'gh pr \\\nmerge 12'
    check pass 'gh pr create --draft --title x'
    check ask 'gh pr view 1 >(gh pr merge 1 --admin)'
    check ask 'gh pr view <(gh pr merge 1 --admin)'
    check ask 'gh stack push >(gh pr ready 3)'
    check ask 'gh stack push >(gh stack merge)'
    check ask 'gh pr view 1 | jq . >(gh pr ready 3)'
    check ask $'gh pr create --draft --title \'a"\' --draft=false --body \'"b\''
    check ask 'gh pr create --draft --title "a\"" --draft=false --body "\"b"'
    check ask 'git -c k=v push origin feat'
    check ask 'git --git-dir=x push origin feat'
    check ask 'git --git-dir x push origin main'
    check ask 'git --work-tree x push origin main'
    check pass 'git stash push -u -m wip'
    check pass 'git log --grep push'
    check pass 'git commit -m "ask before push to main"'
    check ask 'GIT push --force origin main'
    check ask 'Git push origin main'
    check ask 'GH pr merge 1 --admin'
    check ask 'Gh api -XPUT repos/o/r/pulls/1/merge'
    check ask 'GIT push origin feat'
    check ask 'git -c "user.name=a b" push --force origin main'
    check ask "git -c 'core.x=1 2' push -f origin HEAD:main"
    check ask 'git --config-env "a.b=X Y" push origin main'
    check ask 'git -C "a b" push origin main'
    check ask 'bash -c "git push origin main"'
    check ask "gh api repos/o/r/pulls/1/merge --jq '' -XPUT"
    check ask 'gh api repos/o/r/pulls/1/merge --jq "" --method=PUT'
    check ask "gh api repos/o/r/pulls/1/merge --cache '' -XPUT"
    check ask 'gh api repos/o/r/pulls/1/merge -H -XPUT'
    check ask 'gh api repos/o/r/pulls/1/merge --cache -XPUT'
    check ask '/usr/libexec/git-core/git-push origin main'
    check ask 'git-push origin main'
    check ask 'git-send-pack origin feat:main'
    check ask '/usr/libexec/git-core/git-receive-pack .'
    check ask 'gh pr create --draft -fd=false --title x'
    check ask 'gh pr create --draft -ed=false --title x'
}

check ask 'git push origin HEAD:main'
check ask 'git push origin feat:refs/heads/main'
check ask 'git push origin @:main'
check ask 'git push origin +feat:main'
check ask 'git push origin :main'
check ask 'git push origin --delete main'
check ask 'git push -d origin main'
check ask 'git push origin main'
check ask 'git push origin +main'
check ask 'git push'
check ask 'git push origin'
check ask 'git push origin HEAD'
check ask 'git push --all'
check ask 'git push --mirror'
check ask 'git push --tags'
check ask 'git -c push.default=upstream push origin feat'
check ask 'git -C ../main push'
check ask 'cd ../main && git push'
check ask 'GIT_DIR=../main/.git git push origin feat'
check ask 'git push origin "feat:main"'
check ask "git push origin 'feat:main'"
check ask 'git push origin feat:ma"in"'
check ask 'git push origin feat:mai\n'
check ask 'git push --force origin feat'
check ask 'git push -f origin feat'
check ask 'git push -uf origin feat'
check ask 'git push -fu origin feat'
check ask 'git push --force-with-lease origin feat'
check ask 'git push --force-with-lease=feat:abc origin feat'
check ask 'git push --force-if-includes origin feat'
check ask 'git push origin +feat'
check ask 'git push origin feat && git push origin feat:main'
check ask 'command git push origin HEAD:main'
check ask '/usr/bin/git push origin HEAD:main'
check ask 'echo "git push origin HEAD:main"'
check ask 'git push origin heads/main'
check ask 'git push origin refs/heads/main'
check ask 'git push origin -f'
check ask 'git push origin ORIG_HEAD'
check ask 'git push origin feat..main'
check ask 'git push origin feat' refspec
check ask 'git push origin feat' upstream

check ask 'malformed payload' clean 'not json'

check ask 'gh pr merge 12 --squash'
check ask 'gh pr merge --auto 12'
check pass 'gh pr ready 12'
check pass 'gh pr ready 12 --repo o/r'
check pass 'gh pr ready 12 --repo=o/r'
check pass 'gh pr edit 12 --title "[JSH-67] feat(fencing): add the disk"'
check pass 'gh pr edit 12 --body-file .claude/team/lead/pr-storage.md'
check ask 'gh pr edit 12 --body-file /tmp/scratch/pr body.md'
check pass 'gh pr edit 12 --body-file /tmp/scratch/pr-body.md'
check pass "gh pr edit 12 --body-file='/tmp/scratch/pr body.md' --title 'x y'"
check pass 'gh pr edit 12 --title=x --body-file=b.md --repo o/r'
check pass 'gh pr edit --title x 12'
check ask 'gh pr ready --undo 12'
check ask 'gh pr ready 12 --undo'
check ask 'gh pr ready'
check ask 'gh pr ready 12 13'
check ask 'gh pr ready feat-branch'
check ask 'gh pr ready https://github.com/o/r/pull/12'
check ask 'gh pr ready -R o/r 12'
check ask 'gh pr ready 12 --repo o/r/x'
check ask 'gh -R o/r pr ready 12'
check ask 'gh pr edit 12'
check ask 'gh pr edit 12 --base main'
check ask 'gh pr edit 12 --base=main'
check ask 'gh pr edit 12 --title x --base main'
check ask 'gh pr edit 12 --add-reviewer someone'
check ask 'gh pr edit 12 --add-label x'
check ask 'gh pr edit 12 --remove-label x'
check ask 'gh pr edit 12 --milestone m'
check ask 'gh pr edit 12 --body "inline body"'
check ask 'gh pr edit 12 -b x'
check ask 'gh pr edit 12 -t x'
check ask 'gh pr edit 12 -F b.md'
check ask 'gh pr edit 12 --tit x'
check ask 'gh pr edit 12 --body-fil b.md'
check ask 'gh pr edit 12 --title'
check ask 'gh pr edit 12 --title --base main'
check ask 'gh pr edit 12 --title x -- --base main'
check ask 'gh pr edit 12 --body-file .env'
check ask 'gh pr edit 12 --body-file -'
check ask 'gh pr edit 12 --body-file b.md.sh'
check ask 'gh pr edit 12 --body-file= --title x'
check ask "gh pr edit 12 \"--ba\"'se' main"
check ask "gh pr edit 12 '--title x --base main'"
check ask 'gh pr edit 12 --title $TITLE'
check ask "gh pr edit 12 --title \"\$(cat .env)\""
check ask 'gh pr edit 12 --title `id`'
check ask 'gh pr edit 12 --body-file {a,b}.md'
check ask 'gh pr edit 12 --body-file *.md'
check ask 'gh pr edit 12 --body-file b.md > out.txt'
check ask 'gh pr edit 12 --body-file b.md < in.txt'
check ask 'gh pr edit 12 --body-file b.md | cat'
check ask 'gh pr edit 12 --body-file b.md; gh pr merge 12'
check ask 'gh pr ready 12 && gh pr merge 12'
check ask $'gh pr ready 12\ngh pr merge 12'
check ask $'gh pr edit 12 --title x\t--base main'
check ask "gh pr edit 12 --ti\"tle\" x --ba''se main"
check pass "gh pr edit 12 \"--title\" 'x' --body-file \"b.md\""
check pass '  gh   pr  edit   12    --title   x  '
check ask 'GH_HOST=evil.example gh pr ready 12'
check ask 'GH_REPO=o/r gh pr edit 12 --title x'
check ask 'gh pr edit 12 --title x —base main'
check ask $'gh pr ready 12 \\\n&& gh pr merge 12'
check ask 'gh pr edit 12 --title x #--base main'
check ask $'gh pr edit 12 --title x\r'
check ask 'gh pr edit 12 --body-file=b.md=x'
check ask 'gh pr edit 12 --body-file ~/notes.md'
check ask 'gh pr edit 12 --body-file=~/notes.md'
check ask 'gh pr ready 12 2>&1'
check ask 'gh pr merge 12'
check ask 'gh pr create --title x'
check ask 'gh stack merge'
check ask 'gh stack unstack'
check ask 'gh stack unstack --local'
check ask 'gh stack submit --open'
check ask 'gh stack link --open a b'
check ask 'gh stack link main feat'
check ask 'gh stack link --base develop a b'
check ask 'gh api -X PUT repos/o/r/pulls/12/merge'
check ask 'gh api repos/o/r/merges -f base=main -f head=feat'
check ask 'gh api -X PATCH repos/o/r/git/refs/heads/main -f sha=abc -F force=true'
check ask "gh api graphql -f query='mutation { mergePullRequest(input:{pullRequestId:\"x\"}) { clientMutationId } }'"
check ask 'gh api -X PATCH repos/o/r -f default_branch=feat'
check ask 'gh repo sync --branch main --force'
check ask 'gh repo edit --default-branch feat'
check ask 'gh stack link "main" feat'
check ask "gh stack link 'main' feat"
check ask 'gh pr "merge" 12'
check ask 'gh api -X PUT repos/o/r/contents/README.md -f message=x -f content=eA== -f branch=main'
check ask "gh api graphql -f query='mutation { markPullRequestReadyForReview(input:{pullRequestId:\"x\"}) { clientMutationId } }'"
check ask 'gh api -XDELETE repos/o/r/git/tags/v1'
check ask 'gh api --method POST repos/o/r/pulls'
check ask 'gh api repos/o/r/pulls --input body.json'
check ask 'git -c alias.up=push up origin feat'
check ask 'git config alias.up "push --force"'
check ask 'git config --global alias.up push'
check ask 'git send-pack origin feat:main'
check ask "gh alias set up 'pr merge'"
check ask 'gh alias import aliases.yml'
check ask 'gh pr create --title "fix -d flag" --body y'
check ask 'gh pr create --draft --title a && gh pr create --title b'
check ask 'gh pr create --draft --title x; echo done'
check ask 'gh pr create --draft --title x | cat'
check ask 'echo $(gh pr create --title x) --draft'
check ask 'git push origin feat' notrepo
check ask 'git push origin feat' missing
check ask $'gh pr \\\nmerge 12 --squash'
check ask $'git \\\npush origin main'
check ask $'gh stack \\\nmerge'
check ask $'gh pr \\\nready 12'
check ask $'git push \\\norigin feat'
check ask 'gh api repos/o/r/pulls -ftitle=x -fhead=feat -fbase=main'
check ask 'gh api repos/o/r/pulls -fkey=val'
check ask 'gh api repos/o/r/pulls -ifkey=val'
check ask 'gh api repos/o/r/pulls -Fdraft=false -fhead=feat'
check ask 'gh api repos/o/r/branches/main/rename -fnew_name=trunk'
check ask 'gh pr create --draft --title x --draft=false'
check ask 'gh pr create -d --title x -d=false'
check ask 'git $p origin main'
check ask 'gh pr $(echo merge) 12'
check ask 'gh `echo pr` merge 1'
check ask 'gh pr -R o/r merge 5 --admin'
check ask 'gh -R o/r pr merge 5'
check ask 'gh api -iXDELETE repos/o/r'
check ask 'gh api -iX DELETE repos/o/r'
check ask 'gh pr new --title x'
check ask 'gh pr create --title --draft --body y'
check ask 'gh pr {merge,} 12'
check ask 'git {push,} origin main'
check ask 'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=alias.up GIT_CONFIG_VALUE_0=push git up origin main'
check ask $'GIT_CONFIG_PARAMETERS="\'alias.up=push\'" git up origin main'
check ask 'git push origin alias' symref
check ask 'git commit -m "mention gh pr merge"'
check ask 'bash -c "gh pr merge 12"'
check ask 'echo 12 | xargs gh pr merge'
check ask 'GH_REPO=o/r gh pr view 12'
check ask $'gh pr view 12\ngh pr merge 12'
check ask 'gh pr view 12 | sh'
check ask 'gh pr view 12 --json title | jq .title | sh'
check ask 'gh pr view 12 | rg --pre sh x'
check ask 'gh pr close 12'
check ask 'gh repo delete o/r --yes'
check ask 'gh secret set X'
check ask 'gh workflow run deploy'
check ask 'gh release create v1'
check ask 'gh api graphql'
check ask 'gh api repos/o/r/pulls/12 --jq=.title'
check ask 'gh api repos/o/r -H X-HTTP-Method-Override:DELETE'
check ask 'gh stack checkout main'
check ask 'gh auth status --show-token'
check ask 'gh auth status -t'
check ask 'gh auth status -at'
check ask 'gh pr view 1 >(gh pr merge 1 --admin)'
check ask 'gh pr view <(gh pr merge 1 --admin)'
check ask 'gh stack push >(gh pr ready 3)'
check ask 'gh stack push >(gh stack merge)'
check ask 'gh pr view 1 | jq . >(gh pr ready 3)'
check ask $'gh pr create --draft --title \'a"\' --draft=false --body \'"b\''
check ask 'gh pr create --draft --title "a\"" --draft=false --body "\"b"'
check ask 'git -c k=v push origin feat'
check ask 'git --git-dir=x push origin feat'
check ask 'git --git-dir x push origin main'
check ask 'git --work-tree x push origin main'
check ask 'GIT push --force origin main'
check ask 'Git push origin main'
check ask 'GH pr merge 1 --admin'
check ask 'Gh api -XPUT repos/o/r/pulls/1/merge'
check ask 'GIT push origin feat'
check ask 'git -c "user.name=a b" push --force origin main'
check ask "git -c 'core.x=1 2' push -f origin HEAD:main"
check ask 'git --config-env "a.b=X Y" push origin main'
check ask 'git -C "a b" push origin main'
check ask 'bash -c "git push origin main"'
check ask "gh api repos/o/r/pulls/1/merge --jq '' -XPUT"
check ask 'gh api repos/o/r/pulls/1/merge --jq "" --method=PUT'
check ask "gh api repos/o/r/pulls/1/merge --cache '' -XPUT"
check ask 'gh api repos/o/r/pulls/1/merge -H -XPUT'
check ask 'gh api repos/o/r/pulls/1/merge --cache -XPUT'
check ask '/usr/libexec/git-core/git-push origin main'
check ask 'git-push origin main'
check ask 'git-send-pack origin feat:main'
check ask '/usr/libexec/git-core/git-receive-pack .'
check ask 'gh pr create --draft -fd=false --title x'
check ask 'gh pr create --draft -ed=false --title x'
check pass 'git stash push -u -m wip'
check pass 'git log --grep push'
check pass 'git commit -m "ask before push to main"'
check ask 'gh auth status --show-token=true'

check pass 'git push origin feat'
check pass 'git push -u origin jsh-50-quiet-publishing-hook'
check pass 'git push --set-upstream origin jsh-50/a.b_c'
check pass 'git push origin maintenance'
check pass 'git push origin feature/main'
check pass 'git push -u origin jsh-51-maintenance'
check pass 'gh pr create --draft --title x --body y'
check pass 'gh pr create -d --title x'
check pass 'gh stack push'
check pass 'gh stack submit --auto'
check pass 'gh stack sync'
check pass 'gh stack link a b'
check pass 'git status'
check pass 'gh pr view 12'
check pass 'gh api repos/o/r/pulls/12'
check pass 'gh api -X GET repos/o/r/pulls/12'
check pass 'gh api -H Accept:x repos/o/r/pulls/12'
check pass $'git commit -m "$(cat <<\'EOF\'\n[JSH-50] fix(hooks): x\n\nbody\nEOF\n)"'
check pass 'gh pr view 12 --json title | jq .title'
check pass 'gh api repos/o/r/pulls/12 --jq .title'
check pass 'gh api repos/doghead-digital-web/jshq --jq .allow_squash_merge'
check ask "gh api repos/doghead-digital-web/jshq --jq '{squash: .allow_squash_merge}'"
check ask 'gh api -X PATCH repos/doghead-digital-web/jshq'
check pass 'gh pr create --draft --title x --body-file b.md --base main'
check pass 'gh pr checks 12'
check pass 'gh run list'
check pass 'gh api --paginate -H Accept:x --cache 1h repos/o/r/pulls'
check pass 'gh stack view'
check pass 'gh auth status'
check pass 'gh auth status -h github.com'
check pass 'git push origin feat' symref
check pass 'gh pr create --draft --title "fix the -x flag" --body y'
check pass 'git log --oneline main'
check pass 'git rebase main'

check pass 'gh repo view --json visibility; git branch -r | rg feat-x'
check pass 'gh pr view 12 && git status'
check pass 'gh pr view 12 || git log --oneline -5'
check pass 'gh pr view 12 --json title | jq .title | head -1'
check pass 'gh pr view 12;git status'
check pass 'git status; gh pr status; git rev-parse HEAD'
check pass 'gh api repos/o/r/pulls/12 --jq .title && git fetch --prune origin && git diff origin/main --stat'
check pass "git branch --list 'feat-*'; gh stack view"
check pass "gh pr diff 12 | rg -n 'a|b; c' | wc -l"
check pass 'git merge-tree --write-tree main feat; gh pr checks 12'
check pass 'cat README.md; ls -la; date; tail -n 5 log.txt; gh run list'
check pass 'git show HEAD --stat; gh issue view 3 | grep -c x'
check pass 'GH pr view 12; git status'
check pass 'gh api -X GET repos/o/r/pulls/12; git log -1 --format="%h %s"'
check pass 'gh stack view --json | head -50'
check deny 'gh stack view --json 2>&1 | head -50'
check deny 'git status --short && git branch --show-current && gh stack view --json 2>&1 | head -50'
check deny 'gh pr view 12 2>&1'
check deny 'gh pr view 12 >/dev/null 2>&1; git status'
check pass 'gh pr view 12 2>/dev/null'
check pass 'gh stack view 2> /dev/null'
check pass 'gh stack view > out.txt'
check ask 'gh pr view 12 2>&1; gh pr merge 12'
check ask 'gh pr view 12 2>&1 > out.txt; git status'
check ask 'gh stack view 2>&1 | sh'
check ask 'gh pr view 12; gh pr merge 12'
check ask 'gh pr view 12 && git push origin main'
check ask 'gh pr view 12 && gh pr ready 12'
check ask 'gh pr view 12; gh pr create --draft --title x'
check ask 'gh pr view 12; gh auth status -t'
check ask 'gh pr view 12; gh api -X PATCH repos/o/r'
check ask 'gh pr view 12; gh stack push'
check ask 'gh pr view 12; echo done'
check ask 'gh pr view 12; ./merge.sh'
check ask 'gh pr view 12; git commit -m x'
check ask 'gh pr view 12; git -C ../main status'
check ask 'gh pr view 12; git branch feat'
check ask 'gh pr view 12; git branch -D feat'
check ask 'gh pr view 12; git branch -r -d origin/feat'
check ask 'gh pr view 12; git log -1 --output=x.txt'
check ask 'gh pr view 12; git fetch --upl=./x.sh .'
check ask 'gh pr view 12; git fetch --upload-pack=./x.sh .'
check ask 'gh pr view 12; git status | rg --pre sh x'
check ask "gh pr view 12; rg --p''re=sh x"
check ask 'gh pr view 12; rg --hostname-bin=./x.sh --hyperlink-format=default x'
check ask 'gh pr view 12 | rg --hostname-bin=./x.sh --hyperlink-format=default x'
check ask "gh pr view 12 | rg --p''re=sh x"
check ask 'gh pr view 12; GH_REPO=o/r gh pr view 1'
check ask 'gh pr view 12; gh "pr merge" 12'
check ask 'gh pr view 12; echo $(gh pr merge 12)'
check ask 'gh pr view 12; git log `gh pr merge 12`'
check ask 'gh pr view 12; { gh pr merge 12; }'
check ask 'gh pr view 12; (gh pr merge 12)'
check ask 'gh pr view 12 > out.txt; git status'
check deny 'gh pr view 12; git status 2>/dev/null'
check ask 'gh pr view 12 < in.txt; git status'
check ask $'gh pr view 12\ngit status'
check ask $'gh pr view 12; \\\ngit status'
check ask 'gh pr view 12 & gh pr merge 12'
check ask 'gh pr view 12 |& cat'
check ask 'gh pr view 12;'
check ask 'gh pr view 12; ; git status'
check ask 'gh pr view 12; rg x *'
check ask "gh pr view 12; rg 'x\$' f"
check ask "gh pr view 12; rg 'x"
check ask "gh pr view 12; gh api repos/o/r --jq '{a: .b}'"
check ask $'gh pr view 12; rg \'a\nb\' f'
check ask 'gh pr view 12 # ; git status'

[[ -z "$SETTINGS" ]] || check_store

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[[ "$fail" == 0 ]]
