#!/usr/bin/env bash
#
# Formats and analyses one PHP file after an AI coding agent has edited it.
#
# Registered in .claude/settings.json as a PostToolUse hook for the Edit and
# Write tools. The agent passes the tool call as JSON on stdin; the edited
# file is at tool_input.file_path.
#
# The script acts only on a file that exists, ends in .php and is under src/,
# tests/ or examples/ of this repository. For that file it runs PHP-CS-Fixer,
# then PHPStan with the repository's configuration.
#
# Exit status:
#   0  nothing to do, or the file is clean. Nothing is printed.
#   2  PHPStan reported errors. They are printed on stderr, which the agent
#      is shown, so it can correct the file.
#
# It never blocks work it cannot check: unreadable input, a path outside the
# three directories, a missing file, or a missing tool (PHP, PHP-CS-Fixer,
# PHPStan) all exit 0 in silence. It checks one file only; `composer ci` is
# the gate.
#
# The JSON is read with PHP, which the project needs anyway, so that no other
# tool (jq, for example) has to be installed.
#
# Tested by tests/Feature/AgentHookTest.php.

set -u

command -v php > /dev/null 2>&1 || exit 0

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2> /dev/null && pwd -P)" || exit 0

# Prints tool_input.file_path, or nothing when the input is not a tool call.
file="$(php -r '
    $call = json_decode((string) stream_get_contents(STDIN), true);
    $path = is_array($call) ? ($call["tool_input"]["file_path"] ?? null) : null;
    if (is_string($path)) {
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

case "$file" in
    "$root"/src/* | "$root"/tests/* | "$root"/examples/*) ;;
    *) exit 0 ;;
esac

[ -x "$root/vendor/bin/php-cs-fixer" ] || exit 0
[ -x "$root/vendor/bin/phpstan" ] || exit 0

cd "$root" || exit 0

# A file PHP-CS-Fixer cannot format (a syntax error, for example) is left as
# it is; PHPStan reports the cause below.
vendor/bin/php-cs-fixer fix --quiet "$file" > /dev/null 2>&1

if ! report="$(vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=512M "$file" 2>&1)"; then
    printf '%s\n' "$report" >&2
    exit 2
fi

exit 0
