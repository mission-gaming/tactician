<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

/**
 * The memory limit of the test process of a mutation, and the record of the
 * mutations that ended at it.
 *
 * A mutation can remove the one thing that keeps a loop finite: a counter
 * that now runs the wrong way, a bound that is no longer tested. When that
 * loop adds to an array on every pass, the tests of the mutation allocate
 * until something stops them. Pest's runner stops a test process at a time
 * limit (the duration of the first, unmutated run of the tests and a fifth
 * more), and a process can take all the memory of the machine well inside
 * that time. On a CI runner that ends the whole job, with no summary.
 *
 * So every test process of a mutation gets a memory limit. A process that
 * reaches it ends with PHP's fatal error; the runner sees a failed test
 * process, counts the mutation as tested and goes on to the next. No test
 * failed on such a mutation, so the process writes one line to a log before
 * it ends, and the summary ({@see MutationReport}) counts the mutations
 * those lines name and says so.
 *
 * `tests/mutation-memory.php` installs it, and PHP itself runs that file
 * before the script of the process (`auto_prepend_file`). It has to run that
 * early, and not from a PHPUnit bootstrap or `tests/Pest.php`: the line is
 * written by a shutdown function, PHP runs shutdown functions in the order
 * they were registered, and Pest registers one of its own as soon as it
 * starts, which ends the process on a fatal error and so keeps every later
 * one from running. PHP's own error log cannot stand in for the line either:
 * PHPUnit points `error_log` at a temporary file of its own for as long as a
 * test runs, which is when a mutation runs out of memory.
 *
 * `composer mutation` is what makes PHP run the file. Its script,
 * `tests/bin/run-mutation.php`, writes a one-line configuration file that
 * names it ({@see self::scanDirectories()}) and starts the run with the
 * directory of that file added to `PHP_INI_SCAN_DIR`, which every process of
 * the run inherits. Nothing else loads the file: not Composer's autoloader,
 * so not an ordinary test run, a static analysis or a project that installs
 * the package. A mutation run that is started in another way
 * (`vendor/bin/pest --mutate`) has no limit and writes no log, and the
 * summary of such a run says so.
 */
final class MutationMemoryLimit
{
    /**
     * The limit of one test process. The tests `composer mutation` runs need
     * well under a tenth of it, and four processes at the limit, which is
     * what a standard CI runner starts, take a quarter of its memory.
     */
    public const string DEFAULT = '1G';

    /** The environment variable that replaces {@see self::DEFAULT}, in PHP's notation ("512M", "2G"). */
    public const string ENV_LIMIT = 'TACTICIAN_MUTATION_MEMORY_LIMIT';

    /** The log, relative to the root of the repository; `build/` is not tracked. */
    public const string LOG = 'build/mutation-memory.log';

    /** The environment variable that names another file for the log. */
    public const string ENV_LOG = 'TACTICIAN_MUTATION_MEMORY_LOG';

    /**
     * The environment variable that holds the name of one test run of one
     * mutation. The first process of that run makes the name up and every
     * process it starts inherits it, so that the lines of one mutation can
     * be told from the lines of another ({@see self::read()}). Nobody sets
     * it by hand.
     */
    public const string ENV_RUN = 'TACTICIAN_MUTATION_RUN';

    /**
     * Where `composer mutation` writes the configuration file that makes
     * PHP run `tests/mutation-memory.php`, relative to the root of the
     * repository.
     */
    public const string INI_DIRECTORY = 'build/mutation-ini';

    /** How PHP's message for an allocation over the limit begins. */
    private const string EXHAUSTED = 'Allowed memory size of ';

