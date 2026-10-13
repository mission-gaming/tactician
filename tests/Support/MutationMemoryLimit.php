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
 * `tests/mutation-memory.php` installs it. Composer loads that file with the
 * autoloader (`autoload-dev.files` in `composer.json`), and it has to be
 * there and not in a PHPUnit bootstrap or `tests/Pest.php`: the line is
 * written by a shutdown function, PHP runs shutdown functions in the order
 * they were registered, and Pest registers one of its own as soon as it
 * starts, which ends the process on a fatal error and so keeps every later
 * one from running. The autoloader is the one thing Pest loads before that.
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
     *   `--mutate`: removes the log of an earlier run, so that the log holds
     *   one run. Its own memory is not limited: it holds the coverage of
     *   the whole first run of the tests.
     * - Any other process (an ordinary test run, a static analysis, the
     *   summary script): nothing.
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
            if (in_array('--mutate', $arguments, true) && is_file($log)) {
                unlink($log);
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
