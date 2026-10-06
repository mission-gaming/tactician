<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\PotDrawPlan;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Whole-schedule pot draw: the entrants are cut into pots of equal size in
 * list order, and every entrant meets a fixed number of opponents from
 * every pot, its own included, with no rematch. Every round is drawn before
 * any event is played.
 *
 * This is not Swiss pairing, although the format is often called Swiss:
 * nothing is paired from results. It is a partial round robin constrained
 * by pots. The generator is pairwise: every event has two participants.
 *
 * Experimental: the class and its output for a seed may change in a minor
 * release (see the README's "Versioning and stability").
 *
 * ## What is drawn
 *
 * With p pots of size s and k opponents per pot, the schedule has p × k
 * rounds. It is built directly, with no search, and only for the
 * configurations below; any other feasible one is refused as not yet
 * supported ({@see InvalidConfigurationReason::ConfigurationNotYetSupported}),
 * and an infeasible one is refused by {@see PotDrawPlan}. Both refusals
 * happen before anything is drawn.
 *
 * - any even pot size s;
 * - an odd pot size s with two opponents per pot (the number of pots is
 *   then even, because the field is).
 *
 * Roles (the position of a participant in an event) are balanced: the two
 * role counts of an entrant differ by at most one, and when k is even an
 * entrant is in each role against every pot exactly k / 2 times.
 *
 * ## Why every round has every entrant once: even pot size
 *
 * The draw is k layers. A layer gives every entrant one opponent from every
 * pot, in p rounds:
 *
 * - Inside a pot, a layer uses one perfect matching of the pot. The
 *   matchings of the layers come from one 1-factorisation of the complete
 *   graph on the pot (the circle method), so they share no pairing. The pot
 *   size is even, so each of them covers the whole pot.
 * - Between two pots A and B, a layer uses one cyclic shift: the member at
 *   position i of A meets the member at position i + d of B, positions
 *   taken modulo s. The k layers use k different shifts d, so no pairing
 *   repeats.
 * - The rounds of a layer follow a 1-factorisation of the pots themselves,
 *   with each pot meeting itself once: with an odd number of pots, round r
 *   pairs the pots by the circle method and the pot left over plays inside
 *   itself; with an even number, p − 1 rounds pair all the pots and one more
 *   round has every pot play inside itself.
 *
 * A round is therefore a union of perfect matchings on disjoint sets of
 * entrants that together are the whole field: a pot playing inside itself,
 * or two pots joined by a shift. Over the k layers an entrant gets k
 * different opponents from its own pot and k from every other pot.
 *
 * Roles, even pot size. The layers are taken in pairs. Between two pots,
 * one layer of a pair has the entrants of A in the first role and the other
 * has those of B. Inside a pot, the two matchings of a pair form cycles of
 * even length; walking each cycle and putting the entrant that is left
 * first gives every member one event in each role. With k odd one layer is
 * left over. Its matching inside a pot joins positions 2j and 2j + 1, and
 * its shifts are even, so it never joins an even position to an odd one
 * across pots. The pots are placed in a circular order in which each is
 * "ahead of" half of the others (one more or one fewer than half when the
 * number of pots is even). Even positions take the first role against the
 * pots they are ahead of, odd positions do the reverse, and the role inside
 * the pot is the one that brings an entrant's total back within one.
 *
 * ## Why every round has every entrant once: odd pot size, two opponents
 *
 * A pot of odd size has no perfect matching, so no round can be played
 * inside the pots alone. The pots are paired as partners by one perfect
 * matching of the pots, taken from a 1-factorisation of them (the number of
 * pots is even).
 *
 * - Two partner pots A and B take four rounds between them. Inside each,
 *   the members form one cycle 0, 1, ..., s − 1, 0, which gives each member
 *   its two opponents from its own pot. Round one plays the pairs (1, 2),
 *   (3, 4), ... of both cycles and joins the two members at position 0.
 *   Round two plays (0, 1), (2, 3), ... and joins the two members at
 *   position s − 1. Round three plays the last edge of each cycle,
 *   (s − 1, 0), and joins the members at every other position i to each
 *   other. Round four joins position i of A to position i + c of B for one
 *   c that is not zero. Each of the four covers both pots exactly once, and
 *   a member has met positions i and i + c of its partner pot.
 * - Every other perfect matching of the pots is played twice, as two rounds
 *   with two different shifts.
 *
 * That is 4 + 2 × (p − 2) = 2 × p rounds. Roles: along its cycle each
 * member is first against the next member and second against the previous
 * one; against every other pot one of the two meetings has it first.
 *
 * ## Mixing the rounds
 *
 * Built as above, a round sets whole pots against each other, the events
 * inside the pots share a few rounds, and one pot is first against another
 * for a whole round. None of that is part of the format, so the rounds are
 * mixed before they are returned. No event is changed: only the round an
 * event is in.
 *
 * Take two rounds X and Y. Each has every entrant exactly once and they
 * share no pairing, because no pairing is in the schedule twice. Start at
 * any entrant, follow its event in X to its opponent, that opponent's event
 * in Y to the next entrant, and so on. Every entrant has exactly one event
 * in X and one in Y, so the walk closes into a cycle that uses events of X
 * and Y in turn, and the entrants fall into such cycles with none left
 * over. The entrants of one cycle are exactly the entrants of its events
 * from X, and exactly the entrants of its events from Y. So moving the
 * cycle's X events to Y and its Y events to X leaves each of those
 * entrants in X once and in Y once, and touches nobody else: both rounds
 * still have every entrant exactly once.
 *
 * One exchange draws two different rounds and, for each of their cycles,
 * a coin that decides whether the cycle trades. The scheduler makes
 * 8 × (number of rounds) exchanges. The events themselves, and so the set
 * of pairings and the role of each entrant in each event, are the same
 * before and after. Everything proved above about opponents per pot,
 * rematches and roles is a statement about that set, so it still holds.
 * Two rounds whose events form one single cycle can only swap as wholes,
 * which is why the smallest configurations (6 entrants in 3 pots of 2,
 * say) come out with the shape they were built with.
 *
 * Last, the order of the rounds and the order of the events inside each
 * round are shuffled.
 *
 * ## The seed
 *
 * The seed chooses the order of the members inside each pot (which decides
 * who meets whom), the order of the pots in the pot-level matchings, the
 * matchings and shifts used, which side is first, which rounds trade which
 * cycles, and the order of the rounds and of the events in them. Between
 * two given pots, or inside one, every pairing is as likely as any other,
 * because the members of each pot are shuffled first.
 *
 * The draw is not uniform over every schedule the format allows. The
 * pairings between two pots are always cyclic shifts of one order of their
 * members, and inside a pot they come from one 1-factorisation (or one
 * cycle, for an odd pot size); the mixing changes which round an event is
 * in and nothing else, and it stops after a fixed number of exchanges, so
 * a round is now and then still made of whole pots.
 *
 * The same entrants, options and seed give the same schedule on every
 * call: each call builds its own `Random\Randomizer` on the
 * `Xoshiro256StarStar` engine from the seed and keeps no state between
 * calls. Nothing in the draw depends on anything but the entrants' list
 * positions and that randomizer.
 *
 * ## Cost
 *
 * Time and memory are proportional to the number of events,
 * entrants × pots × opponents per pot / 2: for the construction, for the
 * mixing (one exchange visits every entrant once, and there are
 * 8 × rounds of them, so 16 visits per event) and for the validation that
 * follows.
 *
 * @experimental
 */
class PotDrawScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * How many times, per round of the schedule, two rounds trade events
     * when the rounds are mixed. Part of the output for a seed.
     */
    private const int EXCHANGES_PER_ROUND = 8;

    /**
     * Takes nothing: a pot draw has no constraints, and its randomness
     * comes from the seed in PotDrawOptions, not from a randomizer here.
     */
    public function __construct()
    {
        $this->initializeValidation();
    }

    /**
     * Draw the whole schedule.
     *
     * Every round has every entrant exactly once, every entrant meets
     * opponentsPerPot different opponents from every pot, and no two
     * entrants meet twice. The events are in round order, rounds numbered
     * from 1. The same entrants in the same order, options and seed give
     * the same schedule, from this object or another. The schedule's
     * metadata keys are 'algorithm', 'participant_count', 'pots',
     * 'pot_size', 'opponents_per_pot', 'seed', 'total_rounds' and
     * 'expected_event_count'.
     *
     * @param array<Participant> $participants The entrants in seeding order: list position is the
     *                                         seeding, and no seed attribute is read
     * @param SchedulerOptions|null $options PotDrawOptions, or null for one pot, one opponent and seed 0
     *
     * @throws InvalidConfigurationException When the configuration is infeasible or not yet supported
     * @throws IncompleteScheduleException When the drawn schedule fails the plan's validation,
     *                                     which would be a defect in this class
     * @throws InvalidInputException Never in practice: an event is always built with two participants
     */
    #[Override]
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        $options = $this->resolveOptions($options);
        $plan = $this->buildPlan($participants, $options);
        $this->clearViolations();

        $entrants = $plan->getParticipants();
        $randomizer = new Randomizer(new Xoshiro256StarStar($options->seed));

        $rounds = $plan->getPotSize() % 2 === 0
            ? $this->drawEvenPots($plan->getPots(), $plan->getPotSize(), $plan->getOpponentsPerPot(), $randomizer)
            : $this->drawOddPots($plan->getPots(), $plan->getPotSize(), $randomizer);

        $rounds = $this->mixRounds($rounds, count($entrants), $randomizer);
        $rounds = $randomizer->shuffleArray($rounds);

        $events = [];
        foreach (array_values($rounds) as $index => $pairs) {
            $round = new Round($index + 1);
            foreach ($randomizer->shuffleArray($pairs) as [$first, $second]) {
                $events[] = new Event([$entrants[$first], $entrants[$second]], $round);
            }
        }

        $schedule = new Schedule($events, [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($entrants),
            'pots' => $plan->getPots(),
            'pot_size' => $plan->getPotSize(),
            'opponents_per_pot' => $plan->getOpponentsPerPot(),
            'seed' => $options->seed,
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
        ]);

        $this->validateGeneratedSchedule($schedule, $entrants, $plan);

        return $schedule;
    }

    /**
     * Build the pot draw plan for the given configuration: the exact rounds
     * and events, before anything is drawn.
     *
     * @param array<Participant> $participants The entrants in seeding order
     * @param SchedulerOptions|null $options PotDrawOptions, or null for one pot, one opponent and seed 0
     * @throws InvalidConfigurationException When the configuration is infeasible or not yet supported
     */
    #[Override]
    public function getPlan(
        array $participants,
        ?SchedulerOptions $options = null
    ): PotDrawPlan {
        return $this->buildPlan($participants, $this->resolveOptions($options));
    }

    /**
     * Default and type-check the options: this scheduler accepts only
     * PotDrawOptions.
     *
     * @throws InvalidConfigurationException When another algorithm's options are passed
     */
    private function resolveOptions(?SchedulerOptions $options): PotDrawOptions
    {
        if ($options === null) {
            return new PotDrawOptions();
        }

        if (!$options instanceof PotDrawOptions) {
            throw new InvalidConfigurationException(
                'A pot draw requires PotDrawOptions',
                ['options' => $options::class],
                reason: InvalidConfigurationReason::UnsupportedOptions
            );
        }

        return $options;
    }

    /**
     * The plan refuses what is infeasible; this adds the refusal of what is
     * feasible and has no direct construction here yet.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function buildPlan(array $participants, PotDrawOptions $options): PotDrawPlan
    {
        $plan = new PotDrawPlan($participants, $options->pots, $options->opponentsPerPot);

        if ($plan->getPotSize() % 2 !== 0 && $plan->getOpponentsPerPot() !== 2) {
            throw new InvalidConfigurationException(
                'A pot draw with pots of odd size is supported for two opponents per pot only',
                [
                    'participant_count' => count($plan->getParticipants()),
                    'pots' => $plan->getPots(),
                    'pot_size' => $plan->getPotSize(),
                    'opponents_per_pot' => $plan->getOpponentsPerPot(),
                    'supported_opponents_per_pot_for_odd_pot_size' => [2],
                ],
                reason: InvalidConfigurationReason::ConfigurationNotYetSupported
            );
        }

        return $plan;
    }

    /**
     * The rounds for an even pot size (see the class docblock).
     *
     * An entrant is the index of its place in the participant list; a pair
     * is [first role, second role].
     *
     * @return list<list<array{int, int}>>
     */
    private function drawEvenPots(int $pots, int $potSize, int $opponentsPerPot, Randomizer $randomizer): array
    {
        $members = $this->shuffledPots($pots, $potSize, $randomizer);
        $potOrder = $randomizer->shuffleArray(range(0, $pots - 1));
        $potRank = array_flip($potOrder);
        $hasSingleLayer = $opponentsPerPot % 2 === 1;
        $pairedLayers = $opponentsPerPot - ($hasSingleLayer ? 1 : 0);
        $factors = $this->potFactors($potSize);

        // Inside each pot: one oriented perfect matching per layer.
        $inside = [];
        for ($pot = 0; $pot < $pots; ++$pot) {
            // Factor 0 joins positions 2j and 2j + 1 and is kept for the
            // single layer; the paired layers draw from the others.
            $others = $potSize > 2 ? range(1, $potSize - 2) : [];
            $choice = array_slice($randomizer->shuffleArray($others), 0, $pairedLayers);
            $layers = [];
            for ($layer = 0; $layer < $pairedLayers; $layer += 2) {
                [$layers[$layer], $layers[$layer + 1]] = $this->orientMatchingPair(
                    $factors[$choice[$layer]],
                    $factors[$choice[$layer + 1]],
                    $potSize
                );
            }

            if ($hasSingleLayer) {
                // Even positions are ahead of this many more pots than they
                // are behind; the role inside the pot offsets it.
                $lead = 0;
                for ($other = 0; $other < $pots; ++$other) {
                    if ($other !== $pot) {
                        $lead += $this->isAhead($potRank[$pot], $potRank[$other], $pots) ? 1 : -1;
                    }
                }
                $single = [];
                foreach ($factors[0] as [$even, $odd]) {
                    $single[] = $lead > 0 ? [$odd, $even] : [$even, $odd];
                }
                $layers[$pairedLayers] = $single;
            }

            foreach ($layers as $layer => $matching) {
                foreach ($matching as [$first, $second]) {
                    $inside[$pot][$layer][] = [$members[$pot][$first], $members[$pot][$second]];
                }
            }
        }

        // Between each two pots: one oriented shift per layer.
        $between = [];
        for ($a = 0; $a < $pots; ++$a) {
            for ($b = $a + 1; $b < $pots; ++$b) {
                $shifts = $randomizer->shuffleArray(range(0, $potSize - 1));
                if ($hasSingleLayer) {
                    // The single layer needs an even shift: move the first
                    // even one to the single layer's place.
                    foreach ($shifts as $index => $shift) {
                        if ($shift % 2 === 0) {
                            [$shifts[$index], $shifts[$pairedLayers]] = [$shifts[$pairedLayers], $shift];
                            break;
                        }
                    }
                }

                // Which pot is first in each layer of a pair of layers: one
                // pot in the even layer, the other in the odd one.
                $aFirstInLayer = [];
                for ($layer = 0; $layer < $pairedLayers; $layer += 2) {
                    $aFirstInLayer[$layer] = $randomizer->getInt(0, 1) === 0;
                    $aFirstInLayer[$layer + 1] = !$aFirstInLayer[$layer];
                }
                $aAhead = $this->isAhead($potRank[$a], $potRank[$b], $pots);

                for ($layer = 0; $layer < $opponentsPerPot; ++$layer) {
                    for ($position = 0; $position < $potSize; ++$position) {
                        $fromA = $members[$a][$position];
                        $fromB = $members[$b][($position + $shifts[$layer]) % $potSize];
                        // The single layer has no entry: there, even
                        // positions are first against the pots they are
                        // ahead of, and odd positions do the reverse.
                        $aFirst = $aFirstInLayer[$layer] ?? ($aAhead === ($position % 2 === 0));
                        $between[$a][$b][$layer][] = $aFirst ? [$fromA, $fromB] : [$fromB, $fromA];
                    }
                }
            }
        }

        // The rounds of a layer: a 1-factorisation of the pots, each pot
        // meeting itself once.
        $potRounds = [];
        foreach ($this->circleRounds($pots) as $matching) {
            $potRound = [];
            foreach ($matching as [$x, $y]) {
                // The ghost of an odd count: its partner plays inside itself.
                $potRound[] = $y >= $pots ? [$potOrder[$x], $potOrder[$x]] : [$potOrder[$x], $potOrder[$y]];
            }
            $potRounds[] = $potRound;
        }
        if ($pots % 2 === 0) {
            $potRounds[] = array_map(fn(int $pot): array => [$pot, $pot], range(0, $pots - 1));
        }

        $rounds = [];
        for ($layer = 0; $layer < $opponentsPerPot; ++$layer) {
            foreach ($potRounds as $potRound) {
                $round = [];
                foreach ($potRound as [$x, $y]) {
                    $pairs = $x === $y
                        ? $inside[$x][$layer]
                        : $between[min($x, $y)][max($x, $y)][$layer];
                    foreach ($pairs as $pair) {
                        $round[] = $pair;
                    }
                }
                $rounds[] = $round;
            }
        }

        return $rounds;
    }

    /**
     * The rounds for an odd pot size with two opponents per pot (see the
     * class docblock). The number of pots is even.
     *
     * @return list<list<array{int, int}>>
     */
    private function drawOddPots(int $pots, int $potSize, Randomizer $randomizer): array
    {
        $members = $this->shuffledPots($pots, $potSize, $randomizer);
        $potOrder = $randomizer->shuffleArray(range(0, $pots - 1));
        $potMatchings = $this->circleRounds($pots);
        $last = $potSize - 1;
        $rounds = [];

        // The partner pots: four rounds between each two.
        $partnerRounds = [[], [], [], []];
        foreach ($potMatchings[0] as [$x, $y]) {
            [$a, $b] = $randomizer->getInt(0, 1) === 0
                ? [$potOrder[$x], $potOrder[$y]]
                : [$potOrder[$y], $potOrder[$x]];
            $shift = $randomizer->getInt(1, $last);

            foreach ([$a, $b] as $pot) {
                for ($position = 1; $position < $last; $position += 2) {
                    $partnerRounds[0][] = [$members[$pot][$position], $members[$pot][$position + 1]];
                }
                for ($position = 0; $position < $last; $position += 2) {
                    $partnerRounds[1][] = [$members[$pot][$position], $members[$pot][$position + 1]];
                }
                $partnerRounds[2][] = [$members[$pot][$last], $members[$pot][0]];
            }

            $partnerRounds[0][] = [$members[$a][0], $members[$b][0]];
            $partnerRounds[1][] = [$members[$a][$last], $members[$b][$last]];
            for ($position = 1; $position < $last; ++$position) {
                $partnerRounds[2][] = [$members[$a][$position], $members[$b][$position]];
            }
            for ($position = 0; $position < $potSize; ++$position) {
                $partnerRounds[3][] = [$members[$b][($position + $shift) % $potSize], $members[$a][$position]];
            }
        }
        foreach ($partnerRounds as $round) {
            $rounds[] = $round;
        }

        // Every other matching of the pots: two rounds, two shifts.
        foreach (array_slice($potMatchings, 1) as $matching) {
            $pair = [[], []];
            foreach ($matching as [$x, $y]) {
                [$a, $b] = $randomizer->getInt(0, 1) === 0
                    ? [$potOrder[$x], $potOrder[$y]]
                    : [$potOrder[$y], $potOrder[$x]];
                $shifts = array_slice($randomizer->shuffleArray(range(0, $last)), 0, 2);

                for ($position = 0; $position < $potSize; ++$position) {
                    $pair[0][] = [$members[$a][$position], $members[$b][($position + $shifts[0]) % $potSize]];
                    $pair[1][] = [$members[$b][($position + $shifts[1]) % $potSize], $members[$a][$position]];
                }
            }
            $rounds[] = $pair[0];
            $rounds[] = $pair[1];
        }

        return $rounds;
    }

    /**
     * Mix the events across the rounds without changing any event (see
     * "Mixing the rounds" in the class docblock): `EXCHANGES_PER_ROUND` ×
     * the number of rounds times, two different rounds are drawn and trade
     * events along the cycles their events form together.
     *
     * Each round has every entrant once and two rounds share no pairing,
     * so following an entrant's event in one round, then its opponent's
     * event in the other, and so on, closes a cycle of even length whose
     * events come from the two rounds in turn. Each cycle either stays as
     * it is or has its events change rounds, on the draw of a coin. The
     * entrants of a cycle are in each round exactly once before and after,
     * so both rounds still have every entrant once.
     *
     * With fewer than two rounds there is nothing to trade and the rounds
     * are returned as they are.
     *
     * @param list<list<array{int, int}>> $rounds
     * @param int $entrants How many entrants there are; every round has each of 0 .. `$entrants` − 1 once
     * @return list<list<array{int, int}>> The same events, each still [first role, second role],
     *                                     and every round still with every entrant once
     */
    private function mixRounds(array $rounds, int $entrants, Randomizer $randomizer): array
    {
        $count = count($rounds);
        if ($count < 2) {
            return $rounds;
        }

        // A round as two maps over the entrants: the opponent of each, and
        // whether it is in the first role. Trading an event between two
        // rounds is then swapping the entries of its two entrants.
        $opponent = [];
        $isFirst = [];
        foreach ($rounds as $round => $pairs) {
            foreach ($pairs as [$first, $second]) {
                $opponent[$round][$first] = $second;
                $opponent[$round][$second] = $first;
                $isFirst[$round][$first] = true;
                $isFirst[$round][$second] = false;
            }
        }

        for ($exchange = 0; $exchange < self::EXCHANGES_PER_ROUND * $count; ++$exchange) {
            $x = $randomizer->getInt(0, $count - 1);
            $y = $randomizer->getInt(0, $count - 2);
            if ($y >= $x) {
                ++$y;
            }

            $opponentX = $opponent[$x];
            $opponentY = $opponent[$y];
            $isFirstX = $isFirst[$x];
            $isFirstY = $isFirst[$y];

            // Entrants are taken in index order, so the cycles are found in
            // an order that depends on nothing but the two rounds.
            $visited = [];
            for ($start = 0; $start < $entrants; ++$start) {
                if (isset($visited[$start])) {
                    continue;
                }

                $cycle = [];
                $entrant = $start;
                do {
                    $other = $opponent[$x][$entrant];
                    $visited[$entrant] = true;
                    $visited[$other] = true;
                    $cycle[] = $entrant;
                    $cycle[] = $other;
                    $entrant = $opponent[$y][$other];
                } while ($entrant !== $start);

                if ($randomizer->getInt(0, 1) === 0) {
                    continue;
                }

                foreach ($cycle as $member) {
                    $opponentX[$member] = $opponent[$y][$member];
                    $opponentY[$member] = $opponent[$x][$member];
                    $isFirstX[$member] = $isFirst[$y][$member];
                    $isFirstY[$member] = $isFirst[$x][$member];
                }
            }

            $opponent[$x] = $opponentX;
            $opponent[$y] = $opponentY;
            $isFirst[$x] = $isFirstX;
            $isFirst[$y] = $isFirstY;
        }

        $mixed = [];
        for ($round = 0; $round < $count; ++$round) {
            $pairs = [];
            for ($entrant = 0; $entrant < $entrants; ++$entrant) {
                if ($isFirst[$round][$entrant]) {
                    $pairs[] = [$entrant, $opponent[$round][$entrant]];
                }
            }
            $mixed[] = $pairs;
        }

        return $mixed;
    }

    /**
     * The members of each pot in a drawn order: entry [pot][position] is an
     * entrant's index in the participant list.
     *
     * @return list<list<int>>
     */
    private function shuffledPots(int $pots, int $potSize, Randomizer $randomizer): array
    {
        $members = [];
        for ($pot = 0; $pot < $pots; ++$pot) {
            $members[] = array_values($randomizer->shuffleArray(range($pot * $potSize, ($pot + 1) * $potSize - 1)));
        }

        return $members;
    }

    /**
     * A 1-factorisation of the complete graph on `$count` labels by the
     * circle method: every two labels are paired in exactly one of the
     * matchings returned, and a matching has every label once.
     *
     * An odd count gets one more label, the ghost `$count`, so that each
     * matching pairs one real label with it; the ghost is always the second
     * entry of its pair.
     *
     * @return list<list<array{int, int}>> `$count − 1` matchings for an even count, `$count` for an odd one
     */
    private function circleRounds(int $count): array
    {
        $size = $count + $count % 2;
        $ring = $size - 1;
        $matchings = [];

        for ($round = 0; $round < $ring; ++$round) {
            $matching = [[$round, $ring]];
            for ($step = 1; $step <= intdiv($ring - 1, 2); ++$step) {
                $matching[] = [($round + $step) % $ring, ($round - $step + $ring) % $ring];
            }
            $matchings[] = $matching;
        }

        return $matchings;
    }

    /**
     * The perfect matchings used inside a pot of even size: the circle
     * method's 1-factorisation with the positions renamed so that matching 0
     * joins positions 2j and 2j + 1, in that order.
     *
     * @return list<list<array{int, int}>> `$potSize − 1` matchings
     */
    private function potFactors(int $potSize): array
    {
        $matchings = $this->circleRounds($potSize);

        $rename = [];
        foreach ($matchings[0] as $index => [$x, $y]) {
            $rename[$x] = 2 * $index;
            $rename[$y] = 2 * $index + 1;
        }

        $factors = [];
        foreach ($matchings as $matching) {
            $factor = [];
            foreach ($matching as [$x, $y]) {
                $factor[] = [$rename[$x], $rename[$y]];
            }
            $factors[] = $factor;
        }

        return $factors;
    }

    /**
     * Give two perfect matchings of the same positions their roles so that
     * every position is first in one of its two events and second in the
     * other. Together the matchings form cycles of even length; each cycle
     * is walked once, and the position that is left is first.
     *
     * @param list<array{int, int}> $firstMatching
     * @param list<array{int, int}> $secondMatching
     * @return array{list<array{int, int}>, list<array{int, int}>} The two matchings as [first role, second role] pairs
     */
    private function orientMatchingPair(array $firstMatching, array $secondMatching, int $size): array
    {
        $partnerInFirst = [];
        foreach ($firstMatching as [$x, $y]) {
            $partnerInFirst[$x] = $y;
            $partnerInFirst[$y] = $x;
        }
        $partnerInSecond = [];
        foreach ($secondMatching as [$x, $y]) {
            $partnerInSecond[$x] = $y;
            $partnerInSecond[$y] = $x;
        }

        $visited = [];
        $orientedFirst = [];
        $orientedSecond = [];
        for ($start = 0; $start < $size; ++$start) {
            if (isset($visited[$start])) {
                continue;
            }

            $current = $start;
            do {
                $next = $partnerInFirst[$current];
                $orientedFirst[] = [$current, $next];
                $visited[$current] = true;
                $visited[$next] = true;
                $current = $partnerInSecond[$next];
                $orientedSecond[] = [$next, $current];
            } while ($current !== $start);
        }

        return [$orientedFirst, $orientedSecond];
    }

    /**
     * Whether the pot at `$rank` is ahead of the pot at `$otherRank` in the
     * circular order of `$count` pots. Of two pots exactly one is ahead of
     * the other. A pot is ahead of half of the others: exactly half when the
     * count is odd, and one more or one fewer than it is behind when the
     * count is even.
     */
    private function isAhead(int $rank, int $otherRank, int $count): bool
    {
        $distance = ($otherRank - $rank + $count) % $count;

        return 2 * $distance < $count || (2 * $distance === $count && $rank < $otherRank);
    }
}
