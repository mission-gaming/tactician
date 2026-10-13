<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * The reading of one elimination tie from its leg results: who advances,
 * and whether that was taken from the deprecated 'tie_winner' metadata.
 *
 * TieDecision states the rules; this is where they are applied, once for
 * every caller. It is apart from TieDecision::advancer() for one reason: a
 * caller that reads many ties in one call (an engine replaying a bracket, a
 * selector reading a round) has to raise the deprecation of the metadata
 * key once for the call and not once for every tie, so it needs to be told
 * that the key was read and to raise the notice itself.
 *
 * @internal Not public API: the reading behind TieDecision::advancer(), the
 *           bracket engines and MatchOutcomeSelector.
 */
final readonly class TieResolution
{
    /**
     * The metadata key the releases before 0.3.0 recorded a tie decision
     * under. Written here and not read from TieDecision::TIE_WINNER_KEY,
     * which is deprecated with the key.
     */
    private const string METADATA_KEY = 'tie_winner';

    /**
     * What the deprecation of the metadata key says. It begins with what is
     * deprecated, so that a handler can tell it from any other notice.
     */
    public const string TIE_WINNER_DEPRECATION = "The 'tie_winner' metadata of a result is deprecated since 0.3.0"
        . ' and is no longer read from 1.0.0: record who advances from a level tie as the decider of the result'
        . ' instead (MissionGaming\Tactician\DTO\Decider, the sixth argument of Result)';

    /**
     * @param Participant|null $advancer Who advances, or null while a leg has no result
     * @param bool $readTieWinnerMetadata Whether the advancer was taken from 'tie_winner' metadata
     */
    private function __construct(
        public ?Participant $advancer,
        public bool $readTieWinnerMetadata
    ) {}

    /**
     * Read a tie from its leg results, by the rules TieDecision states.
     *
     * @param array<Result> $legResults The recorded results of the tie's legs, any order
     *
     * @throws InvalidConfigurationException The cases TieDecision::advancer() names
     */
    public static function of(array $legResults, Participant $first, Participant $second, int $legsPerTie): self
    {
        $legResults = array_values($legResults);
        if (count($legResults) < $legsPerTie) {
            return new self(null, false); // Legs still unplayed
        }

        $sides = [$first->getId() => $first, $second->getId() => $second];
        $ids = [$first->getId(), $second->getId()];

        $decided = $legsPerTie === 1
            ? self::singleLegWinner($legResults[0], $ids)
            : self::winnerOnLegs($legResults, $first, $second);

        $deciders = [];
        foreach ($legResults as $result) {
            $decider = $result->getDecider();
            if ($decider instanceof Decider) {
                $deciders[] = $decider;
            }
        }

        if ($decided instanceof Participant) {
            // The results decide: nothing recorded beside them may. The
            // metadata key is not read here, as it never was.
            if ($deciders !== []) {
                throw new InvalidConfigurationException(
                    'A decider is recorded on a tie that its results already decide: a decider settles a level tie and'
                        . ' cannot confirm or overturn a result',
                    ['decider' => $deciders[0]->getParticipantIds(), 'advancer' => $decided->getId(), 'participants' => $ids],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }

            return new self($decided, false);
        }

        if ($deciders !== []) {
            return new self(self::decidedBy($deciders, $legResults, $sides, $ids), false);
        }

        // The form the releases before 0.3.0 recorded the decision in
        foreach ($legResults as $result) {
            $decision = $result->getMetadataValue(self::METADATA_KEY);
            if ($decision === null) {
                continue;
            }

            if (!is_string($decision) || !isset($sides[$decision])) {
                throw new InvalidConfigurationException(
                    'Tie decision names a participant who is not in the tie',
                    [self::METADATA_KEY => $decision, 'participants' => $ids],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }

            return new self($sides[$decision], true);
        }

        throw new InvalidConfigurationException(
            $legsPerTie === 1
                ? "Elimination events cannot end in a draw ({$first->getLabel()} vs {$second->getLabel()}): the event is level, so record who advances as the decider of its result"
                : "Two-legged tie between {$first->getLabel()} and {$second->getLabel()} is undecided: the legs are level, so record who advances as the decider of one leg's result",
            ['participants' => $ids],
            reason: InvalidConfigurationReason::UndecidedTie
        );
    }

    /**
     * Raise the deprecation of the 'tie_winner' metadata key: one
     * E_USER_DEPRECATED notice, on every PHP version. The caller raises it
     * once for each call of its own in which the key decided a tie.
     */
    public static function raiseTieWinnerDeprecation(): void
    {
        trigger_error(self::TIE_WINNER_DEPRECATION, E_USER_DEPRECATED);
    }

    /**
     * The winner a single-leg result records, or null when it is level.
     *
     * @param array{string, string} $ids
     *
     * @throws InvalidConfigurationException When the winner is not in the tie
     */
    private static function singleLegWinner(Result $result, array $ids): ?Participant
    {
        $winner = $result->getWinner();
        if ($winner !== null && !in_array($winner->getId(), $ids, true)) {
            throw self::winnerOutsideTie($winner, $ids);
        }

        return $winner;
    }

    /**
     * The side that won more legs, or null when the legs are level.
     *
     * @param list<Result> $legResults
     *
     * @throws InvalidConfigurationException When a leg's winner is not in the tie
     */
    private static function winnerOnLegs(array $legResults, Participant $first, Participant $second): ?Participant
    {
        $legWins = [$first->getId() => 0, $second->getId() => 0];
        foreach ($legResults as $result) {
            $winner = $result->getWinner();
            if ($winner === null) {
                continue;
            }

            if (!isset($legWins[$winner->getId()])) {
                throw self::winnerOutsideTie($winner, [$first->getId(), $second->getId()]);
            }

            ++$legWins[$winner->getId()];
        }

        if ($legWins[$first->getId()] === $legWins[$second->getId()]) {
            return null;
        }

        return $legWins[$first->getId()] > $legWins[$second->getId()] ? $first : $second;
    }

    /**
     * Who the decider of a level tie sends on.
     *
     * A tie has one decider, on whichever of its results, and a tie
     * between two sends one participant on. Where a result of the tie
     * still carries 'tie_winner' metadata beside the decider, the two must
     * name the same participant: the key is compared and not relied on, so
     * no deprecation is raised for it.
     *
     * @param non-empty-list<Decider> $deciders
     * @param list<Result> $legResults
     * @param array<string, Participant> $sides The two participants of the tie, by ID
     * @param array{string, string} $ids The IDs of the two, as strings
     *
     * @throws InvalidConfigurationException When the tie carries more than one decider, the decider
     *                                       does not name exactly one participant, names one outside
     *                                       the tie, or disagrees with 'tie_winner' metadata
     */
    private static function decidedBy(array $deciders, array $legResults, array $sides, array $ids): Participant
    {
        if (count($deciders) > 1) {
            throw new InvalidConfigurationException(
                'A tie has one decider, and ' . count($deciders) . ' of its results carry one: record who advances'
                    . ' on one result of the tie',
                ['deciders' => count($deciders), 'participants' => $ids],
                reason: InvalidConfigurationReason::InvalidResult
            );
        }

        $named = $deciders[0]->getParticipantIds();
        if (count($named) !== 1) {
            throw new InvalidConfigurationException(
                'A tie between two participants sends one of them on, and its decider names ' . count($named),
                ['decider' => $named, 'participants' => $ids],
                reason: InvalidConfigurationReason::InvalidResult
            );
        }

        if (!isset($sides[$named[0]])) {
            throw new InvalidConfigurationException(
                'Decider names a participant who is not in the tie',
                ['decider' => $named, 'participants' => $ids],
                reason: InvalidConfigurationReason::InvalidResult
            );
        }

        foreach ($legResults as $result) {
            $decision = $result->getMetadataValue(self::METADATA_KEY);
            if ($decision !== null && $decision !== $named[0]) {
                throw new InvalidConfigurationException(
                    "The decider of a tie and the 'tie_winner' metadata of its results name different participants:"
                        . ' record who advances once, as the decider, and remove the metadata key',
                    ['decider' => $named, self::METADATA_KEY => $decision, 'participants' => $ids],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }
        }

        return $sides[$named[0]];
    }

    /**
     * @param array{string, string} $ids
     */
    private static function winnerOutsideTie(Participant $winner, array $ids): InvalidConfigurationException
    {
        return new InvalidConfigurationException(
            'Leg result names a winner who is not in the tie',
            ['winner' => $winner->getId(), 'participants' => $ids],
            reason: InvalidConfigurationReason::InvalidResult
        );
    }
}
