#!/usr/bin/env bash
#
# Formats and analyses one PHP file after an AI coding agent has edited it.
#
# Registered in .claude/settings.json as a PostToolUse hook for the Edit and
# Write tools. The agent passes the tool call as JSON on stdin; the edited
# file is at tool_input.file_path.
#
# The script acts only on a regular file that ends in .php and is under src/,
# tests/ or examples/ of this repository. It does not follow a symbolic link,
# because PHP-CS-Fixer rewrites the file and the link's target can be anywhere.
# For that file it runs PHP-CS-Fixer, then, under src/ or tests/, PHPStan with
# the repository's configuration. A file under examples/ is formatted only:
# phpstan.neon does not analyse examples/, so the gate does not hold the
# example scripts to level 8 and the hook must not either.
#
# Exit status:
#   0  nothing to do, or the file is clean. Nothing is printed.
#   2  PHPStan reported errors. They are printed on stderr, which the agent
#      is shown, so it can correct the file.
#
# It never blocks work it cannot check: unreadable input, a path that holds a
# control character, a path outside the three directories, a missing file, a
# symbolic link, a missing tool (PHP, PHP-CS-Fixer, PHPStan), or a PHPStan run
# that reports no error for the file (phpstan.neon excludes tests/Pest.php,
# for example) all exit 0 in silence.
# It checks one file only; `composer ci` is the gate.
#
# The JSON is read with PHP, which the project needs anyway, so that no other
# tool (jq, for example) has to be installed. The path it holds is only ever
# used as one quoted argument; nothing from the JSON is evaluated.
#
# Tested by tests/Feature/AgentHookTest.php.

set -u

# With CDPATH set, `cd` with a relative path can go to another directory and
# print its name. The script is started with a relative path when it is run by
# hand from the repository root.
unset CDPATH

command -v php > /dev/null 2>&1 || exit 0

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2> /dev/null && pwd -P)" || exit 0

# Prints tool_input.file_path, or nothing when the input is not a tool call.
# A path with a control character prints nothing as well: the shell drops a
# NUL byte and a trailing newline from the output, so the script would act on
# a different file from the one the tool call names.
file="$(php -r '
    $call = json_decode((string) stream_get_contents(STDIN), true);
    $path = is_array($call) ? ($call["tool_input"]["file_path"] ?? null) : null;
    if (is_string($path) && preg_match("/[\\x00-\\x1f\\x7f]/", $path) !== 1) {
        echo $path;
    }
' 2> /dev/null)" || exit 0

case "$file" in
    *.php) ;;
    *) exit 0 ;;
esac

# A relative path is relative to the repository root.
case "$file" in
    /*) ;;
    *) file="$root/$file" ;;
esac

[ -f "$file" ] || exit 0

# Resolve the directory, so that neither `..` nor a symbolic link can make a
# file elsewhere look as if it were inside one of the three directories.
directory="$(cd "$(dirname "$file")" 2> /dev/null && pwd -P)" || exit 0
file="$directory/$(basename "$file")"

# The directory is resolved now; the file itself can still be a link.
[ -L "$file" ] && exit 0
[ -f "$file" ] || exit 0

case "$file" in
    "$root"/src/* | "$root"/tests/*) analyse=yes ;;
    "$root"/examples/*) analyse=no ;;
    *) exit 0 ;;
esac

[ -x "$root/vendor/bin/php-cs-fixer" ] || exit 0
[ -x "$root/vendor/bin/phpstan" ] || exit 0

cd "$root" || exit 0

# A file PHP-CS-Fixer cannot format (a syntax error, for example) is left as
# it is; PHPStan reports the cause below.
vendor/bin/php-cs-fixer fix --quiet -- "$file" > /dev/null 2>&1

[ "$analyse" = yes ] || exit 0

# The raw format prints one line per error on stdout. PHPStan's own messages
# (the configuration note, "No files found to analyse" for a file that
# phpstan.neon excludes, a crash) go to stderr and are not the file's errors,
# so they are dropped: a failure with nothing on stdout is not reported.
report="$(vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=512M -- "$file" 2> /dev/null)" && exit 0

[ -n "$report" ] || exit 0

printf '%s\n' "$report" >&2

exit 2
