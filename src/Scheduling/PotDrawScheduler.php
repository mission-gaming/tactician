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
 *   repeats. The positions of B are in an order drawn for these two pots,
 *   so what one pot pair is given says nothing about another.
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
 * first gives every member one event in each role. So over a pair of
 * layers an entrant is in each role once against every pot. With k odd one
 * layer is left over, and its p rounds are each a perfect matching of the
 * whole field. They are taken in pairs in the same way: two of them form
 * cycles of even length over the field, and walking each cycle gives every
 * entrant one event in each role. With p odd as well, one round is left
 * over, and each of its events takes its roles from a coin: an entrant then
 * has one role once more than the other, which is the least an odd number
 * of events allows.
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
 *   with two different shifts, the positions of the second pot again in an
 *   order drawn for the two pots.
 *
 * That is 4 + 2 × (p − 2) = 2 × p rounds. Roles: along its cycle each
 * member is first against the next member and second against the previous
 * one; against every other pot one of the two meetings has it first.
 *
 * ## The walk
 *
 * Built as above, a draw has a shape the format does not ask for. A round
 * sets whole pots against each other, the events inside the pots share a
 * few rounds, one pot is first against another for a whole round, and the
 * pairings between two pots are rotations: with the members of each in
 * some order, position i of the one meets positions i + d of the other.
 * So the draw that was built is not the draw returned. It is the start of
 * a walk over draws: a number of steps, each of which makes the two moves
 * below. A move turns a draw that keeps every rule into another draw that
 * keeps every rule, or leaves it as it is. Nothing is searched and nothing
 * is tried again, so the walk cannot fail and always ends.
 *
 * The rules are the four proved above for the draw as built: every round
 * has every entrant once; every entrant has k opponents from every pot; no
 * two entrants meet twice; the roles are balanced as "What is drawn" says.
 *
 * ### The round exchange
 *
 * It moves events between two rounds and changes no event. Take two rounds
 * X and Y and an entrant e. Each round has every entrant exactly once, and
 * the two share no pairing, because no pairing is in the draw twice. Follow
 * the event of e in X to its opponent, that opponent's event in Y to the
 * next entrant, and so on. Every entrant has exactly one event in X and one
 * in Y, so the walk comes back to e: it closes a cycle that uses events of
 * X and of Y in turn. The entrants of the cycle are exactly the entrants of
 * its events from X, and exactly the entrants of its events from Y. So
 * moving the cycle's X events to Y and its Y events to X leaves each of
 * those entrants in X once and in Y once, and touches nobody else: both
 * rounds still have every entrant once. The set of events, and with it who
 * meets whom and in which role, is the same before and after, so the other
 * three rules hold as they did.
 *
 * ### The opponent exchange
 *
 * It changes who meets whom, inside one round. Take a round, an entrant a,
 * and another member c of the pot of a. In that round a meets b and c meets
 * d. The exchange is made when all three hold:
 *
 * 1. a and c are in the same role (so b and d are both in the other one);
 * 2. b and d are in the same pot;
 * 3. a and d meet nowhere in the draw, and neither do c and b.
 *
 * The two events become a v d and c v b, and each of the four entrants
 * keeps the role it had. When one of the three does not hold, nothing
 * changes. Rule by rule:
 *
 * - The round. The two entrants of one event are in different roles, so by
 *   condition 1 c is not b and a is not d: the events are two and the
 *   entrants four. They were in two events of the round and are in two
 *   events of it, each of them once, and no other event is touched.
 * - Opponents per pot. a gives up b for d and c gives up d for b: by
 *   condition 2, an opponent from the same pot. b gives up a for c and d
 *   gives up c for a: a and c are in one pot. Every count is what it was.
 * - No rematch. By condition 3 neither new pairing was in the draw, and
 *   they are two different pairings.
 * - Roles. Each of the four is in the role it had, against an opponent
 *   from the same pot as before. So its number of events in each role
 *   against every pot is unchanged, and with that its two totals.
 *
 * Nothing in this needs the two pots to be different. When all four
 * entrants are in one pot, they could also be paired as a v c and b v d;
 * that puts two entrants of the same role in one event, so one of each
 * pair would change role, and with it its role counts against its own pot.
 * That pairing is never made.
 *
 * With k odd the format promises only the totals of an entrant's roles, and
 * the exchange keeps more: the roles against every pot. Keeping all of it
 * is one rule for every k, and nothing has to be counted again.
 *
 * ### Where the walk goes
 *
 * Each move is undone by the same move: the same two rounds and entrant
 * trade the same cycle back, and a and c exchanging again get b and d back,
 * since the three conditions then hold the other way round. And a move is
 * drawn without looking at the draw: the rounds, the entrant and the pot
 * member come from the randomizer alone. So from any draw, a change is as
 * likely as the change that undoes it is from the draw it leads to. A walk
 * of that kind, run long enough, is as likely to be at any one of the draws
 * it can reach from its start as at any other. That is all that is claimed:
 * it is not proved that the walk can reach every draw the format allows,
 * and the walk is not run for ever ("How long the walk is").
 *
 * ### What the walk cannot change
 *
 * - One round (one pot, one opponent) has nothing to trade with, and its
 *   events are all the pairings there are. The draw is the one built: a
 *   matching of the circle method over members in a drawn order.
 * - Six entrants or fewer. A cycle has at least four entrants, because two
 *   rounds share no pairing, so two cycles need eight. Two rounds of six
 *   entrants or fewer are one cycle, and the round exchange trades the
 *   whole of both rounds, which the shuffle of the rounds does anyway.
 *   Every round keeps the pots it was built with: with 6 entrants in 3
 *   pots of 2, one pot inside itself and the other two against each other.
 * - Pots of three (so two opponents per pot). Inside the pot every pairing
 *   is played. Between two pots an entrant meets two of the three members
 *   of the other pot, once in each role. If a and c could exchange b and d,
 *   a would not have met d and c would not have met b, so the second
 *   opponent of both a and c in that pot would be its third member, and by
 *   condition 1 both would be in the same role against it; but that member
 *   is in each role against one of them only. So no opponent exchange is
 *   ever made. None is needed: the pairings between two pots of three are
 *   all but one perfect matching, and which one is left out is drawn evenly
 *   for every two pots when the draw is built. With 6 entrants in 2 pots of
 *   3 neither move changes anything.
 * - One pot with every other member as opponent is a single round robin:
 *   every pairing is played, so there is none to exchange. Its rounds are
 *   the circle method's. When the pot size less one is a prime number,
 *   every two of those rounds form one cycle through the whole pot (a
 *   known property of the circle method, which the tests check for the
 *   sizes they draw), so the round exchange changes nothing either.
 *
 * ## How long the walk is
 *
 * A step is one round exchange, with two drawn rounds and a drawn entrant,
 * and then, in one drawn round, as many offers of an opponent exchange as
 * the round has events, each to a drawn entrant and a drawn other member
 * of its pot. The walk takes
 *
 *     rounds × max(16, 1024 ÷ entrants, rounded down)
 *
 * steps. A small field takes more steps for each round because its rounds
 * form few cycles, so that more of its round exchanges trade two whole
 * rounds and change nothing.
 *
 * The two numbers were chosen by measurement. Of the 1,449 supported
 * configurations with up to 60 entrants, 1,419 have more than one round.
 * For each of them seven shares were counted, at the length above and at
 * ten times that length, over draws enough for 2,000 rounds each time:
 *
 * - the rounds made of whole pots (every pot meets one pot only, another
 *   or itself);
 * - the rounds that hold an event inside a pot;
 * - the times one pot is first throughout, out of the times two pots meet
 *   more than once in a round;
 * - the rounds whose events are listed pot pair by pot pair;
 * - the pairs of rounds whose events form a single cycle;
 * - the pot pairs whose pairings pass a test that every rotation passes
 *   (each member of a pot has the same numbers of opponents in common with
 *   the other members of its pot; with two opponents per pot, the cycles
 *   the pairings form have one length);
 * - the sets of three pots in which every member of one pot is in the same
 *   number of sets of three entrants, one from each pot, who all meet.
 *
 * The first five agree at the two lengths to within 0.05 in every
 * configuration, and to within 0.01 on average. So do the last two,
 * except where an entrant meets all but two members of every other pot:
 * there the pot pairs pass the test up to 0.07 less often than after the
 * longer walk (56 entrants in 4 pots of 14 with 12 opponents per pot: 0.70
 * against 0.77, and 1 as built), because few pairs of events can exchange
 * opponents when nearly every pairing is played. A configuration in which
 * the first count was too coarse to tell was counted again over six to
 * twelve times as many draws.
 *
 * Sixteen steps for every round are too few for a small field, which is
 * what the second number is for. The slowest field measured is 10 entrants
 * in 2 pots of 5: one pot is first throughout a block always as built,
 * 0.60 of the time after 16 steps for every round, 0.55 at the length above
 * (102 steps for every round) and 0.52 after ten times that. With 512 in
 * place of 1,024 it is 0.57, at the edge of the 0.05. A field of 64
 * entrants or more takes the 16 steps for every round; a sample of fields
 * from 64 to 200 entrants agrees with ten times as many in the same way.
 *
 * Last, the order of the rounds and the order of the events inside each
 * round are shuffled.
 *
 * ## The seed
 *
 * The seed chooses the order of the members inside each pot, the order of
 * the pots in the pot-level matchings, the matchings and shifts used, which
 * side is first, every round, entrant and pot member the walk draws, and
 * the order of the rounds and of the events in them. Between two given
 * pots, or inside one, every pairing is as likely as any other, because the
 * members of each pot are shuffled first and no move prefers one member to
 * another.
 *
 * The draw is not uniform over every schedule the format allows, and is
 * not claimed to be. It is a walk of a fixed length from a draw of a known
 * shape. What is measured is that a walk ten times as long gives the same
 * figures for that shape; what is argued is that a walk long enough is
 * uniform over the draws it can reach; what is not known is whether those
 * are all the draws there are, except for the fields listed under "What
 * the walk cannot change", where they are not.
 *
 * The same entrants, options and seed give the same schedule on every
 * call: each call builds its own `Random\Randomizer` on the
 * `Xoshiro256StarStar` engine from the seed and keeps no state between
 * calls. Nothing in the draw depends on anything but the entrants' list
 * positions and that randomizer: the walk reads its maps by entrant and by
 * round, never in the order they were filled.
 *
 * ## Cost
 *
 * Time and memory are proportional to the number of events,
 * entrants × rounds / 2. A step of the walk passes at most every entrant
 * once in the round exchange (the cycle) and makes entrants / 2 offers, so
 * it costs at most 1.5 × entrants entrant visits. For 64 entrants or more
 * that is 16 × rounds steps and at most 48 visits for each event; a
 * smaller field is walked for as long as one of 64 entrants with the same
 * number of rounds, 1,536 visits for each round. The construction and the
 * validation that follows pass every event a few times.
 *
 * @experimental
 */
class PotDrawScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * The steps the walk takes for every round of the schedule, at least.
     * Part of the output for a seed.
     */
    private const int STEPS_PER_ROUND = 16;

    /**
     * A field of fewer entrants than this number divided by
     * STEPS_PER_ROUND takes more steps for every round: this number divided
     * by its entrants. Part of the output for a seed.
     */
    private const int STEPS_PER_ROUND_TIMES_ENTRANTS = 1024;

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

        $rounds = $this->mix($rounds, count($entrants), $plan->getPotSize(), $randomizer);
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
        $pairedLayers = $opponentsPerPot - $opponentsPerPot % 2;
        $factors = $this->circleRounds($potSize);

        // Inside each pot: one perfect matching per layer. The layers of a
        // pair take their roles together; a single last layer takes its
        // roles with its rounds, below.
        $inside = [];
        for ($pot = 0; $pot < $pots; ++$pot) {
            $choice = array_slice($randomizer->shuffleArray(range(0, $potSize - 2)), 0, $opponentsPerPot);
            $layers = [];
            for ($layer = 0; $layer < $pairedLayers; $layer += 2) {
                [$layers[$layer], $layers[$layer + 1]] = $this->orientMatchingPair(
                    $factors[$choice[$layer]],
                    $factors[$choice[$layer + 1]],
                    $potSize
                );
            }
            if ($pairedLayers < $opponentsPerPot) {
                $layers[$pairedLayers] = $factors[$choice[$pairedLayers]];
            }

            foreach ($layers as $layer => $matching) {
                foreach ($matching as [$first, $second]) {
                    $inside[$pot][$layer][] = [$members[$pot][$first], $members[$pot][$second]];
                }
            }
        }

        // Between each two pots: the members of the one face those of the
        // other in an order drawn for the two, and a layer is one shift of
        // it. In a pair of layers one pot is first in one layer and the
        // other pot in the other.
        $between = [];
        for ($a = 0; $a < $pots; ++$a) {
            for ($b = $a + 1; $b < $pots; ++$b) {
                $facing = $randomizer->shuffleArray($members[$b]);
                $shifts = array_slice($randomizer->shuffleArray(range(0, $potSize - 1)), 0, $opponentsPerPot);
                $aFirst = true;
                foreach ($shifts as $layer => $shift) {
                    $aFirst = $layer % 2 === 0 ? $randomizer->getInt(0, 1) === 0 : !$aFirst;
                    for ($position = 0; $position < $potSize; ++$position) {
                        $fromA = $members[$a][$position];
                        $fromB = $facing[($position + $shift) % $potSize];
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

        // A single last layer: its rounds take their roles two at a time,
        // and a round left over takes them from a coin for each event.
        if ($pairedLayers < $opponentsPerPot) {
            $last = count($rounds) - 1;
            for ($round = $last - $pots + 1; $round < $last; $round += 2) {
                [$rounds[$round], $rounds[$round + 1]] = $this->orientMatchingPair(
                    $rounds[$round],
                    $rounds[$round + 1],
                    $pots * $potSize
                );
            }
            if ($pots % 2 === 1) {
                foreach ($rounds[$last] as $event => [$first, $second]) {
                    if ($randomizer->getInt(0, 1) === 1) {
                        $rounds[$last][$event] = [$second, $first];
                    }
                }
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
                // The members of the one face those of the other in an
                // order drawn for the two
                $facing = $randomizer->shuffleArray($members[$b]);
                $shifts = array_slice($randomizer->shuffleArray(range(0, $last)), 0, 2);

                for ($position = 0; $position < $potSize; ++$position) {
                    $pair[0][] = [$members[$a][$position], $facing[($position + $shifts[0]) % $potSize]];
                    $pair[1][] = [$facing[($position + $shifts[1]) % $potSize], $members[$a][$position]];
                }
            }
            $rounds[] = $pair[0];
            $rounds[] = $pair[1];
        }

        return $rounds;
    }

    /**
     * Walk from the constructed rounds to the rounds returned (see "The
     * walk" in the class docblock): `mixingSteps()` times, two rounds trade
     * a cycle of events and entrants of one round offer to exchange
     * opponents.
     *
     * With fewer than two rounds there is nothing to trade, and one round
     * of one opponent per pot holds no two events that could exchange
     * opponents: the rounds are returned as they are.
     *
     * @param list<list<array{int, int}>> $rounds
     * @param int $entrants How many entrants there are; every round has each of 0 .. `$entrants` − 1 once
     * @return list<list<array{int, int}>> Rounds that still have every entrant once, with every entrant
     *                                     in the roles and against the pots it had
     */
    private function mix(array $rounds, int $entrants, int $potSize, Randomizer $randomizer): array
    {
        if (count($rounds) < 2) {
            return $rounds;
        }

        // The draw as maps over the entrants: in each round, the opponent
        // of each and whether it is in the first role; over the whole draw,
        // who has met whom.
        $opponent = [];
        $isFirst = [];
        $met = [];
        foreach ($rounds as $pairs) {
            $opponents = [];
            $roles = [];
            foreach ($pairs as [$first, $second]) {
                $opponents[$first] = $second;
                $opponents[$second] = $first;
                $roles[$first] = true;
                $roles[$second] = false;
                $met[$first][$second] = true;
                $met[$second][$first] = true;
            }
            $opponent[] = $opponents;
            $isFirst[] = $roles;
        }

        for ($step = $this->mixingSteps($entrants, count($rounds)); $step > 0; --$step) {
            $this->exchangeRounds($opponent, $isFirst, $entrants, $randomizer);
            $this->exchangeOpponents($opponent, $isFirst, $met, $entrants, $potSize, $randomizer);
        }

        $mixed = [];
        foreach ($opponent as $round => $opponents) {
            $pairs = [];
            for ($entrant = 0; $entrant < $entrants; ++$entrant) {
                if ($isFirst[$round][$entrant]) {
                    $pairs[] = [$entrant, $opponents[$entrant]];
                }
            }
            $mixed[] = $pairs;
        }

        return $mixed;
    }

    /**
     * How many steps the walk takes: the rule, and the only place it is
     * stated in code. "How long the walk is" in the class docblock says
     * what the two numbers were measured against.
     */
    private function mixingSteps(int $entrants, int $rounds): int
    {
        return $rounds * max(self::STEPS_PER_ROUND, intdiv(self::STEPS_PER_ROUND_TIMES_ENTRANTS, $entrants));
    }

    /**
     * The round exchange: two drawn rounds trade the cycle of events that
     * runs through a drawn entrant.
     *
     * From the entrant, its event in the one round leads to its opponent,
     * that opponent's event in the other round to the next entrant, and so
     * on until the walk is back where it began. The entrants passed are in
     * each of the two rounds exactly once, in events of the cycle only, so
     * when those events change rounds both rounds still have every entrant
     * once. No event is changed.
     *
     * @param list<array<int, int>> $opponent Round => entrant => its opponent in that round
     * @param list<array<int, bool>> $isFirst Round => entrant => whether it is in the first role
     * @param-out list<array<int, int>> $opponent
     * @param-out list<array<int, bool>> $isFirst
     */
    private function exchangeRounds(array &$opponent, array &$isFirst, int $entrants, Randomizer $randomizer): void
    {
        $x = $randomizer->getInt(0, count($opponent) - 1);
        $y = $randomizer->getInt(0, count($opponent) - 2);
        if ($y >= $x) {
            ++$y;
        }
        $start = $randomizer->getInt(0, $entrants - 1);

        $opponentX = $opponent[$x];
        $opponentY = $opponent[$y];
        $isFirstX = $isFirst[$x];
        $isFirstY = $isFirst[$y];

        $entrant = $start;
        do {
            $other = $opponentX[$entrant];
            $next = $opponentY[$other];
            foreach ([$entrant, $other] as $member) {
                $inX = $opponentX[$member];
                $opponentX[$member] = $opponentY[$member];
                $opponentY[$member] = $inX;
                $firstInX = $isFirstX[$member];
                $isFirstX[$member] = $isFirstY[$member];
                $isFirstY[$member] = $firstInX;
            }
            $entrant = $next;
        } while ($entrant !== $start);

        $opponent[$x] = $opponentX;
        $opponent[$y] = $opponentY;
        $isFirst[$x] = $isFirstX;
        $isFirst[$y] = $isFirstY;
    }

    /**
     * The opponent exchange: in one drawn round, as many times as the round
     * has events, a drawn entrant and a drawn other member of its pot
     * exchange opponents if the format allows it.
     *
     * With `a v b` and `c v d` the events of the two, it allows it when `a`
     * and `c` are in the same role, `b` and `d` are in one pot, and neither
     * `a` and `d` nor `c` and `b` meet anywhere in the draw. The events
     * become `a v d` and `c v b`, every one of the four in the role it had.
     * An offer the format does not allow changes nothing.
     *
     * @param list<array<int, int>> $opponent Round => entrant => its opponent in that round
     * @param list<array<int, bool>> $isFirst Round => entrant => whether it is in the first role
     * @param array<int, array<int, true>> $met Entrant => the entrants it meets anywhere in the draw
     * @param-out list<array<int, int>> $opponent
     * @param-out array<int, array<int, true>> $met
     */
    private function exchangeOpponents(
        array &$opponent,
        array $isFirst,
        array &$met,
        int $entrants,
        int $potSize,
        Randomizer $randomizer
    ): void {
        $round = $randomizer->getInt(0, count($opponent) - 1);
        $opponents = $opponent[$round];
        $roles = $isFirst[$round];

        for ($offer = intdiv($entrants, 2); $offer > 0; --$offer) {
            $a = $randomizer->getInt(0, $entrants - 1);
            // Another member of the pot of $a, each as likely as the others
            $c = $a - $a % $potSize + $randomizer->getInt(0, $potSize - 2);
            if ($c >= $a) {
                ++$c;
            }
            $b = $opponents[$a];
            $d = $opponents[$c];

            if ($roles[$a] !== $roles[$c]
                || intdiv($b, $potSize) !== intdiv($d, $potSize)
                || isset($met[$a][$d])
                || isset($met[$c][$b])
            ) {
                continue;
            }

            $opponents[$a] = $d;
            $opponents[$d] = $a;
            $opponents[$c] = $b;
            $opponents[$b] = $c;
            unset($met[$a][$b], $met[$b][$a], $met[$c][$d], $met[$d][$c]);
            $met[$a][$d] = true;
            $met[$d][$a] = true;
            $met[$c][$b] = true;
            $met[$b][$c] = true;
        }

        $opponent[$round] = $opponents;
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
}
