<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionReason;
use MissionGaming\Tactician\Serialization\PlainData;
use MissionGaming\Tactician\Stage\FormatMetadataKey;
use MissionGaming\Tactician\Stage\PairKey;
use ReflectionException;
use ReflectionProperty;

/**
 * Calculates an ordered standings table from recorded results.
 *
 * Entries are ordered, best first, by the ranking strategy's primary value
 * (higher first), then by each configured tiebreaker in order (higher
 * first), then by score difference and by scores-for (higher first). What
 * is still level after that is ordered by a fallback that reflects no
 * result: seed (lower first, unseeded participants last), then participant
 * ID. Standings::getTiedSets() names the entries only the fallback
 * separates.
 *
 * The fallback is there so that no two entries compare equal and the order
 * the participants are given in does not show in the table: a table
 * calculated on its own is the same for every order of its participants
 * array. A label is never compared. IDs are ordered as a total order over
 * every string: an ID written as a whole number without a sign or a leading
 * zero comes first, in the order of the values ('9' before '10'), and every
 * other ID after those, byte by byte ('10' before '01', 'p10' before 'p2').
 *
 * That fallback is for a table that stands alone. Where the participants
 * are the entrant list of a stage, the position in that list decides
 * between level entries, and neither the seed attribute nor the ID does:
 * the engines order their tables that way, and
 * Standings::withTiedSetsOrderedBy() does it for a table of your own
 * (docs/adr/0004-position-decides-where-results-do-not.md).
 *
 * Three kinds of fact that are no play are counted as the application
 * records them. An awarded result (DTO\Result::isAwarded()) is a result of
 * the table, with two choices left to the caller (AwardedResultPolicy). An
 * adjustment (Adjustment) is an amount of ranking value for one
 * participant, shown as a figure of its own. A bye is a round a participant
 * sat out: it is counted as a figure of its own, and credited with what
 * the ranking strategy says a bye is worth (RankingStrategyWithByeValue).
 * calculateWith() takes all three with the results; withAdjustments() and
 * withByesCredited() apply the last two to a table that exists. None
 * changes how a table is ordered: by the same figures, with the same
 * fallback.
 *
 * The order of entries that are still level is the application's to
 * decide, and is given to the table the calculator returns
 * (Standings::withDecisions()), after byes are credited and adjustments
 * applied. The calculator takes no decision itself: which entries are
 * level is read from the table.
 *
 * @experimental
 */
