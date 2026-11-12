#!/usr/bin/env bash
# Exercises scripts/action/publish.sh against a temporary bare repository standing in for GitHub.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
publish=$root/scripts/action/publish.sh
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# Isolate from the developer's Git configuration (signing, hooks, default branch).
export GIT_CONFIG_GLOBAL=$tmp/gitconfig GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.com GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.com
touch "$GIT_CONFIG_GLOBAL"

failures=0
check() {
    if [[ $2 == "$3" ]]; then
        echo "ok - $1"
    else
        echo "not ok - $1: expected '$3', got '$2'"
        failures=$((failures + 1))
    fi
}

remote=$tmp/remote.git
git init --quiet --bare -b main "$remote"
git clone --quiet "$remote" "$tmp/seed" 2>/dev/null
echo '# Profile' > "$tmp/seed/README.md"
git -C "$tmp/seed" add README.md
git -C "$tmp/seed" commit --quiet -m 'Initial commit'
git -C "$tmp/seed" push --quiet origin main
git clone --quiet --depth=1 "file://$remote" "$tmp/work"

printf 'first image' > "$tmp/one.png"
printf 'second image' > "$tmp/two.png"
main_before=$(git -C "$remote" rev-parse main)
printf '<svg>first</svg>' > "$tmp/one.svg"
printf '<svg>second</svg>' > "$tmp/two.svg"

run() {
    GC_REPO=$tmp/work GC_IMAGE=$1 GC_BRANCH=$2 GC_PATH=${3:-constellation.png} bash "$publish" >/dev/null
}
remote_git() {
    git -C "$remote" "$@"
}

run "$tmp/one.png" constellation
first=$(remote_git rev-parse constellation)
check 'orphan branch has one commit' "$(remote_git rev-list --count constellation)" 1
check 'orphan branch holds the image' "$(remote_git show constellation:constellation.png)" 'first image'
check 'orphan branch holds only the image and README' "$(remote_git ls-tree --name-only constellation | tr '\n' ' ')" 'README.md constellation.png '
check 'commits are authored by the Actions bot' "$(remote_git log -1 --format='%an <%ae>' constellation)" \
    'github-actions[bot] <41898099+github-actions[bot]@users.noreply.github.com>'

run "$tmp/two.png" constellation
check 'orphan mode replaces the single commit' "$(remote_git rev-list --count constellation)" 1
check 'orphan branch holds the new image' "$(remote_git show constellation:constellation.png)" 'second image'
second=$(remote_git rev-parse constellation)
[[ $second != "$first" ]] && check 'orphan commit changed' yes yes || check 'orphan commit changed' no yes

run "$tmp/two.png" constellation
check 'orphan mode no-ops when the image is unchanged' "$(remote_git rev-parse constellation)" "$second"
check 'orphan mode leaves main untouched' "$(remote_git rev-parse main)" "$main_before"

run "$tmp/one.png" main images/constellation.png
check 'default-branch mode adds one commit' "$(remote_git rev-list --count main)" 2
check 'default-branch mode keeps existing files' "$(remote_git show main:README.md)" '# Profile'
check 'default-branch mode writes the image' "$(remote_git show main:images/constellation.png)" 'first image'
after_first=$(remote_git rev-parse main)

run "$tmp/one.png" main images/constellation.png
check 'default-branch mode no-ops when unchanged' "$(remote_git rev-parse main)" "$after_first"

run "$tmp/two.png" main images/constellation.png
check 'default-branch mode commits a changed image' "$(remote_git rev-list --count main)" 3

run_animated() {
    GC_REPO=$tmp/work GC_IMAGE=$1 GC_ANIMATED=$2 GC_BRANCH=$3 GC_PATH=${4:-constellation.png} bash "$publish" >/dev/null
}

check 'without GC_ANIMATED the branch holds no SVG' "$(remote_git ls-tree --name-only constellation | grep -c '\.svg$' || true)" 0

run_animated "$tmp/one.png" "$tmp/one.svg" constellation
animated_first=$(remote_git rev-parse constellation)
check 'animated orphan branch holds PNG, SVG and README' "$(remote_git ls-tree --name-only constellation | tr '\n' ' ')" 'README.md constellation.png constellation.svg '
check 'animated orphan branch still has one commit' "$(remote_git rev-list --count constellation)" 1
check 'animated orphan branch holds the SVG' "$(remote_git show constellation:constellation.svg)" '<svg>first</svg>'

