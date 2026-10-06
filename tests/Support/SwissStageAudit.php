<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Drives a Swiss stage round by round with random results and checks every
 * round the engine pairs against what the usage guide promises.
 *
 * Nothing here asks the library what happened. Who is active, who has met
 * whom, who has had a bye and how many points each participant has are all
 * kept in this class's own books, from the pairings the engine returned and
 * the results this class drew. The stage state is only handed back to the
 * engine (through JSON when asked, as a platform would store it).
 *
 * What a round is held to (docs/USAGE.md, "Swiss Tournaments" and the
 * glossary entries "Bye" and "Score group"):
 *
 * - Its number is one more than the last, starting at 1, and every event
 *   carries it.
 * - Every active participant is in exactly one event or has the bye; nobody
 *   else appears.
 * - No two participants meet who have met before.
 * - An odd active field has exactly one bye; an even one has none.
 * - The bye goes to "the lowest-placed participant with the fewest so far":
 *   nobody with fewer byes, and nobody with as many byes and fewer points,
 *   could have taken it and left a complete pairing for the rest. In
 *   particular nobody takes a second bye while a participant who has had
 *   none could have taken it.
 * - Score groups: participants are "ordered by standings and paired
 *   adjacently, backtracking past repeat pairings", a bye counting as a win.
 *   So, taking the events in the order the engine lists them, each one holds
 *   a participant from the highest score group still unpaired, and that
 *   participant's opponent has at least as many points as every other
 *   unpaired participant it could have met while leaving a complete pairing
 *   for the rest. Nobody is paired down past an opponent it could have had.
 * - When the engine says there is no valid pairing, there is none: no bye
 *   and set of events covers the active field without a rematch.
 * - The stage is complete after its planned rounds and not before, and its
 *   outcome then ranks everyone it has seen, withdrawn participants included.
 *
 * "A complete pairing exists" is a perfect matching in the graph of
 * participants who may still meet. It is decided with the Tutte matrix: the
 * determinant of a skew-symmetric matrix holding a random number for each
 * edge is non-zero only if a perfect matching exists. Every violation above
 * rests on a non-zero determinant, which is a proof. A zero determinant
 * means "none" with an error chance below 2e-8 per test, and that case never
 * reports a violation.
 */
final class SwissStageAudit
{
    /** A prime below 2^31, so that a product of two residues fits an int. */
    private const int MODULUS = 2147483647;

    /**
     * @param list<Participant> $participants The field, in list order
     * @param int $rounds Rounds to drive; the engine must be planned for the same number
     * @param int $seed Seeds the results, the withdrawals and the matching test
     * @param float $win Points for a win and for a bye
     * @param float $draw Points for a draw
     * @param (Closure(Participant, Participant): bool)|null $mayMeet The pairs a constraint of the engine allows, when it has one
     * @param bool $withdrawals Whether participants withdraw between rounds (never below four active)
     * @param bool $throughJson Whether the state goes through JSON after every round
     * @return array{violations: list<string>, transcript: list<string>, rounds: int, failedRound: int|null, byes: int, withdrawn: int}
     * @throws InvalidConfigurationException When the engine refuses the state
     * @throws JsonConversionException When the state does not survive JSON
     */
    public static function drive(
        SwissPairingEngine $engine,
        array $participants,
        int $rounds,
        int $seed,
        float $win = 3.0,
        float $draw = 1.0,
        ?Closure $mayMeet = null,
        bool $withdrawals = false,
        bool $throughJson = true
    ): array {
        $random = new Randomizer(new Mt19937($seed));
        $matching = new Randomizer(new Mt19937($seed + 7919));

        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $participants);
        $indexOf = array_flip($ids);
        $count = count($participants);

        /** @var array<int, true> $active */
        $active = array_fill(0, $count, true);
        /** @var array<int, array<int, true>> $met */
        $met = [];
        $points = array_fill(0, $count, 0.0);
        $byeCounts = array_fill(0, $count, 0);

        $blocked = static function (int $one, int $other) use (&$met, $mayMeet, $participants): bool {
            return isset($met[$one][$other])
                || ($mayMeet !== null && !$mayMeet($participants[$one], $participants[$other]));
        };

        $violations = [];
        $transcript = [];
        $failedRound = null;
        $byesGiven = 0;
        $withdrawn = 0;
        $paired = 0;

        $state = StageState::start($participants);