readonly class StandingsCalculator
{
    /**
     * @param RankingStrategy $rankingStrategy Computes the primary ranking value
     *                                         (default: 3/1/0 win-draw-loss points)
     * @param array<TiebreakerInterface> $tiebreakers Applied in order after the primary ranking value
     * @param AwardedResultPolicy $awardedResults What is done with an awarded result where a
     *                                            competition chooses: whether its scores count,
     *                                            and whether a tiebreaker between the tied
     *                                            participants only reads it (default: both).
     *                                            Changes nothing where no result is awarded
     *
     * @throws InvalidInputException When the tiebreakers hold one that is computed between the
     *                               tied participants (TiedParticipantsTiebreaker) and two of
     *                               them have one name. A value is kept on an entry, and
     *                               compared, under the name of its tiebreaker, and a
     *                               tiebreaker of that kind has another value after a
     *                               tiebreaker of another kind, where the tied sets are
     *                               smaller: the later one would replace the earlier and
     *                               order the table in its place. A chain without such a
     *                               tiebreaker is not checked, as before
     */
    public function __construct(
        private RankingStrategy $rankingStrategy = new WinDrawLossRanking(),
        private array $tiebreakers = [],
        private AwardedResultPolicy $awardedResults = new AwardedResultPolicy()
    ) {
        $betweenTheTied = false;
        $named = [];
        $repeated = null;
        foreach ($tiebreakers as $tiebreaker) {
            $betweenTheTied = $betweenTheTied || $tiebreaker instanceof TiedParticipantsTiebreaker;

            $name = $tiebreaker->getName();
            $repeated ??= isset($named[$name]) ? $name : null;
            $named[$name] = true;
        }

        if ($betweenTheTied && $repeated !== null) {
            throw new InvalidInputException(
                "The tiebreaker '{$repeated}' is in the chain twice. With a tiebreaker between the tied participants in"
                    . ' the chain every tiebreaker needs a name of its own: a value is kept and compared under the name,'
                    . ' and such a tiebreaker can have another value at another place'
            );
        }
    }

    /**
     * Build a calculator from plain configuration data:
     *
     *     StandingsCalculator::fromArray([
     *         'ranking' => ['win' => 3, 'draw' => 1, 'loss' => 0, 'bye' => 'draw'],
     *         'tiebreakers' => ['between-tied:ranking-value', 'score-difference', 'wins'],
     *         'awarded_results' => ['count_scores' => false],
     *     ]);
     *
     * `ranking` is the form of WinDrawLossRanking::fromArray(),
     * `tiebreakers` the list of TiebreakerChain::fromIdentifiers() and
     * `awarded_results` the form of AwardedResultPolicy::fromArray(). Each
     * may be left out, or be null, and is then what a calculator built
     * without arguments has: 3/1/0, no tiebreaker, awarded results counted
     * in full. Any other key is refused.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), one of the three values is not
     *                                       an array (`WrongValueType`), or one of the three
     *                                       readers refuses its part
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['ranking', 'tiebreakers', 'awarded_results'], 'standings calculator');

        $parts = [];
        foreach (['ranking', 'tiebreakers', 'awarded_results'] as $key) {
            $part = $config[$key] ?? [];
            if (!is_array($part)) {
                throw new InvalidConfigurationException(
                    "The '{$key}' of a standings calculator must be an array",
                    ['key' => $key, 'value' => $part],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            $parts[$key] = $part;
        }

        return new self(
            WinDrawLossRanking::fromArray($parts['ranking']),
            TiebreakerChain::fromIdentifiers($parts['tiebreakers']),
            AwardedResultPolicy::fromArray($parts['awarded_results'])
        );
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then `ranking`, `tiebreakers` and
     * `awarded_results`, always. The three parts are written without a
     * version key of their own.
     *
     * @return array{format_version: int, ranking: array<string, float>, tiebreakers: list<string|array{tiebreaker: string, byes: string}>, awarded_results: array<string, bool>}
     *
     * @throws InvalidConfigurationException When the calculator cannot be written as plain data: it
     *                                       is a subclass, its ranking strategy is not a
     *                                       WinDrawLossRanking, or a tiebreaker is not one of the
     *                                       library's (reason `NotSerializable`)
     */
    public function toArray(): array
    {
        if (static::class !== self::class || !$this->rankingStrategy instanceof WinDrawLossRanking) {
            throw new InvalidConfigurationException(
                'Only a StandingsCalculator of the library with a WinDrawLossRanking can be written as plain data',
                ['calculator' => static::class, 'ranking_strategy' => $this->rankingStrategy::class],
                reason: InvalidConfigurationReason::NotSerializable
            );
        }

        $ranking = $this->rankingStrategy->toArray();
        $awardedResults = $this->awardedResults->toArray();
        unset($ranking[PlainData::VERSION_KEY], $awardedResults[PlainData::VERSION_KEY]);

        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'ranking' => $ranking,
            'tiebreakers' => TiebreakerChain::identifiersOf($this->tiebreakers),
            'awarded_results' => $awardedResults,
        ];
    }

    /**
     * What this calculator does with an awarded result where a competition
     * chooses.
     */
    public function getAwardedResultPolicy(): AwardedResultPolicy
    {
        return $this->awardedResults;
    }

    /**
     * The strategy that computes each entry's primary ranking value.
     */
    public function getRankingStrategy(): RankingStrategy
    {
        return $this->rankingStrategy;
    }

    /**
     * The tiebreakers, in the order they are applied.
     *
     * @return array<TiebreakerInterface>
     */
    public function getTiebreakers(): array
    {
        return $this->tiebreakers;
    }

    /**
     * Calculate the table of the given participants from the given results.
     *
     * The table has one entry per participant ID, in the order the class
     * describes. For each participant, over the results whose event it is
     * in: played counts them, a result that names it the winner is a win, a
     * result with no winner is a draw and any other is a loss (in an event
     * of three, both who did not win have a loss); scores-for adds its own
     * recorded scores and scores-against those of every other participant
     * of the event. A bye is not a result and is counted nowhere here:
     * calculateWith() takes the byes of a table.
     *
     * The tiebreakers are asked in the order they were given. Each is
     * handed every result and the entries of the results alone, with two
     * exceptions. A TiebreakerWithByeTreatment is handed entries that carry
     * the byes of the table, of which this method has none. A
     * TiedParticipantsTiebreaker is asked once for each tied set, with the
     * results between the participants of the set and the entries those
     * results give. A tied set is the participants with the same ranking
     * value and the same value for every tiebreaker before the run it is
     * in: tiebreakers of this kind that follow one another are a run, and
     * all of a run are asked about the same sets, those level when the
     * first of them was asked, so that together they are one small table
     * of the events among the level participants. The interface has the
     * contract.
     *
     * An awarded result (DTO\Result::isAwarded()) is a result: it is
     * counted in played and as the win, draw or loss it is, it is handed to
     * the ranking strategy, and StandingEntry::getAwarded() counts it
     * besides. The awarded result policy of the calculator decides two
     * things. With its countScores off, the scores of an awarded result are
     * added to no scores-for and no scores-against. With its
     * countBetweenTiedParticipants off, a tiebreaker that is a
     * TiedParticipantsTiebreaker is handed the results without the awarded
     * ones; every other tiebreaker is handed all of them.
     *
     * Adjustments are no part of this calculation: calculateWith() takes
     * them with the results, and withAdjustments() applies them to a table
     * that exists.
     *
     * A participant without results has a zeroed record and is placed by it
     * like any other, not last: it stands above a participant whose ranking
     * value is below zero, or whose ranking value is zero with a score
     * difference below zero.
     *
     * An event has one result. Two results are of one event, and are
     * refused, when they hold the same Event object, or when their events
     * carry the same round number, the same participants in any order and
     * the same tie leg (the `tie_leg` metadata of a two-legged tie; an
     * event without it is leg 1). The second rule is what holds after
     * storage: results read back from JSON hold Event objects of their
     * own, so a result recorded twice is two objects and one match. An
     * event with no round has nothing but the object to tell it from
     * another meeting of the same participants, so two such events are
     * two events played. Events of one round between the same participants
     * that are in fact two events need a `tie_leg` each to be counted
     * apart, and results of two schedules or stages that number their
     * rounds alike (two seasons, two stages that each count from 1) are
     * to be given one table each, or round numbers that continue; the
     * message of the exception says so.
     *
     * @param array<Participant> $participants Everyone the table lists, in any order. Of two
     *                                         participants with the same ID the later is kept
     * @param array<Result> $results The recorded results, in any order
     *
     * @throws InvalidInputException When a result's event has a participant who is not
     *                               among the given ones, or two results are of one event
     */
    public function calculate(array $participants, array $results): Standings
    {
        self::refuseRepeatedResults($results);

        [$participantsById, $entries] = $this->entriesOf($participants, $results);

        return $this->inOrderOfTheChain($participantsById, $results, $entries, $entries, $entries);
    }

    /**
     * Calculate the table of the given participants from the given results,
     * with the byes they had and the adjustments made to them: the whole
     * table in one call.
     *
     * The table is the one calculate() gives, with two things more. Each
     * bye is credited: it adds what the ranking strategy says a bye is
     * worth (RankingStrategyWithByeValue::getByeValue(), times the number
     * given as $creditsPerBye) to the ranking value its participant earned,
     * and StandingEntry::getByes() counts it. Played, wins, draws and
     * losses count results only. Each adjustment is applied as
     * withAdjustments() applies it. So for every entry
     *
     *     getEarnedRankingValue() = getRankingValueOfResults() + getByes() x $creditsPerBye x the value of a bye
     *     getRankingValue()       = getEarnedRankingValue() + getAdjustment()
     *
     * This is the call for a table that has byes or adjustments, and the
     * only one for a table whose tiebreakers depend on them: a tiebreaker
     * that reads byes (TiebreakerWithByeTreatment with a treatment other
     * than ByeTreatment::Uncounted) is handed the entries with the byes
     * credited, and a tiebreaker between the tied participants
     * (TiedParticipantsTiebreaker) is asked about the participants that
     * are level once byes and adjustments are in the ranking value. The
     * engines calculate their tables with it, giving the byes and the
     * adjustments recorded on the stage state, so the order a round is
     * paired from and the table of the outcome are one table.
     *
     * With tiebreakers of neither kind the result is, entry for entry and
     * in the same order, what
     * withAdjustments(withByesCredited(calculate(...), ...), ...) gives.
     * The order is the same with one of those kinds: entries that no
     * figure separates are ordered by what they earned (higher first),
     * then by what their results are worth, and then by the fallback of
     * calculate(), seed and participant ID. Standings::getTiedSets()
     * reports them either way.
     *
     * The table carries no decision (Standings::withDecisions()): a
     * decision orders entries that are level once everything here is
     * counted, so it is applied to the table this returns, last.
     *
     * Given tiers, the table has them (Standings::getTiers()): a tier is a
     * criterion that orders the table before any figure, as the round an
     * entrant went out in orders the table of a bracket. The entries are in
     * tier order, the figures order them inside a tier, and nothing moves
     * an entry from one tier to another. A tied set of a tiebreaker between
     * the tied participants is formed inside a tier: participants of
     * different tiers are never one set, so an event against a participant
     * of another tier counts in no value of that kind, also where the two
     * are level on every figure. A participant the tiers do not name is in
     * a tier after every tier given, with the others they do not name.
     * Without tiers the table is ordered by its figures alone.
     *
     * A subclass is always calculated the second way, through its own
     * calculate(), withByesCredited() and withAdjustments(): one that
     * orders a table by rules of its own is asked as it was before this
     * method existed, and with a tiebreaker of one of the two kinds it is
     * refused the byes and adjustments those methods refuse. It is given
     * its tiers after calculate() (an entry keeps the order calculate()
     * gave it inside its tier), and is refused them when it has a
     * tiebreaker between the tied participants, whose sets its calculate()
     * forms over the whole table.
     *
     * @param array<Participant> $participants Everyone the table lists, in any order
     * @param array<Result> $results The recorded results, in any order
     * @param array<int|string, int> $byes Bye counts keyed by participant ID; an ID with no
     *                                     entry in the table, and a count below 1, is ignored
     * @param array<Adjustment> $adjustments Every adjustment of the participants of the table
     * @param int $creditsPerBye How many times each bye is credited with the value of a bye: 1, or
     *                           the legs of a tie where a bye stands for a tie of several legs. 0
     *                           counts each bye (StandingEntry::getByes()) and credits it with
     *                           nothing, under any ranking strategy: a Swiss stage and a bracket
     *                           do so under one that does not say what a bye is worth
     * @param array<int|string, int> $tiers The tier of each participant by ID, lower first; an ID
     *                                      with no entry in the table is ignored. Empty: a table
     *                                      without tiers
     *
     * @throws InvalidInputException When a result's event has a participant who is not among
     *                               the given ones, two results are of one event, an
     *                               adjustment names a participant that is not among the
     *                               given ones, or the amounts for one participant add up to
     *                               more than a float holds
     * @throws InvalidConfigurationException When there is a bye to credit with a value
     *                                       ($creditsPerBye above 0) and the ranking strategy does
     *                                       not say what a bye is worth (it is no
     *                                       RankingStrategyWithByeValue), or the calculator is a
     *                                       subclass with a tiebreaker between the tied
     *                                       participants and is given tiers (reason
     *                                       `IncompatibleOptions` for both)
     */
    public function calculateWith(
        array $participants,
        array $results,
        array $byes = [],
        array $adjustments = [],
        int $creditsPerBye = 1,
        array $tiers = []
    ): Standings {
        // A subclass is asked through the three methods it may override,
        // and nothing of this class is read before they are
        if (static::class !== self::class || !$this->isCalculatedInOneGo(true)) {
            $table = $this->calculate($participants, $results);

            return $this->withAdjustments(
                $this->withByesCredited($tiers === [] ? $table : $this->inTiers($table, $tiers), $byes, $creditsPerBye),
                $adjustments
            );
        }

        self::refuseRepeatedResults($results);

        [$participantsById, $baseEntries] = $this->entriesOf($participants, $results);

        // What a tiebreaker that reads byes is handed: the byes and their
        // credit, and no adjustment
        $creditedEntries = [];
        foreach ($baseEntries as $id => $entry) {
            $count = $byes[$id] ?? 0;
            $creditedEntries[$id] = $count < 1 ? $entry : $this->creditByes($entry, $count, $creditsPerBye);
        }

        $entries = $creditedEntries;
        if ($adjustments !== []) {
            $sums = $this->adjustmentSums(array_keys($entries), $adjustments);
            foreach ($entries as $id => $entry) {
                $entries[$id] = $entry->withAdjustment($sums[$id]);
            }
        }

        return $this->inOrderOfTheChain(
            $participantsById,
            $results,
            $baseEntries,
            $creditedEntries,
            $entries,
            $tiers === [] ? [] : self::tierOfEach(array_keys($participantsById), $tiers)
        );
    }

    /**
     * The tier of each of the given participants: the one given, and for
     * a participant given none, the tier after the last given.
     *
     * @param array<int|string> $ids
     * @param array<int|string, int> $tiers
     *
     * @return array<string, int> By participant ID
     */
    private static function tierOfEach(array $ids, array $tiers): array
    {
        $named = [];
        foreach ($ids as $id) {
            if (isset($tiers[$id])) {
                $named[(string) $id] = $tiers[$id];
            }
        }

        $unnamed = $named === [] ? 0 : max($named) + 1;
        $tierOf = [];
        foreach ($ids as $id) {
            $tierOf[(string) $id] = $named[(string) $id] ?? $unnamed;
        }

        return $tierOf;
    }

    /**
     * Whether the tiebreakers of a calculator can be read: a subclass whose
     * constructor does not call the one it inherits has none set, and
     * reading them would be an Error. Such a calculator orders its tables
     * by its own calculate(), and has no tiebreaker of any kind to refuse.
     */
    private static function hasTiebreakersToRead(self $calculator): bool
    {
        try {
            return (new ReflectionProperty(self::class, 'tiebreakers'))->isInitialized($calculator);
        } catch (ReflectionException) {
            // Not thrown: the class declares the property
            return true;
        }
    }

    /**
     * The given table with tiers: each entry in its tier, and in the order
     * the table has it inside the tier (see calculateWith()).
     *
     * @param array<int|string, int> $tiers By participant ID
     *
     * @throws InvalidConfigurationException When a tiebreaker of the calculator is computed between
     *                                       the tied participants (reason `IncompatibleOptions`)
     */
    private function inTiers(Standings $table, array $tiers): Standings
    {
        if ($this->isCalculatedInOneGo(false)) {
            throw new InvalidConfigurationException(
                'A subclass of StandingsCalculator calculates a table through its own calculate(), which forms the tied'
                    . ' sets of a tiebreaker between the tied participants over the whole table and not inside a tier.'
                    . ' Calculate a table with tiers with StandingsCalculator itself, or without a tiebreaker of that kind',
                ['calculator' => static::class, 'tiebreakers' => $this->tiebreakerNames()],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        $entries = array_values($table->getEntries());
        $tierOf = self::tierOfEach(
            array_map(static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(), $entries),
            $tiers
        );

        $rows = [];
        foreach ($entries as $index => $entry) {
            $rows[] = [$tierOf[$entry->getParticipant()->getId()], $index, $entry];
        }
        usort($rows, static fn(array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);

        return new Standings(
            array_map(static fn(array $row): StandingEntry => $row[2], $rows),
            array_map(static fn(array $row): int => $row[0], $rows)
        );
    }

    /**
     * Whether a tiebreaker of this calculator has a value that depends on
     * more than the results: on who is level in the finished table
     * (TiedParticipantsTiebreaker), or, when asked for, on the byes
     * (TiebreakerWithByeTreatment with a treatment other than Uncounted).
     * A table with such a tiebreaker cannot have byes or adjustments
     * applied to it after it was calculated. A subclass whose constructor
     * does not call the one it inherits has no tiebreaker set, and so none
     * of the kind (see hasTiebreakersToRead()).
     */
    private function isCalculatedInOneGo(bool $forByes): bool
    {
        if (!self::hasTiebreakersToRead($this)) {
            return false;
        }

        foreach ($this->tiebreakers as $tiebreaker) {
            if ($tiebreaker instanceof TiedParticipantsTiebreaker) {
                return true;
            }

            if ($forByes
                && $tiebreaker instanceof TiebreakerWithByeTreatment
                && $tiebreaker->getByeTreatment() !== ByeTreatment::Uncounted
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The table of the given entries: the tiebreakers asked in order, and
     * the order of the class docblock.
     *
     * @param array<string, Participant> $participantsById
     * @param array<Result> $results
     * @param array<string, StandingEntry> $baseEntries The entries of the results alone
     * @param array<string, StandingEntry> $creditedEntries Those with the byes credited
     * @param array<string, StandingEntry> $entries Those with the adjustments applied as well
     * @param array<string, int> $tierOf The tier of every participant by ID; empty for a table
     *                                   without tiers
     *
     * @throws InvalidInputException Not thrown here: the results were checked against the
     *                               participants before
     */
    private function inOrderOfTheChain(
        array $participantsById,
        array $results,
        array $baseEntries,
        array $creditedEntries,
        array $entries,
        array $tierOf = []
    ): Standings {
        if ($this->tiebreakers !== []) {
            // What a tiebreaker between the tied participants only is handed
            // when awarded results do not count in it
            $playedResults = $this->awardedResults->countBetweenTiedParticipants
                ? $results
                : array_values(array_filter($results, static fn(Result $result): bool => !$result->isAwarded()));

            /** @var array<string, list<float>> $figures The ranking value and the tiebreaker values so far */
            $figures = [];
            /** @var array<string, array<string, float>> $values */
            $values = [];
            foreach ($entries as $id => $entry) {
                // The tier is the first figure a tied set is read from:
                // participants of two tiers are never one set
                $figures[$id] = $tierOf === []
                    ? [$entry->getRankingValue()]
                    : [(float) $tierOf[$id], $entry->getRankingValue()];
                $values[$id] = [];
            }

            /** @var array<string, list<float>>|null $figuresOfRun The figures as they were before a run of tiebreakers between the tied */
            $figuresOfRun = null;
            foreach ($this->tiebreakers as $tiebreaker) {
                $name = $tiebreaker->getName();

                if ($tiebreaker instanceof TiedParticipantsTiebreaker) {
                    // A run of these in a row is one small table: each is
                    // asked about the sets that were level when the first
                    // of the run was, and not about what its predecessors
                    // in the run left level
                    $figuresOfRun ??= $figures;
                    $calculated = $this->betweenTiedParticipants($tiebreaker, $participantsById, $figuresOfRun, $playedResults);
                } else {
                    $figuresOfRun = null;
                    $handed = $tiebreaker instanceof TiebreakerWithByeTreatment ? $creditedEntries : $baseEntries;
                    $calculated = [];
                    foreach ($participantsById as $id => $participant) {
                        $calculated[$id] = $tiebreaker->calculate($participant, $results, $handed);
                    }
                }

                foreach ($calculated as $id => $value) {
                    $values[$id][$name] = $value;
                    $figures[$id][] = $value;
                }
            }

            foreach ($entries as $id => $entry) {
                $entries[$id] = $entry->withTiebreakers($values[$id]);
            }
        }

        $tiebreakerNames = $this->tiebreakerNames();

        $ordered = array_values($entries);
        usort($ordered, static function (StandingEntry $a, StandingEntry $b) use ($tiebreakerNames, $tierOf): int {
            $comparison = ($tierOf[$a->getParticipant()->getId()] ?? 0) <=> ($tierOf[$b->getParticipant()->getId()] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = EntryOrder::compareFigures($a, $b, $tiebreakerNames);
            if ($comparison !== 0) {
                return $comparison;
            }

            // Level entries are in the order withAdjustments() and
            // withByesCredited() leave them in: as they stood before the
            // adjustments, and before that as they stood before the byes.
            // In a table without either, both steps compare equal values.
            $comparison = [$b->getEarnedRankingValue(), $b->getRankingValueOfResults()]
                <=> [$a->getEarnedRankingValue(), $a->getRankingValueOfResults()];
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ($a->getParticipant()->getSeed() ?? PHP_INT_MAX) <=> ($b->getParticipant()->getSeed() ?? PHP_INT_MAX);
            if ($comparison !== 0) {
                return $comparison;
            }

            return EntryOrder::compareIds($a->getParticipant()->getId(), $b->getParticipant()->getId());
        });

        return new Standings(
            $ordered,
            $tierOf === []
                ? []
                : array_map(static fn(StandingEntry $entry): int => $tierOf[$entry->getParticipant()->getId()], $ordered)
        );
    }

    /**
     * The values of a tiebreaker between the tied participants: for every
     * tied set, the tiebreaker is asked about each of its participants with
     * the results between the participants of the set and the entries those
     * results give.
     *
     * A tied set is the participants whose figures given are the same: the
     * ranking value and the value of every tiebreaker before the run of
     * tiebreakers between the tied that this one is in. The sets are read
     * from the figures alone, so they are the same for every order of the
     * participants and the results.
     *
     * @param array<string, Participant> $participantsById
     * @param array<string, list<float>> $figures By participant ID
     * @param array<Result> $results The results the tiebreaker may read
     *
     * @return array<string, float> By participant ID
     *
     * @throws InvalidInputException Not thrown here: the results were checked against the
     *                               participants before
     */
    private function betweenTiedParticipants(
        TiedParticipantsTiebreaker $tiebreaker,
        array $participantsById,
        array $figures,
        array $results
    ): array {
        $ids = array_map(strval(...), array_keys($participantsById));
        usort($ids, static fn(string $first, string $second): int => $figures[$first] <=> $figures[$second]);

        /** @var array<string, int> $setOf The number of the tied set of each participant */
        $setOf = [];
        /** @var list<list<Participant>> $sets */
        $sets = [];
        $previous = null;
        foreach ($ids as $id) {
            if ($previous === null || ($figures[$previous] <=> $figures[$id]) !== 0) {
                $sets[] = [];
            }
            $setOf[$id] = count($sets) - 1;
            $sets[count($sets) - 1][] = $participantsById[$id];
            $previous = $id;
        }

        /** @var array<int, list<Result>> $resultsOfSet */
        $resultsOfSet = [];
        foreach ($results as $result) {
            $set = null;
            foreach ($result->getEvent()->getParticipants() as $participant) {
                $own = $setOf[$participant->getId()] ?? null;
                if ($own === null || ($set !== null && $own !== $set)) {
                    continue 2;
                }
                $set = $own;
            }
            if ($set !== null) {
                $resultsOfSet[$set][] = $result;
            }
        }

        $values = [];
        foreach ($sets as $number => $members) {
            $between = $resultsOfSet[$number] ?? [];
            [, $entriesOfSet] = $this->entriesOf($members, $between);
            foreach ($members as $member) {
                $values[$member->getId()] = $tiebreaker->calculate($member, $between, $entriesOfSet);
            }
        }

        return $values;
    }

    /**
     * The entries of the given participants from the given results alone:
     * the record, the scores and the ranking value the ranking strategy
     * computes, with no bye, adjustment or tiebreaker value.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     *
     * @return array{array<string, Participant>, array<string, StandingEntry>} The participants and
     *                                                                         their entries, by ID
     *
     * @throws InvalidInputException When a result's event has a participant who is not among
     *                               the given ones
     */
    private function entriesOf(array $participants, array $results): array
    {
        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        /** @var array<string, array<Result>> $resultsByParticipant */
        $resultsByParticipant = [];
        /** @var array<string, array{played: int, wins: int, draws: int, losses: int, for: float, against: float, awarded: int}> $tallies */
        $tallies = [];

        foreach ($participants as $participant) {
            $participantsById[$participant->getId()] = $participant;
            $tallies[$participant->getId()] = [
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'for' => 0.0,
                'against' => 0.0,
                'awarded' => 0,
            ];
        }

        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();
            $awarded = $result->isAwarded();
            $scoresCount = !$awarded || $this->awardedResults->countScores;

            foreach ($eventParticipants as $participant) {
                $id = $participant->getId();
                if (!isset($tallies[$id])) {
                    throw new InvalidInputException(
                        "Result references participant {$id} who is not in the standings"
                    );
                }

                $resultsByParticipant[$id][] = $result;
                ++$tallies[$id]['played'];
                if ($result->isDraw()) {
                    ++$tallies[$id]['draws'];
                } elseif ($result->isWinFor($participant)) {
                    ++$tallies[$id]['wins'];
                } else {
                    ++$tallies[$id]['losses'];
                }

                if ($awarded) {
                    ++$tallies[$id]['awarded'];
                }

                if (!$scoresCount) {
                    continue;
                }

                $ownScore = $result->getScoreFor($participant);
                if ($ownScore !== null) {
                    $tallies[$id]['for'] += (float) $ownScore;
                }

                foreach ($eventParticipants as $opponent) {
                    if ($opponent->getId() === $id) {
                        continue;
                    }
                    $opponentScore = $result->getScoreFor($opponent);
                    if ($opponentScore !== null) {
                        $tallies[$id]['against'] += (float) $opponentScore;
                    }
                }
            }
        }

        /** @var array<string, StandingEntry> $entries */
        $entries = [];
        foreach ($tallies as $id => $tally) {
            $rankingValue = $this->rankingStrategy->rank(
                $participantsById[$id],
                $resultsByParticipant[$id] ?? []
            );

            $entries[$id] = new StandingEntry(
                $participantsById[$id],
                $tally['played'],
                $tally['wins'],
                $tally['draws'],
                $tally['losses'],
                $rankingValue,
                $tally['for'],
                $tally['against'],
                awarded: $tally['awarded']
            );
        }

        return [$participantsById, $entries];
    }

    /**
     * Refuse two results of one event (calculate() says which two are one).
     *
     * @param array<Result> $results
     *
     * @throws InvalidInputException When two results are of one event
     */
    private static function refuseRepeatedResults(array $results): void
    {
        $seenEvents = [];
        $seenMatches = [];
        foreach ($results as $result) {
            $event = $result->getEvent();

            // The object does not survive storage: two results of one match
            // read back from JSON hold two Event objects that are equal.
            // What does survive is what the event says about itself, so a
            // result recorded twice is refused in the same words before and
            // after the state was stored.
            $eventId = spl_object_id($event);
            $match = self::matchOf($event);
            if (isset($seenEvents[$eventId]) || ($match !== null && isset($seenMatches[$match]))) {
                $round = $event->getRound()?->getNumber();

                // The same words whether the two results hold one object or
                // two: the hint is for the caller whose two events are in
                // fact two, which the calculator cannot tell from one.
                throw new InvalidInputException(
                    'Two results reference the same event; each event can have only one result'
                    . ($round === null ? '' : sprintf(
                        ' (round %d, %s). Events with the same round number, participants and tie leg are one event:'
                        . ' if these are two (one table over two stages or seasons, or two meetings in one round),'
                        . " calculate a table for each, continue the round numbers, or give each event a 'tie_leg'",
                        $round,
                        implode(' vs ', array_map(
                            fn(Participant $participant): string => $participant->getId(),
                            $event->getParticipants()
                        ))
                    ))
                );
            }

            $seenEvents[$eventId] = true;
            if ($match !== null) {
                $seenMatches[$match] = true;
            }
        }
    }

    /**
     * What identifies the match an event is, where the event says enough
     * to identify one: its round number, its participants in any order and
     * its tie leg. Null for an event with no round, which nothing but the
     * object tells from another meeting of the same participants.
     *
     * The same three things identify an event to StageState and to the
     * bracket engines, so a result is one match to all of them.
     */
    private static function matchOf(Event $event): ?string
    {
        $round = $event->getRound()?->getNumber();
        if ($round === null) {
            return null;
        }

        $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        $leg = $event->getMetadataValue(FormatMetadataKey::TIE_LEG);

        return $round . ':' . PairKey::of(...array_values($ids)) . ':' . (is_int($leg) ? $leg : 1);
    }

    /**
     * The table with byes credited: each bye adds what the ranking
     * strategy says a bye is worth (RankingStrategyWithByeValue), the given
     * number of times, to the entry of the participant who had it, and the
     * table is put in order again.
     *
     * A bye is no result, so calculate() counts it nowhere. A stage in
     * which sitting out a round is worth something (a Swiss stage, and a
     * bracket when it ranks the entrants who are still in it) credits it.
     * Only the ranking value changes, and StandingEntry::getByes() says
     * how many byes the entry has: played, wins, draws, losses, the scores
     * and the tiebreaker values are those of the results. A bye worth 0.0
     * is counted and changes no ranking value.
     *
     * This applies byes to a table that exists. It keeps every tiebreaker
     * value as it is, which is right for a tiebreaker that reads no bye
     * (every one of the library's at its default). A calculator with a
     * tiebreaker that does read byes, or one computed between the tied
     * participants, is refused here when there is a bye to credit: use
     * calculateWith(), which takes the byes before the tiebreakers are
     * asked.
     *
     * The credited table is ordered as calculate() orders one, by ranking
     * value, tiebreakers, score difference and scores-for. Entries that
     * are level after that keep the order they have in the table given.
     * A table with tiers keeps them: an entry is ordered among the entries
     * of its own tier only (Standings::getTiers()). Without a bye to
     * credit the table is returned as it is.
     *
     * A table that carries decisions (Standings::withDecisions()) keeps
     * them where the credit leaves every decided set as it was, and is
     * refused where it does not: credit byes first and apply decisions
     * last.
     *
     * @param array<int|string, int> $byes Bye counts keyed by participant ID; an ID with no
     *                                     entry in the table is ignored
     * @param int $creditsPerBye How many times each bye is credited with the value of a bye: 1, or
     *                           the legs of a tie where a bye stands for a tie of several legs. 0
     *                           counts each bye (StandingEntry::getByes()) and credits it with
     *                           nothing, under any ranking strategy: a Swiss stage and a bracket
     *                           do so under one that does not say what a bye is worth
     *
     * @throws InvalidConfigurationException When there is a bye to credit with a value
     *                                       ($creditsPerBye above 0) and the ranking strategy does
     *                                       not say what a bye is worth (it is no
     *                                       RankingStrategyWithByeValue), or a tiebreaker of the
     *                                       calculator reads byes or is computed between the
     *                                       tied participants (reason `IncompatibleOptions` for
     *                                       both)
     * @throws RejectedDecisionException When the table carries a decision whose participants the
     *                                   credit leaves no longer one whole tied set (reason
     *                                   `Stale`)
     */
    public function withByesCredited(Standings $standings, array $byes, int $creditsPerBye = 1): Standings
    {
        $credited = [];
        $any = false;
        foreach ($standings->getEntries() as $entry) {
            $count = $byes[$entry->getParticipant()->getId()] ?? 0;
            if ($count < 1) {
                $credited[] = $entry;
                continue;
            }

            $any = true;
            $credited[] = $this->creditByes($entry, $count, $creditsPerBye);
        }

        if (!$any) {
            return $standings;
        }

        if ($this->isCalculatedInOneGo(true)) {
            throw new InvalidConfigurationException(
                'Byes cannot be credited to a table after it was calculated when a tiebreaker reads byes or is computed'
                    . ' between the tied participants: its values depend on them. Give the byes to calculateWith()',
                ['tiebreakers' => $this->tiebreakerNames()],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        return $this->inOrder($credited, $standings);
    }

    /**
     * The entry with the given number of byes more, each credited with
     * what the ranking strategy says a bye is worth.
     *
     * Credited no times, a bye is counted and adds nothing, and the
     * ranking strategy is not read.
     *
     * @throws InvalidConfigurationException When the bye is credited at least once and the ranking
     *                                       strategy does not say what a bye is worth (reason
     *                                       `IncompatibleOptions`)
     */
    private function creditByes(StandingEntry $entry, int $count, int $creditsPerBye): StandingEntry
    {
        if ($creditsPerBye < 1) {
            return $entry->withByes($entry->getByes() + $count, $entry->getEarnedRankingValue());
        }

        if (!$this->rankingStrategy instanceof RankingStrategyWithByeValue) {
            throw new InvalidConfigurationException(
                'Crediting a bye requires a ranking strategy that says what a bye is worth (RankingStrategyWithByeValue);'
                    . ' nothing else says what sitting a round out counts for on the scale of '
                    . $this->rankingStrategy::class,
                ['ranking_strategy' => $this->rankingStrategy::class],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        // The credit is earned: an adjustment the entry carries stays
        // apart from it (StandingEntry::withByes() keeps it)
        return $entry->withByes(
            $entry->getByes() + $count,
            $entry->getEarnedRankingValue() + $count * $creditsPerBye * $this->rankingStrategy->getByeValue()
        );
    }

    /**
     * The table with adjustments applied: each participant's entry carries
     * the sum of its adjustments as a figure of its own
     * (StandingEntry::getAdjustment()), its ranking value is what it earned
     * plus that sum, and the table is put in order again.
     *
     * An adjustment is an amount of the ranking value on the scale of the
     * ranking strategy, whatever that strategy is (see Adjustment): the
     * calculator adds it and needs to know nothing about the scale, so no
     * ranking strategy is refused. Only the ranking value changes. The
     * record, the scores, the awarded count and the bye count are those of
     * the results, and so is every tiebreaker value: a tiebreaker that
     * reads the ranking values of opponents (Buchholz, Sonneborn-Berger)
     * uses the value an opponent earned from its results, and an
     * opponent's adjustment, like an opponent's bye, counts as nothing in
     * it. What was taken from a participant for a reason that is no event
     * says nothing about how strong it was to meet.
     *
     * The adjustments replace any the table carries: give every adjustment
     * of the competition, in any order. An entry no adjustment names has
     * 0.0. The amounts for one participant are added without the rounding
     * of a running float total: the figure is the float nearest to their
     * exact sum. So it is the same for every order of the list, and amounts
     * that cancel leave nothing: 0.1 and 0.2 added and each taken back
     * again are 0.0, where a running total would leave -2.8e-17 and place
     * the participant below one it is level with.
     *
     * The table is ordered as calculate() orders one, by ranking value,
     * tiebreakers, score difference and scores-for. Entries that are level
     * after that keep the order they have in the table given: follow with
     * Standings::withTiedSetsOrderedBy() where the participants are an
     * entrant list. A table with tiers keeps them: an entry is ordered
     * among the entries of its own tier only. A table that carries no
     * adjustment, given none, is returned as it is.
     *
     * Byes can be credited before or after; the two do not disturb each
     * other.
     *
     * This applies adjustments to a table that exists, and keeps every
     * tiebreaker value as it is. A calculator with a tiebreaker computed
     * between the tied participants (TiedParticipantsTiebreaker) is
     * refused here when an adjustment changes the table, because who is
     * tied depends on the adjustments: use calculateWith(), which takes
     * them before the tiebreakers are asked.
     *
     * A decision is applied after the adjustments
     * (Standings::withDecisions()), because it settles the order of
     * entries that are level on the adjusted ranking value. A table that
     * already carries decisions keeps them where the adjustments leave
     * every decided set as it was: the same entries, still level with each
     * other and with no other. Where they do not (a decided entry is no
     * longer level with the rest of its set, or another entry is now level
     * with them), the table is refused, so that no order is shown as
     * decided for a set the decision was not made for.
     *
     * @param array<Adjustment> $adjustments Every adjustment of the participants of the table
     *
     * @throws InvalidInputException When an adjustment names a participant that has no entry
     *                               in the table, or the amounts for one participant add up
     *                               to more than a float holds
     * @throws InvalidConfigurationException When an adjustment changes the table and a tiebreaker
     *                                       of the calculator is computed between the tied
     *                                       participants (reason `IncompatibleOptions`)
     * @throws RejectedDecisionException When the table carries a decision whose participants the
     *                                   adjustments leave no longer one whole tied set (reason
     *                                   `Stale`)
     */
    public function withAdjustments(Standings $standings, array $adjustments): Standings
    {
        $ids = array_map(
            static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
            array_values($standings->getEntries())
        );
        $sums = $this->adjustmentSums($ids, $adjustments);

        $adjusted = [];
        $any = false;
        foreach ($standings->getEntries() as $entry) {
            $sum = $sums[$entry->getParticipant()->getId()];
            $any = $any || $sum !== $entry->getAdjustment();
            $adjusted[] = $entry->withAdjustment($sum);
        }

        if (!$any) {
            return $standings;
        }

        if ($this->isCalculatedInOneGo(false)) {
            throw new InvalidConfigurationException(
                'Adjustments cannot be applied to a table after it was calculated when a tiebreaker is computed between'
                    . ' the tied participants: who is tied depends on them. Give the adjustments to calculateWith()',
                ['tiebreakers' => $this->tiebreakerNames()],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        return $this->inOrder($adjusted, $standings);
    }

    /**
     * The sum of the adjustments of every participant, 0.0 for one that
     * has none (see withAdjustments() for how the amounts are added).
     *
     * @param array<int|string> $ids The IDs of the participants of the table
     * @param array<Adjustment> $adjustments
     *
     * @return array<int|string, float> By participant ID
     *
     * @throws InvalidInputException When an adjustment names a participant that is not among
     *                               them, or the amounts for one add up to more than a
     *                               float holds
     */
    private function adjustmentSums(array $ids, array $adjustments): array
    {
        /** @var array<int|string, list<float>> $amounts */
        $amounts = [];
        foreach ($ids as $id) {
            $amounts[$id] = [];
        }

        foreach ($adjustments as $adjustment) {
            $id = $adjustment->getParticipantId();
            if (!isset($amounts[$id])) {
                throw new InvalidInputException(
                    "Adjustment references participant {$id} who is not in the standings"
                );
            }
            $amounts[$id][] = $adjustment->getAmount();
        }

        $sums = [];
        foreach ($amounts as $id => $list) {
            $sum = self::exactSum($list);
            if (!is_finite($sum)) {
                throw new InvalidInputException(
                    "The adjustments of participant {$id} add up to more than a float holds"
                );
            }
            $sums[$id] = $sum;
        }

        return $sums;
    }

    /**
     * The float nearest to the exact sum of the amounts, whatever order
     * they are given in.
     *
     * A running float total rounds after every addition, so it depends on
     * the order of its terms, and terms that cancel need not leave zero.
     * This keeps the error of each addition as a term of its own (the
     * error of a float addition is itself a float), so nothing is lost
     * until the one rounding at the end; a sum that lies halfway between
     * two floats is rounded as the exact sum is, to the even one. It is
     * the summation of Shewchuk, as math.fsum of Python does it.
     *
     * The amounts are taken smallest first, so that a sum too large for a
     * float is too large in every order of the list: the result is then
     * not finite, or not a number.
     *
     * @param list<float> $amounts Finite amounts
     */
    private static function exactSum(array $amounts): float
    {
        sort($amounts);

        /** @var list<float> $partials Terms that add up to the sum exactly, by rising magnitude */
        $partials = [];
        foreach ($amounts as $amount) {
            $kept = [];
            foreach ($partials as $partial) {
                if (abs($amount) < abs($partial)) {
                    [$amount, $partial] = [$partial, $amount];
                }
                $high = $amount + $partial;
                $low = $partial - ($high - $amount);
                if ($low !== 0.0) {
                    $kept[] = $low;
                }
                $amount = $high;
            }
            $kept[] = $amount;
            $partials = $kept;
        }

        $index = count($partials);
        if ($index === 0) {
            return 0.0;
        }

        $sum = $partials[--$index];
        $low = 0.0;
        while ($index > 0) {
            $high = $sum;
            $partial = $partials[--$index];
            $sum = $high + $partial;
            $low = $partial - ($sum - $high);
            if ($low !== 0.0) {
                break;
            }
        }

        // Halfway between two floats, and what is left says which side the
        // exact sum is on
        if ($index > 0 && (($low < 0.0 && $partials[$index - 1] < 0.0) || ($low > 0.0 && $partials[$index - 1] > 0.0))) {
            $twice = $low * 2.0;
            $next = $sum + $twice;
            if ($twice === $next - $sum) {
                $sum = $next;
            }
        }

        // 0.0 and never -0.0, which the amounts -0.0 alone would give
        return $sum + 0.0;
    }

    /**
     * A table of the given entries, which are those of a table in its order
     * with some ranking values changed: ordered by tier and then as
     * calculate() orders, with level entries left in the order given.
     *
     * The decisions of the table the entries are from go with them, and
     * are held to the new order: a decision whose participants are no
     * longer one whole tied set is not kept and not dropped, the table is
     * refused.
     *
     * @param list<StandingEntry> $entries
     * @param Standings $from The table the entries are from, for its tiers and its decisions
     *
     * @throws RejectedDecisionException When a decision of the table no longer orders one whole
     *                                   tied set (reason `Stale`)
     */
    private function inOrder(array $entries, Standings $from): Standings
    {
        $tiers = $from->getTiers();
        $tiebreakerNames = $this->tiebreakerNames();
        $rows = [];
        foreach ($entries as $index => $entry) {
            $rows[] = ['tier' => $tiers[$index] ?? 0, 'entry' => $entry];
        }
        // usort() is stable: level entries keep the order of the table given
        usort(
            $rows,
            static fn(array $a, array $b): int => $a['tier'] !== $b['tier']
                ? $a['tier'] <=> $b['tier']
                : EntryOrder::compareFigures($a['entry'], $b['entry'], $tiebreakerNames)
        );

        // The tiers never fall down a table, and no entry left its tier, so
        // they are the tiers of the re-ordered table as they stand
        $ordered = array_map(static fn(array $row): StandingEntry => $row['entry'], $rows);

        try {
            return new Standings($ordered, $tiers, $from->getDecisions());
        } catch (RejectedDecisionException $rejected) {
            throw new RejectedDecisionException(
                RejectedDecisionReason::Stale,
                $rejected->getDecider(),
                $rejected->getParticipantIds(),
                previous: $rejected
            );
        }
    }

    /**
     * The names of the tiebreakers, in order; none for a subclass whose
     * constructor does not call the one it inherits (see
     * hasTiebreakersToRead()).
     *
     * @return list<string>
     */
    private function tiebreakerNames(): array
    {
        if (!self::hasTiebreakersToRead($this)) {
            return [];
        }

        return array_values(array_map(
            static fn(TiebreakerInterface $tiebreaker): string => $tiebreaker->getName(),
            $this->tiebreakers
        ));
    }
}