run_animated "$tmp/one.png" "$tmp/one.svg" constellation
check 'orphan mode no-ops when the pair is unchanged' "$(remote_git rev-parse constellation)" "$animated_first"

run_animated "$tmp/one.png" "$tmp/two.svg" constellation
check 'orphan mode publishes when only the SVG changed' "$(remote_git show constellation:constellation.svg)" '<svg>second</svg>'
check 'orphan mode keeps the PNG when only the SVG changed' "$(remote_git show constellation:constellation.png)" 'first image'
check 'orphan mode keeps one commit after an SVG-only change' "$(remote_git rev-list --count constellation)" 1

run_animated "$tmp/one.png" "$tmp/one.svg" main images/constellation.png
check 'default-branch mode commits both files' "$(remote_git show main:images/constellation.svg)" '<svg>first</svg>'
check 'default-branch mode keeps the PNG next to the SVG' "$(remote_git show main:images/constellation.png)" 'first image'
check 'default-branch mode adds one commit for the pair' "$(remote_git rev-list --count main)" 4
after_animated=$(remote_git rev-parse main)

run_animated "$tmp/one.png" "$tmp/one.svg" main images/constellation.png
check 'default-branch mode skips when both files are unchanged' "$(remote_git rev-parse main)" "$after_animated"

run_animated "$tmp/one.png" "$tmp/two.svg" main images/constellation.png
check 'default-branch mode commits an SVG-only change' "$(remote_git rev-list --count main)" 5

before_missing=$(remote_git rev-parse constellation)
if missing_error=$(GC_REPO=$tmp/work GC_IMAGE=$tmp/one.png GC_ANIMATED=$tmp/missing.svg bash "$publish" 2>&1 >/dev/null); then
    check 'fails when the animation is missing' published failed
else
    check 'fails when the animation is missing' failed failed
fi
check 'missing animation error names the file' "$missing_error" "Error: Rendered animation not found: $tmp/missing.svg."
check 'missing animation leaves the branch untouched' "$(remote_git rev-parse constellation)" "$before_missing"

for bad in ../escape.png /abs.png .git/x.png image.jpg; do
    if run "$tmp/one.png" constellation "$bad" 2>/dev/null; then
        check "rejects path $bad" accepted rejected
    else
        check "rejects path $bad" rejected rejected
    fi
done
if GC_REPO=$tmp/work GC_IMAGE=$tmp/missing.png bash "$publish" >/dev/null 2>&1; then
    check 'fails without an image' published failed
else
    check 'fails without an image' failed failed
fi
git -C "$tmp/seed" switch --quiet -c notes
echo 'keep me' > "$tmp/seed/notes.txt"
git -C "$tmp/seed" add notes.txt
git -C "$tmp/seed" commit --quiet -m 'Notes'
git -C "$tmp/seed" push --quiet origin notes
git -C "$tmp/seed" switch --quiet --orphan bare-orphan
echo 'other' > "$tmp/seed/README.md"
git -C "$tmp/seed" add README.md
git -C "$tmp/seed" commit --quiet -m 'Foreign orphan'
git -C "$tmp/seed" push --quiet origin bare-orphan
for foreign in notes bare-orphan; do
    before=$(remote_git rev-parse "$foreign")
    if run "$tmp/two.png" "$foreign" 2>/dev/null; then
        check "refuses to overwrite foreign branch $foreign" overwritten refused
    else
        check "refuses to overwrite foreign branch $foreign" refused refused
    fi
    check "foreign branch $foreign is untouched" "$(remote_git rev-parse "$foreign")" "$before"
done

output=$(GC_TOKEN=secret-token-value GC_REPO=$tmp/work GC_IMAGE=$tmp/one.png bash "$publish" 2>&1)
check 'publishes with a token supplied through the environment' "$(remote_git show constellation:constellation.png)" 'first image'
encoded=$(printf 'x-access-token:secret-token-value' | base64)
if printf '%s\n' "$output" | cat - "$tmp/work/.git/config" | grep -qF -e secret-token-value -e "$encoded"; then
    check 'token never appears in output or git config' leaked hidden
else
    check 'token never appears in output or git config' hidden hidden
fi
second=$(remote_git rev-parse constellation)

check 'the checked-out working tree is untouched' "$(git -C "$tmp/work" status --porcelain)" ''
check 'failed runs leave the published image untouched' "$(remote_git rev-parse constellation)" "$second"

if ((failures > 0)); then
    echo "$failures publish test(s) failed" >&2
    exit 1
fi
