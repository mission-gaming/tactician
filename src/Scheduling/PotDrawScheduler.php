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
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
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
 * A draw is made in three steps, each with its own section below: who
 * meets whom in which round is built; a walk changes it; and then every
 * event is given its roles.
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
 * That is 4 + 2 × (p − 2) = 2 × p rounds.
 *
 * ## The walk
 *
 * Built as above, a draw has a shape the format does not ask for. A round
 * sets whole pots against each other, the events inside the pots share a
 * few rounds, and the pairings between two pots are rotations: with the
 * members of each in some order, position i of the one meets positions
 * i + d of the other. So the draw that was built is not the draw returned.
 * It is the start of a walk over draws: a number of steps, each of which
 * makes the two moves below. A move turns a draw that keeps every rule
 * into another draw that keeps every rule, or leaves it as it is. Nothing
 * is searched and nothing is tried again, so the walk cannot fail and
 * always ends.
 *
 * The rules are the three proved above for the draw as built: every round
 * has every entrant once; every entrant has k opponents from every pot; no
 * two entrants meet twice. The walk has nothing to do with roles: no event
 * has any yet.
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
 * meets whom, is the same before and after, so the other two rules hold as
 * they did.
 *
 * ### The opponent exchange
 *
 * It changes who meets whom, inside one round. Take a round, an entrant a,
 * and another member c of the pot of a. In that round a meets b and c meets
 * d. The exchange is made when all three hold:
 *
 * 1. a and c do not meet each other in this round (b is not c);
 * 2. b and d are in the same pot;
 * 3. a and d meet nowhere in the draw, and neither do c and b.
 *
 * The two events become a v d and c v b. When one of the three does not
 * hold, nothing changes. Rule by rule:
 *
 * - The round. By condition 1 the events are two and the entrants four.
 *   They were in two events of the round and are in two events of it, each
 *   of them once, and no other event is touched.
 * - Opponents per pot. a gives up b for d and c gives up d for b: by
 *   condition 2, an opponent from the same pot. b gives up a for c and d
 *   gives up c for a: a and c are in one pot. Every count is what it was.
 * - No rematch. By condition 3 neither new pairing was in the draw, and
 *   they are two different pairings.
 *
 * Nothing in this needs the two pots to be different. When all four
 * entrants are in one pot, they can also be paired as a v c and b v d;
 * that is the same exchange offered to a and d.
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
 * - One round (one pot, one opponent) has nothing to trade with. The draw
 *   is the one built: a matching of the circle method over members in a
 *   drawn order, which makes every pairing of the field as likely as any
 *   other.
 * - Six entrants in 3 pots of 2. A cycle of two rounds has at least four
 *   entrants, because two rounds share no pairing, so two cycles need
 *   eight: two rounds of six entrants are one cycle, and the round exchange
 *   trades the whole of both, which the shuffle of the rounds does anyway.
 *   The opponent exchange is made, and it turns a round of whole pots into
 *   another: every round has one pot inside itself and the other two
 *   against each other, as built. Of the 72 ways to place the nine events
 *   the format allows, the 48 with such rounds are drawn, each as often as
 *   any other; the other 24 have rounds that mix the three pots, and no
 *   move reaches them.
 * - One pot with every other member as opponent is a single round robin:
 *   every pairing is played, so there is none to exchange. Its rounds are
 *   the circle method's. When the pot size less one is a prime number,
 *   every two of those rounds form one cycle through the whole pot (a
 *   known property of the circle method, which the tests check for the
 *   sizes they draw), so the round exchange changes nothing either. The
 *   same holds for 6 entrants in 2 pots of 3: no pairing can be exchanged
 *   and every two rounds are one cycle. What is built is then all there is
 *   to draw, and for those 6 entrants it is every placing the format
 *   allows, each as often as any other.
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
 * For each of them six shares were counted, at the length above and at
 * ten times that length, over draws enough for 2,000 rounds each time:
 *
 * - the rounds made of whole pots (every pot meets one pot only, another
 *   or itself);
 * - the rounds that hold an event inside a pot;
 * - the rounds whose events are listed pot pair by pot pair;
 * - the pairs of rounds whose events form a single cycle;
 * - the pot pairs whose pairings pass a test that every rotation passes
 *   (each member of a pot has the same numbers of opponents in common with
 *   the other members of its pot; with two opponents per pot, the cycles
 *   the pairings form have one length);
 * - the sets of three pots in which every member of one pot is in the same
 *   number of sets of three entrants, one from each pot, who all meet.
 *
 * The first four agree at the two lengths to within 0.05 in every
 * configuration (0.04 at most), and to within 0.005 on average. The last
 * two are counted over few pot pairs and few sets of three pots in the
 * largest fields; a configuration in which the first count was too coarse
 * to tell was counted again over six to eight times as many draws, or over
 * more, and then they agree to within 0.05 as well. The widest gap that
 * stayed is in the fields where an entrant meets all but two members of
 * every other pot, in which few events can exchange opponents: 60 entrants
 * in 3 pots of 20 with 18 opponents per pot pass the pot pair test 0.75 of
 * the time, against 0.78 after the longer walk and always as built.
 *
 * What agrees at the two lengths is the shape of a draw, not the draw: a
 * walk of this length has not forgotten where it started. With 60 entrants
 * in 15 pots of 4, 36 in 100 of the pairings are still the ones built,
 * against 25 after ten times the steps and after forty times, which is
 * what chance gives. Nothing of
 * that shows in a draw, because the pairings between two pots are drawn
 * evenly as they are built, and the numbers of sets of three and of four
 * entrants who all meet across pots are the same at the three lengths.
 *
 * Sixteen is the least of the numbers tried that is enough: with 8 steps for
 * every round, 54 entrants in 27 pots of 2 have 0.49 of their rounds listed
 * pot pair by pot pair, where both longer walks have 0.62. The second
 * number is for the smallest fields, which 16 steps for every round leave
 * a little short: 8 entrants in 2 pots of 4 with three opponents per pot
 * have 0.37 of their rounds made of whole pots after 16 steps for every
 * round, and 0.33 at the length above (128 steps for every round) and at
 * ten times that; as built it is all of them. A field of 64 entrants or
 * more takes the 16 steps for every round; a sample of fields from 64 to
 * 140 entrants agrees with ten times as many in the same way.
 *
 * ## The roles
 *
 * Roles are given last, to the draw the walk ended on, by one rule for
 * every configuration. Every entrant puts its events in twos, and is first
 * in one event of a two and second in the other:
 *
 * - It takes its events in a drawn order. An event goes with the event
 *   before it against the same pot that is still waiting for one. With k
 *   even that puts all of them in twos, k / 2 against every pot.
 * - With k odd, one event against each pot is left waiting. Those go in
 *   twos as well, in the order they began to wait. With p odd too the
 *   entrant has an odd number of events, and the last of them stays alone.
 *
 * An event has two entrants, and each of them put it with at most one
 * other event. So the events hang together in trails: from an event, on to
 * the event one of its entrants put it with, to the event the other
 * entrant of that one put it with, and so on. A trail either closes, or it
 * ends at both ends with an event an entrant left alone. One coin decides
 * a whole trail: it gives the roles of its first event, and from there
 * every entrant passed is in the other role than it was in the event
 * before. A closed trail agrees with itself when it comes round, because
 * the entrant it started with put the last event with the first.
 *
 * So an entrant is first in exactly one event of every two it made. That
 * is first k / 2 times against every pot when k is even; and when k is
 * odd, first and second equally often but for one event against every
 * pot, and equally often over all its events but for the one left alone,
 * if there is one. The role counts of an entrant differ by at most one,
 * and against any one pot by at most one as well, which is more than the
 * format asks when k is odd.
 *
 * Every assignment of roles with those counts can be drawn. Take one:
 * against each pot an entrant can put each event in which it is first
 * with one in which it is second, with one left over when k is odd; the
 * events left over are first and second equally often but for one, and go
 * together in the same way. The drawn order can give those twos, and the
 * coins can fall with them. They are not all drawn equally often: an
 * assignment is the more likely the more ways of putting the events in
 * twos lead to it. With two opponents
 * per pot they are: an entrant has one way to put its events in twos, the
 * trails are the cycles those twos make, and every cycle takes a coin of
 * its own. For the fields small enough to list every assignment ("The
 * seed"), each one was drawn, and the assignments of one draw came within
 * a few in a hundred of equally often.
 *
 * No entrant is more likely to be first than another, because the order
 * of its events and the coins are drawn without looking at who it is.
 *
 * ## The seed
 *
 * The seed chooses the order of the members inside each pot, the order of
 * the pots in the pot-level matchings, the matchings and shifts used, every
 * round, entrant and pot member the walk draws, the order in which every
 * entrant takes its events and the coin of every trail, and the order of
 * the rounds and of the events in them. Between two given pots, or inside
 * one, every pairing is as likely as any other, because the members of
 * each pot are shuffled first and no move prefers one member to another.
 *
 * The draw is not uniform over every schedule the format allows, and is
 * not claimed to be. It is a walk of a fixed length from a draw of a known
 * shape, and then one way of giving roles. What is measured is that a walk
 * ten times as long gives the same figures for that shape; what is argued
 * is that a walk long enough is uniform over the draws it can reach; what
 * is not known is whether those are all the draws there are, except for
 * the fields listed under "What the walk cannot change", where it is
 * known either way.
 *
 * For a few of the smallest fields every draw the format allows was
 * listed, and the draws of 240,000 seeds or more were counted against the
 * list. Who meets whom in which round: 8 entrants in 2 pots of 4 with one
 * opponent per pot (576 ways), 6 in 2 pots of 3 (288) and 6 as one pot
 * with four opponents (720) give every way as often as any other, as far
 * as that many seeds can tell, and 6 entrants in 3 pots of 2 give 48 of
 * their 72 ("What the walk cannot change"). With the roles: every
 * assignment the format allows was drawn in each of them (1,728 draws,
 * 2,304, 27,360, and 4,944 for the 48 ways). The walk is even over who
 * meets whom and when, and the roles come after it, so a way of meeting
 * that allows more assignments of roles is not drawn more often for that:
 * a draw with its roles is the less likely the more assignments its
 * pairings allow. With 8 entrants in 2 pots of 4, half of the 576 ways
 * allow two assignments and half allow four.
 *
 * The same entrants, options and seed give the same schedule on every
 * call: each call builds its own `Random\Randomizer` on the
 * `Xoshiro256StarStar` engine from the seed and keeps no state between
 * calls. Nothing in the draw depends on anything but the entrants' list
 * positions and that randomizer: the walk and the roles read their maps by
 * entrant and by round, never in the order they were filled.
 *
 * ## Cost
 *
 * Time and memory are proportional to the number of events,
 * entrants × rounds / 2. A step of the walk passes at most every entrant
 * once in the round exchange (the cycle) and makes entrants / 2 offers, so
 * it costs at most 1.5 × entrants entrant visits. For 64 entrants or more
 * that is 16 × rounds steps and at most 48 visits for each event; a
 * smaller field is walked for as long as one of 64 entrants with the same
 * number of rounds, 1,536 visits for each round. The roles pass every
 * event twice at each of its entrants, once to put it in a two and once on
 * its trail. The construction and the validation that follows pass every
 * event a few times.
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

        $opponent = $this->walk($rounds, count($entrants), $plan->getPotSize(), $randomizer);
        $isFirst = $this->drawRoles($opponent, count($entrants), $plan->getPotSize(), $randomizer);

        $events = [];
        foreach ($randomizer->shuffleArray(array_keys($opponent)) as $index => $drawn) {
            $pairs = [];
            foreach ($entrants as $entrant => $participant) {
                if ($isFirst[$drawn][$entrant]) {
                    $pairs[] = [$participant, $entrants[$opponent[$drawn][$entrant]]];
                }
            }

            $round = new Round($index + 1);
            foreach ($randomizer->shuffleArray($pairs) as $pair) {
                $events[] = new Event($pair, $round);
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
     * An entrant is the index of its place in the participant list. A pair
     * is two entrants who meet; which of them is first is not decided here
     * (`drawRoles()`).
     *
     * @return list<list<array{int, int}>>
     */
    private function drawEvenPots(int $pots, int $potSize, int $opponentsPerPot, Randomizer $randomizer): array
    {
        $members = $this->shuffledPots($pots, $potSize, $randomizer);
        $potOrder = $randomizer->shuffleArray(range(0, $pots - 1));
        $factors = $this->circleRounds($potSize);

        // Inside each pot: one perfect matching per layer
        $inside = [];
        for ($pot = 0; $pot < $pots; ++$pot) {
            $choice = array_slice($randomizer->shuffleArray(range(0, $potSize - 2)), 0, $opponentsPerPot);
            foreach ($choice as $layer => $factor) {
                foreach ($factors[$factor] as [$x, $y]) {
                    $inside[$pot][$layer][] = [$members[$pot][$x], $members[$pot][$y]];
                }
            }
        }

        // Between each two pots: the members of the one face those of the
        // other in an order drawn for the two, and a layer is one shift of
        // it
        $between = [];
        for ($a = 0; $a < $pots; ++$a) {
            for ($b = $a + 1; $b < $pots; ++$b) {
                $facing = $randomizer->shuffleArray($members[$b]);
                $shifts = array_slice($randomizer->shuffleArray(range(0, $potSize - 1)), 0, $opponentsPerPot);
                foreach ($shifts as $layer => $shift) {
                    for ($position = 0; $position < $potSize; ++$position) {
                        $between[$a][$b][$layer][] = [$members[$a][$position], $facing[($position + $shift) % $potSize]];
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
     * class docblock). The number of pots is even. Pairs are as in
     * `drawEvenPots()`.
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
            $a = $potOrder[$x];
            $b = $potOrder[$y];
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
                $partnerRounds[3][] = [$members[$a][$position], $members[$b][($position + $shift) % $potSize]];
            }
        }
        foreach ($partnerRounds as $round) {
            $rounds[] = $round;
        }

        // Every other matching of the pots: two rounds, two shifts.
        foreach (array_slice($potMatchings, 1) as $matching) {
            $pair = [[], []];
            foreach ($matching as [$x, $y]) {
                $a = $potOrder[$x];
                // The members of the one face those of the other in an
                // order drawn for the two
                $facing = $randomizer->shuffleArray($members[$potOrder[$y]]);
                $shifts = array_slice($randomizer->shuffleArray(range(0, $last)), 0, 2);

                for ($position = 0; $position < $potSize; ++$position) {
                    $pair[0][] = [$members[$a][$position], $facing[($position + $shifts[0]) % $potSize]];
                    $pair[1][] = [$members[$a][$position], $facing[($position + $shifts[1]) % $potSize]];
                }
            }
            $rounds[] = $pair[0];
            $rounds[] = $pair[1];
        }

        return $rounds;
    }

    /**
     * Walk from the constructed rounds to the rounds returned (see "The
     * walk" in the class docblock): `walkLength()` times, two rounds trade
     * a cycle of events and entrants of one round offer to exchange
     * opponents.
     *
     * One round has no round to trade with, and it is drawn evenly as it is
     * built (one pot, one opponent: "What the walk cannot change"), so it
     * is returned as it is.
     *
     * @param list<list<array{int, int}>> $rounds
     * @param int $entrants How many entrants there are; every round has each of 0 .. `$entrants` − 1 once
     * @return list<array<int, int>> Round => entrant => its opponent in that round. Every round still has
     *                               every entrant once, with as many opponents from every pot as it had
     */
    private function walk(array $rounds, int $entrants, int $potSize, Randomizer $randomizer): array
    {
        // The draw as maps over the entrants: in each round, the opponent
        // of each; over the whole draw, who has met whom.
        $opponent = [];
        $met = [];
        foreach ($rounds as $pairs) {
            $opponents = [];
            foreach ($pairs as [$one, $other]) {
                $opponents[$one] = $other;
                $opponents[$other] = $one;
                $met[$one][$other] = true;
                $met[$other][$one] = true;
            }
            $opponent[] = $opponents;
        }

        if (count($opponent) < 2) {
            return $opponent;
        }

        for ($step = $this->walkLength($entrants, count($opponent)); $step > 0; --$step) {
            $this->exchangeRounds($opponent, $entrants, $randomizer);
            $this->exchangeOpponents($opponent, $met, $entrants, $potSize, $randomizer);
        }

        return $opponent;
    }

    /**
     * How many steps the walk takes: the rule, and the only place it is
     * stated in code. "How long the walk is" in the class docblock says
     * what the two numbers were measured against.
     */
    private function walkLength(int $entrants, int $rounds): int
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
     * @param-out list<array<int, int>> $opponent
     */
    private function exchangeRounds(array &$opponent, int $entrants, Randomizer $randomizer): void
    {
        $x = $randomizer->getInt(0, count($opponent) - 1);
        $y = $randomizer->getInt(0, count($opponent) - 2);
        if ($y >= $x) {
            ++$y;
        }
        $start = $randomizer->getInt(0, $entrants - 1);

        $opponentX = $opponent[$x];
        $opponentY = $opponent[$y];

        $entrant = $start;
        $passed = 0;
        do {
            if ($passed === $entrants) {
                // Not reached: both rounds have every entrant once, so the
                // cycle is back at its start before it has passed them all
                throw new InvariantViolationException('A cycle of two rounds of a pot draw did not close');
            }

            $other = $opponentX[$entrant];
            $next = $opponentY[$other];
            foreach ([$entrant, $other] as $member) {
                $inX = $opponentX[$member];
                $opponentX[$member] = $opponentY[$member];
                $opponentY[$member] = $inX;
            }
            $entrant = $next;
            $passed += 2;
        } while ($entrant !== $start);

        $opponent[$x] = $opponentX;
        $opponent[$y] = $opponentY;
    }

    /**
     * The opponent exchange: in one drawn round, as many times as the round
     * has events, a drawn entrant and a drawn other member of its pot
     * exchange opponents if the format allows it.
     *
     * With `a v b` and `c v d` the events of the two, it allows it when
     * they are two events (`a` does not meet `c` in this round), `b` and
     * `d` are in one pot, and neither `a` and `d` nor `c` and `b` meet
     * anywhere in the draw. The events become `a v d` and `c v b`. An offer
     * the format does not allow changes nothing.
     *
     * @param list<array<int, int>> $opponent Round => entrant => its opponent in that round
     * @param array<int, array<int, true>> $met Entrant => the entrants it meets anywhere in the draw
     * @param-out list<array<int, int>> $opponent
     * @param-out array<int, array<int, true>> $met
     */
    private function exchangeOpponents(
        array &$opponent,
        array &$met,
        int $entrants,
        int $potSize,
        Randomizer $randomizer
    ): void {
        $round = $randomizer->getInt(0, count($opponent) - 1);
        $opponents = $opponent[$round];

        for ($offer = intdiv($entrants, 2); $offer > 0; --$offer) {
            $a = $randomizer->getInt(0, $entrants - 1);
            // Another member of the pot of $a, each as likely as the others
            $c = $a - $a % $potSize + $randomizer->getInt(0, $potSize - 2);
            if ($c >= $a) {
                ++$c;
            }
            $b = $opponents[$a];
            $d = $opponents[$c];

            if ($b === $c
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
     * Give every event its roles (see "The roles" in the class docblock).
     *
     * Every entrant puts its events in twos, and is first in one event of
     * a two and second in the other. The twos join the events into trails,
     * and one coin for a trail decides every role on it.
     *
     * @param list<array<int, int>> $opponent Round => entrant => its opponent in that round
     * @return list<array<int, bool>> Round => entrant => whether it is in the first role
     */
    private function drawRoles(array $opponent, int $entrants, int $potSize, Randomizer $randomizer): array
    {
        $rounds = array_keys($opponent);

        // Entrant => round => the round of the event that the entrant's
        // event of this round is put with
        $putWith = [];
        // The one event an entrant puts with no other, when it has an odd
        // number: [entrant, round]
        $alone = [];
        for ($entrant = 0; $entrant < $entrants; ++$entrant) {
            // In a drawn order, an event goes with the event before it
            // against the same pot that is still waiting for one
            $waiting = [];
            foreach ($randomizer->shuffleArray($rounds) as $round) {
                $pot = intdiv($opponent[$round][$entrant], $potSize);
                if (isset($waiting[$pot])) {
                    $putWith[$entrant][$round] = $waiting[$pot];
                    $putWith[$entrant][$waiting[$pot]] = $round;
                    unset($waiting[$pot]);
                } else {
                    $waiting[$pot] = $round;
                }
            }

            // With an odd number of opponents per pot one event against
            // each pot is still waiting. They go in twos in the order they
            // began to wait, which is a drawn order of the pots
            $left = array_values($waiting);
            for ($index = 0; $index + 1 < count($left); $index += 2) {
                $putWith[$entrant][$left[$index]] = $left[$index + 1];
                $putWith[$entrant][$left[$index + 1]] = $left[$index];
            }
            if (count($left) % 2 === 1) {
                $alone[] = [$entrant, $left[count($left) - 1]];
            }
        }

        // A trail that ends is walked from an end, so the ends come first;
        // what is left after them is trails that close
        $isFirst = array_fill(0, count($opponent), []);
        foreach ($alone as [$entrant, $round]) {
            if (!isset($isFirst[$round][$entrant])) {
                $this->walkTrail($isFirst, $opponent, $putWith, $entrant, $round, $randomizer->getInt(0, 1) === 0);
            }
        }
        foreach ($rounds as $round) {
            for ($entrant = 0; $entrant < $entrants; ++$entrant) {
                if (!isset($isFirst[$round][$entrant])) {
                    $this->walkTrail($isFirst, $opponent, $putWith, $entrant, $round, $randomizer->getInt(0, 1) === 0);
                }
            }
        }

        return $isFirst;
    }

    /**
     * Give the events of one trail their roles, from the event of
     * `$entrant` in `$round` onwards.
     *
     * The entrant has the role `$leads` says, so its opponent has the
     * other one; the opponent has the role of `$leads` again in the event
     * it put with this one, and so on, until an entrant put the event with
     * no other or the next event has its roles already. Every pass gives
     * roles to an event that had none, so the loop ends whatever it is
     * given.
     *
     * @param list<array<int, bool>> $isFirst Round => entrant => whether it is in the first role, as far as given
     * @param list<array<int, int>> $opponent Round => entrant => its opponent in that round
     * @param array<int, array<int, int>> $putWith Entrant => round => the round of the event put with it
     * @param-out list<array<int, bool>> $isFirst
     */
    private function walkTrail(
        array &$isFirst,
        array $opponent,
        array $putWith,
        int $entrant,
        int $round,
        bool $leads
    ): void {
        do {
            $other = $opponent[$round][$entrant];
            $isFirst[$round][$entrant] = $leads;
            $isFirst[$round][$other] = !$leads;
            $entrant = $other;
            $round = $putWith[$entrant][$round] ?? null;
        } while ($round !== null && !isset($isFirst[$round][$entrant]));
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
}