    /**
     * Does one of three things, by the process it is called in.
     *
     * - The test process of a mutation: sets the limit, and arranges for the
     *   line to be written if the process ends at it. Pest's runner tells a
     *   test process which mutation it runs through two environment
     *   variables: the source file that is mutated, and the file that holds
     *   the mutated source, whose name is a hash of that source.
     * - The process that starts a mutation run, which is the one given
     *   `--mutate`: starts the log afresh, as an empty file, so that the log
     *   holds one run and its being there says that the run had the limit.
     *   Its own memory is not limited: it holds the coverage of the whole
     *   first run of the tests.
     * - Any other process of the run (a worker of the first run of the
     *   tests): nothing.
     *
     * @param string $root The root of the repository
     * @param list<string> $arguments The arguments of the process
     */
    public static function install(string $root, array $arguments): void
    {
        $log = getenv(self::ENV_LOG);
        if ($log === false || $log === '') {
            $log = $root . '/' . self::LOG;
        }
        $mutated = getenv('PEST_MUTATION_TESTING');

        if ($mutated === false) {
            if (in_array('--mutate', $arguments, true)) {
                if (!is_dir(dirname($log))) {
                    mkdir(dirname($log), 0o777, true);
                }
                file_put_contents($log, '');
            }

            return;
        }

        $limit = getenv(self::ENV_LIMIT);
        ini_set('memory_limit', $limit === false || $limit === '' ? self::DEFAULT : $limit);

        $source = str_starts_with($mutated, $root . '/') ? substr($mutated, strlen($root) + 1) : $mutated;
        $mutant = basename((string) getenv('PEST_MUTATION_FILE'));

        // The runner starts one process for a mutation, which runs the
        // tests itself. A test can start processes of its own, and a run
        // that is given workers by hand has those: this is the first
        // process of the run when the name is not there yet, and the others
        // inherit it. `putenv` is for a process started with the
        // environment as it is; the two arrays are for Symfony's Process,
        // which passes on only the variables it finds there
        $run = getenv(self::ENV_RUN);
        if ($run === false || $run === '') {
            $run = getmypid() . '.' . hrtime(true);
            putenv(self::ENV_RUN . '=' . $run);
            $_SERVER[self::ENV_RUN] = $run;
            $_ENV[self::ENV_RUN] = $run;
        }

        // Held until the process ends and released first thing then: the
        // process is at its limit, and writing the line needs a little memory
        $reserve = str_repeat(' ', 262144);

        register_shutdown_function(static function () use (&$reserve, $log, $source, $mutant, $run): void {
            $reserve = null;

            $error = error_get_last();
            if ($error === null || !str_starts_with($error['message'], self::EXHAUSTED)) {
                return;
            }

            if (!is_dir(dirname($log))) {
                // Another process may make it between the test and the call
                @mkdir(dirname($log), 0o777, true);
            }
            file_put_contents($log, "{$source}\t{$mutant}\t{$run}\n", FILE_APPEND | LOCK_EX);
        });
    }

    /**
     * Writes the configuration file that makes PHP run
     * `tests/mutation-memory.php` before the script of a process, and
     * returns the value of `PHP_INI_SCAN_DIR` under which PHP reads it: what
     * the caller had there, and the directory of the file after it.
     *
     * The variable is a list of directories, and an empty entry stands for
     * the directory PHP was built with. A caller that has not set it gets
     * that directory and this one; a caller that set it keeps every entry;
     * a caller that set it to nothing, which turns the built-in directory
     * off, gets this one alone.
     *
     * @param string $root The root of the repository
     * @param string $directory Where to write the file; made if it is not there
     * @param string|false $callers The caller's `PHP_INI_SCAN_DIR`, false when it has none
     *
     * @return string|null Null when the file cannot be written or named (a quote in the path, or the
     *     separator of the list): the run then goes on without the limit
     */
    public static function scanDirectories(string $root, string $directory, string|false $callers): ?string
    {
        $prepended = $root . '/tests/mutation-memory.php';

        if (str_contains($prepended, '"') || str_contains($directory, PATH_SEPARATOR)) {
            return null;
        }

        // Asked first, so that what cannot be done is not tried: a warning
        // here would be the first thing a run prints
        $existing = $directory;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            return null;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        if (file_put_contents($directory . '/mutation-memory.ini', "auto_prepend_file=\"{$prepended}\"\n") === false) {
            return null;
        }

        return match ($callers) {
            false => PATH_SEPARATOR . $directory,
            '' => $directory,
            default => $callers . PATH_SEPARATOR . $directory,
        };
    }

    /**
     * The mutations a log names, by source file. A log is one line for each
     * process that ended at the limit: the source file, the name of the
     * mutated copy and the name of the test run ({@see self::ENV_RUN}), with
     * tabs between them.
     *
     * The runner starts one process for a mutation, so a mutation is one
     * line as a rule. Where a test started a process of its own that ended
     * at the limit too, the mutation is still counted once: its lines are
     * the ones with one copy and one run. The copy alone does not name a
     * mutation. Its name is a hash of the mutated source, and two mutators
     * can write the same source (in a run of 845 mutations one copy was
     * shared by two): the runner counts those as two mutations and tests
     * each in a run of its own, so they are counted as two here.
     *
     * @return array<string, int> Source file => mutations that ended at the limit, most first
     */
    public static function read(string $log): array
    {
        $mutants = [];
        foreach (explode("\n", $log) as $line) {
            $fields = explode("\t", trim($line));
            if (count($fields) === 3 && !in_array('', $fields, true)) {
                $mutants[$fields[0]][$fields[1] . "\t" . $fields[2]] = true;
            }
        }

        $bySource = array_map(count(...), $mutants);
        // By name first: the sort is stable, and the order of the lines is
        // the order in which processes happened to end
        ksort($bySource);
        arsort($bySource);

        return $bySource;
    }
}