        for ($round = 1; $round <= $rounds; ++$round) {
            $field = array_keys($active);

            if ($engine->isComplete($state)) {
                $violations[] = sprintf('round %d: the stage is reported complete after %d of %d rounds', $round, $round - 1, $rounds);
            }

            try {
                $pairing = $engine->pairNextRound($state);
            } catch (NoValidPairingException $exception) {
                if ($exception->getRoundNumber() !== $round) {
                    $violations[] = "round {$round}: the failure names round {$exception->getRoundNumber()}";
                }
                if (self::completePairingExists($field, $blocked, $matching)) {
                    $violations[] = "round {$round}: the engine found no valid pairing although one exists";
                }
                $failedRound = $round;

                break;
            }

            ++$paired;
            $score = [];
            foreach ($field as $index) {
                $score[$index] = $points[$index] + $byeCounts[$index] * $win;
            }

            if ($pairing->getRoundNumber() !== $round) {
                $violations[] = "round {$round}: paired as round {$pairing->getRoundNumber()}";
            }

            // The bye.
            $byes = [];
            foreach ($pairing->getByes() as $bye) {
                $byes[] = $indexOf[$bye->getId()] ?? -1;
            }
            if (count($byes) !== count($field) % 2) {
                $violations[] = sprintf('round %d: %d bye(s) in a field of %d', $round, count($byes), count($field));
            }

            $unpaired = $active;
            foreach ($byes as $bye) {
                if (!isset($unpaired[$bye])) {
                    $violations[] = "round {$round}: a bye for someone who is not active or already has one";

                    continue;
                }
                unset($unpaired[$bye]);

                foreach ($field as $other) {
                    if ($other === $bye) {
                        continue;
                    }
                    $fewerByes = $byeCounts[$other] < $byeCounts[$bye];
                    $lowerPlaced = $byeCounts[$other] === $byeCounts[$bye] && $score[$other] < $score[$bye];
                    if (!$fewerByes && !$lowerPlaced) {
                        continue;
                    }

                    $rest = array_values(array_diff($field, [$other]));
                    if (self::hasPerfectMatching($rest, $blocked, $matching)) {
                        $violations[] = sprintf(
                            'round %d: %s took the bye (%d before, %s points) although %s (%d before, %s points) could have',
                            $round,
                            $ids[$bye],
                            $byeCounts[$bye],
                            $score[$bye],
                            $ids[$other],
                            $byeCounts[$other],
                            $score[$other]
                        );

                        break;
                    }
                }
            }

            // The events, in the order the engine lists them.
            $line = [];
            $results = [];
            $roundPairs = [];
            foreach ($pairing->getEvents() as $event) {
                $pair = $event->getParticipants();
                if (count($pair) !== 2 || $event->getRound()?->getNumber() !== $round) {
                    $violations[] = "round {$round}: an event without two participants or with another round number";

                    continue;
                }

                $first = $indexOf[$pair[0]->getId()] ?? -1;
                $second = $indexOf[$pair[1]->getId()] ?? -1;
                $line[] = $pair[0]->getId() . '-' . $pair[1]->getId();

                if ($first === $second || !isset($unpaired[$first], $unpaired[$second])) {
                    $violations[] = "round {$round}: {$line[count($line) - 1]} pairs someone twice, with itself, or who is not active";

                    continue;
                }
                if (isset($met[$first][$second])) {
                    $violations[] = "round {$round}: {$line[count($line) - 1]} is a rematch";
                }
                if ($mayMeet !== null && !$mayMeet($pair[0], $pair[1])) {
                    $violations[] = "round {$round}: {$line[count($line) - 1]} breaks the constraint";
                }

                $highest = $score[$first];
                foreach (array_keys($unpaired) as $candidate) {
                    $highest = max($highest, $score[$candidate]);
                }
                if ($score[$first] !== $highest && $score[$second] !== $highest) {
                    $violations[] = "round {$round}: {$line[count($line) - 1]} is paired before the highest score group is";
                } elseif ($score[$first] !== $score[$second]) {
                    [$top, $opponent] = $score[$first] === $highest ? [$first, $second] : [$second, $first];

                    foreach (array_keys($unpaired) as $candidate) {
                        if ($candidate === $top || $score[$candidate] <= $score[$opponent] || $blocked($top, $candidate)) {
                            continue;
                        }

                        $rest = array_values(array_diff(array_keys($unpaired), [$top, $candidate]));
                        if (self::hasPerfectMatching($rest, $blocked, $matching)) {
                            $violations[] = sprintf(
                                'round %d: %s (%s points) meets %s (%s points) although %s (%s points) was available',
                                $round,
                                $ids[$top],
                                $score[$top],
                                $ids[$opponent],
                                $score[$opponent],
                                $ids[$candidate],
                                $score[$candidate]
                            );

                            break;
                        }
                    }
                }

                unset($unpaired[$first], $unpaired[$second]);
                $roundPairs[] = [$first, $second];

                $outcome = $random->getInt(0, 2);
                $results[] = $outcome === 2 ? new Result($event) : new Result($event, $pair[$outcome]);
                if ($outcome === 2) {
                    $points[$first] += $draw;
                    $points[$second] += $draw;
                } else {
                    $points[$outcome === 0 ? $first : $second] += $win;
                }
            }

            if ($unpaired !== []) {
                $violations[] = sprintf('round %d: %d active participant(s) neither paired nor given the bye', $round, count($unpaired));
            }

            foreach ($roundPairs as [$first, $second]) {
                $met[$first][$second] = true;
                $met[$second][$first] = true;
            }
            foreach ($byes as $bye) {
                if ($bye >= 0) {
                    ++$byeCounts[$bye];
                    ++$byesGiven;
                }
            }

            $byeIds = array_map(static fn(int $bye): string => $ids[$bye] ?? '?', $byes);
            $transcript[] = $round . ': ' . implode(' ', $line) . ($byeIds === [] ? '' : ' | bye ' . implode(' ', $byeIds));

            $state = $state->withRoundPlayed($pairing, $results);

            if ($withdrawals && $round < $rounds && count($active) > 4 && $random->getInt(0, 3) === 0) {
                $leaving = array_keys($active)[$random->getInt(0, count($active) - 1)];
                unset($active[$leaving]);
                $state = $state->withoutParticipant($participants[$leaving]);
                $transcript[] = "withdrawn: {$ids[$leaving]}";
                ++$withdrawn;
            }

            if ($throughJson) {
                $state = StageState::fromJson($state->toJson());
            }
        }

