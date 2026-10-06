<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Compares two sets of benchmark runs made on one machine: the base of a
 * change and the change itself.
 *
 * The figure compared is the fastest revolution a subject managed in any of
 * its runs. On a shared machine every disturbance makes a run slower and
 * none makes it faster, so the fastest of many is the measurement least
 * touched by what else the machine was doing. The two sides are measured by
 * turns (tests/bin/compare-benchmarks.php), so a slow spell falls on both.
 *
 * A subject is a regression when the change's fastest revolution is more
 * than the margin times the base's. A subject the base could not run (it
 * timed out, or the benchmark is new) has nothing to be compared with and
 * is reported as such, not as a failure.
 */
final class BenchmarkComparison
{
    /**
     * The fastest revolution of every subject in a phpbench XML dump, in
     * microseconds.
     *
     * @return array<string, float> "Class::subject" => microseconds
     *
     * @throws RuntimeException When the text is not a phpbench dump
     */
    public static function fastestBySubject(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$document instanceof SimpleXMLElement || $document->getName() !== 'phpbench') {
            throw new RuntimeException('Not a phpbench XML dump.');
        }

        $fastest = [];
        foreach ($document->suite as $suite) {
            foreach ($suite->benchmark as $benchmark) {
                $class = ltrim((string) $benchmark['class'], '\\');
                $class = substr($class, (int) strrpos('\\' . $class, '\\'));

                foreach ($benchmark->subject as $subject) {
                    $name = $class . '::' . (string) $subject['name'];
                    foreach ($subject->variant as $variant) {
                        foreach ($variant->iteration as $iteration) {
                            $revolutions = (int) $iteration['time-revs'];
                            if ($revolutions < 1) {
                                continue;
                            }
                            $time = (float) $iteration['time-net'] / $revolutions;
                            $fastest[$name] = min($fastest[$name] ?? $time, $time);
                        }
                    }
                }
            }
        }

        return $fastest;
    }

    /**
     * The fastest revolution of every subject over several dumps.
     *
     * @param list<string> $dumps phpbench XML dumps
     * @return array<string, float>
     *
     * @throws RuntimeException
     */
    public static function fastestOverRuns(array $dumps): array
    {
        $fastest = [];
        foreach ($dumps as $dump) {
            foreach (self::fastestBySubject($dump) as $subject => $time) {
                $fastest[$subject] = min($fastest[$subject] ?? $time, $time);
            }
        }
        ksort($fastest);

        return $fastest;
    }

    /**
     * @param array<string, float> $base Fastest revolution by subject, on the base
     * @param array<string, float> $change Fastest revolution by subject, on the change
     * @param float $margin How many times the base's figure the change may take
     *
     * @return list<array{subject: string, base: float|null, change: float|null, ratio: float|null, verdict: string}>
     *         One row per subject of either side. The verdict is `ok`,
     *         `regression`, `no baseline` (the base has no figure) or
     *         `not measured` (the change has none, which is a failure: a
     *         benchmark of the change errored or timed out)
     */
    public static function compare(array $base, array $change, float $margin): array
    {
        $subjects = array_keys($base + $change);
        sort($subjects);

        $rows = [];
        foreach ($subjects as $subject) {
            $baseTime = $base[$subject] ?? null;
            $changeTime = $change[$subject] ?? null;

            $ratio = null;
            if ($changeTime === null) {
                $verdict = 'not measured';
            } elseif ($baseTime === null || $baseTime <= 0.0) {
                $verdict = 'no baseline';
            } else {
                $ratio = $changeTime / $baseTime;
                $verdict = $ratio > $margin ? 'regression' : 'ok';
            }

            $rows[] = ['subject' => $subject, 'base' => $baseTime, 'change' => $changeTime, 'ratio' => $ratio, 'verdict' => $verdict];
        }

        return $rows;
    }

    /**
     * Whether any row fails the comparison.
     *
     * @param list<array{subject: string, base: float|null, change: float|null, ratio: float|null, verdict: string}> $rows
     */
    public static function failed(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['verdict'] === 'regression' || $row['verdict'] === 'not measured') {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows as a table, times in milliseconds.
     *
     * @param list<array{subject: string, base: float|null, change: float|null, ratio: float|null, verdict: string}> $rows
     */
    public static function table(array $rows): string
    {
        $width = 7;
        foreach ($rows as $row) {
            $width = max($width, strlen($row['subject']));
        }

        $lines = [sprintf('%-' . $width . 's  %12s  %12s  %7s  %s', 'subject', 'base ms', 'change ms', 'ratio', 'verdict')];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '%-' . $width . 's  %12s  %12s  %7s  %s',
                $row['subject'],
                $row['base'] === null ? '-' : sprintf('%.3F', $row['base'] / 1000),
                $row['change'] === null ? '-' : sprintf('%.3F', $row['change'] / 1000),
                $row['ratio'] === null ? '-' : sprintf('%.2F', $row['ratio']),
                $row['verdict']
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
