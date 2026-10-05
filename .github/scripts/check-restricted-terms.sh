#!/usr/bin/env bash
#
# Fails when a tracked file mentions a term from the maintainers' restricted
# list. Run from inside the repository to check.
#
# RESTRICTED_TERMS holds the list as a case-insensitive POSIX extended regular
# expression; several lines are alternatives. It is a secret, so this script
# must never disclose it or the text it matched:
#
#   - Only paths are printed, and a path that itself matches is counted rather
#     than printed.
#   - git's own diagnostics quote the expression, so they are discarded.
#
# What is checked: the content of every tracked file (binary files included),
# the target of every tracked symbolic link, and every tracked path.
# What is not: commit messages, history, and anything outside the tree.
#
# Exit status: 0 clean, or nothing configured (pull requests from forks and
# from Dependabot receive no secrets); 1 a restricted term was found;
# 2 the expression is unusable (invalid, or it matches every line).
#
# Covered by tests/Feature/RestrictedTermsCheckTest.php.

set -u

# Escaping required by workflow commands, so that a hostile path cannot end
# the annotation early or smuggle in properties of its own.
escape_data() {
  local value=$1
  value=${value//\%/%25}
  value=${value//$'\r'/%0D}
  value=${value//$'\n'/%0A}
  printf '%s' "$value"
}

escape_property() {
  local value
  value=$(escape_data "$1")
  value=${value//:/%3A}
  value=${value//,/%2C}
  printf '%s' "$value"
}

# A secret pasted from a file often carries CRLF line endings or a trailing
# newline. A stray carriage return would stop every term from matching and an
# empty line would match everything, so both are dropped.
pattern=$(printf '%s' "${RESTRICTED_TERMS:-}" | tr -d '\r' | grep -v '^[[:space:]]*$' || true)

if [ -z "$pattern" ]; then
  echo "::notice::No restricted terms configured; skipping."
  exit 0
fi

root=$(git rev-parse --show-toplevel 2>/dev/null) || {
  echo "::error::Restricted terms check must run inside a git work tree."
  exit 2
}
cd "$root" || exit 2

work=$(mktemp -d) || exit 2
trap 'rm -rf "$work"' EXIT

# Prints the numbers of the lines of a file in the scratch directory that
# match the expression. The same git grep engine is used for paths as for
# content so the two can never disagree about what a term matches.
matching_lines() {
  (cd "$work" && git grep --no-index -h -n -a -i -E -e "$pattern" -- "$1" 2>/dev/null) | cut -d: -f1
}

# Paths go one per line; a newline inside a path becomes a space.
append_line() {
  printf '%s\n' "${2//$'\n'/ }" >>"$work/$1"
}

printf '\n' >"$work/empty"
(cd "$work" && git grep --no-index -q -a -i -E -e "$pattern" -- empty 2>/dev/null)
case $? in
  1) ;;
  0)
    echo "::error::RESTRICTED_TERMS matches an empty line, so it would flag every file. Fix the secret."
    exit 2
    ;;
  *)
    echo "::error::RESTRICTED_TERMS is not a valid extended regular expression. Fix the secret."
    exit 2
    ;;
esac

# Tracked paths that themselves contain a term.
: >"$work/paths"
while IFS= read -r -d '' path; do
  append_line paths "$path"
done < <(git ls-files -z)
withheld=$(matching_lines paths | wc -l | tr -d ' ')

# Tracked files whose content contains a term. -a searches binary files too:
# without it a single NUL byte would hide a file from the check.
git grep -l -z -a -i -E -e "$pattern" >"$work/content" 2>/dev/null
status=$?
if [ "$status" -gt 1 ]; then
  echo "::error::Restricted terms check could not search the tracked files."
  exit 2
fi

candidates=()
while IFS= read -r -d '' path; do
  candidates+=("$path")
done <"$work/content"

# git grep skips symbolic links, so their targets are checked separately.
links=()
: >"$work/targets"
while IFS= read -r -d '' entry; do
  case $entry in
    120000\ *)
      path=${entry#*$'\t'}
      links+=("$path")
      append_line targets "$(readlink "$path" 2>/dev/null)"
      ;;
  esac
done < <(git ls-files -s -z)
for number in $(matching_lines targets); do
  candidates+=("${links[number - 1]}")
done

# Name each offending file unless its path would itself disclose a term.
: >"$work/candidates"
for path in ${candidates[@]+"${candidates[@]}"}; do
  append_line candidates "$path"
done
unsafe=" $(matching_lines candidates | tr '\n' ' ')"

found=0
index=0
for path in ${candidates[@]+"${candidates[@]}"}; do
  index=$((index + 1))
  found=1
  case $unsafe in
    *" $index "*) continue ;;
  esac
  echo "::error file=$(escape_property "$path")::Restricted term found in $(escape_data "$path")"
done

if [ "$withheld" -gt 0 ]; then
  found=1
  echo "::error::Restricted term found in the path of $withheld tracked file(s). The paths are withheld because printing them would disclose the term."
fi

exit "$found"