        if ($failedRound === null) {
            if (!$engine->isComplete($state)) {
                $violations[] = "the stage is not reported complete after its {$rounds} rounds";
            } elseif (count($engine->getOutcome($state)?->getStandings()->getEntries() ?? []) !== $count) {
                $violations[] = 'the outcome does not rank everyone the stage has seen';
            }
        }

        return [
            'violations' => $violations,
            'transcript' => $transcript,
            'rounds' => $paired,
            'failedRound' => $failedRound,
            'byes' => $byesGiven,
            'withdrawn' => $withdrawn,
        ];
    }

    /**
     * Whether some bye (in an odd field) and set of events covers the field
     * with no blocked pair.
     *
     * @param list<int> $field
     * @param Closure(int, int): bool $blocked
     */
    public static function completePairingExists(array $field, Closure $blocked, Randomizer $random): bool
    {
        if (count($field) % 2 === 0) {
            return self::hasPerfectMatching($field, $blocked, $random);
        }

        foreach ($field as $bye) {
            if (self::hasPerfectMatching(array_values(array_diff($field, [$bye])), $blocked, $random)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the vertices can be split into pairs none of which is blocked.
     *
     * True is a proof (a non-zero Tutte determinant). False is wrong with a
     * chance below the vertex count over the modulus.
     *
     * @param list<int> $vertices
     * @param Closure(int, int): bool $blocked
     */
    public static function hasPerfectMatching(array $vertices, Closure $blocked, Randomizer $random): bool
    {
        $size = count($vertices);
        if ($size % 2 !== 0) {
            return false;
        }
        if ($size === 0) {
            return true;
        }

        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        for ($row = 0; $row < $size; ++$row) {
            for ($column = $row + 1; $column < $size; ++$column) {
                if ($blocked($vertices[$row], $vertices[$column])) {
                    continue;
                }
                $value = $random->getInt(1, self::MODULUS - 1);
                $matrix[$row][$column] = $value;
                $matrix[$column][$row] = self::MODULUS - $value;
            }
        }

        // Gaussian elimination modulo the prime: the determinant is non-zero
        // exactly when every column yields a pivot.
        for ($column = 0; $column < $size; ++$column) {
            $pivot = null;
            for ($row = $column; $row < $size; ++$row) {
                if ($matrix[$row][$column] !== 0) {
                    $pivot = $row;

                    break;
                }
            }
            if ($pivot === null) {
                return false;
            }
            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];

            $inverse = self::inverse($matrix[$column][$column]);
            for ($row = $column + 1; $row < $size; ++$row) {
                if ($matrix[$row][$column] === 0) {
                    continue;
                }
                $factor = $matrix[$row][$column] * $inverse % self::MODULUS;
                for ($index = $column; $index < $size; ++$index) {
                    $matrix[$row][$index] = ($matrix[$row][$index] - $factor * $matrix[$column][$index] % self::MODULUS + self::MODULUS) % self::MODULUS;
                }
            }
        }

        return true;
    }

    /**
     * The inverse of a residue, by Fermat's little theorem.
     */
    private static function inverse(int $value): int
    {
        $result = 1;
        $base = $value % self::MODULUS;
        $exponent = self::MODULUS - 2;
        while ($exponent > 0) {
            if ($exponent % 2 === 1) {
                $result = $result * $base % self::MODULUS;
            }
            $base = $base * $base % self::MODULUS;
            $exponent = intdiv($exponent, 2);
        }

        return $result;
    }
}
