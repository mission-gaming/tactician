<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

/**
 * The metadata keys the library writes, as constants.
 *
 * Metadata is free-form: an application may put any key on a schedule, a
 * round, an event, a participant or a result. These are the keys the
 * library itself writes, so that an application reads them by name instead
 * of by a string it copied. The values are the strings the library has
 * always written and do not change between releases.
 *
 * Which object carries a key, and when, is said on each constant. A key that
 * is not there is read as missing (`hasMetadata()` is false), never as a
 * default the library meant.
 *
 * @api
 */
final class MetadataKey
{
    /**
     * Schedule: the algorithm identifier of the plan that generated it
     * (Stage\StagePlan::getAlgorithm(), one of the values of
     * Stage\Algorithm). Written by every whole-schedule generator.
     */
    public const string ALGORITHM = 'algorithm';

    /**
     * Schedule: the number of participants the generator was given. Written
     * by every whole-schedule generator.
     */
    public const string PARTICIPANT_COUNT = 'participant_count';

    /**
     * Schedule: the number of rounds the plan declares. Written by every
     * whole-schedule generator.
     */
    public const string TOTAL_ROUNDS = 'total_rounds';

    /**
     * Schedule: the number of events the plan declares. Written by every
     * whole-schedule generator.
     */
    public const string EXPECTED_EVENT_COUNT = 'expected_event_count';

    /**
     * Schedule: the number of legs. Written by the round-robin scheduler.
     */
    public const string LEGS = 'legs';

    /**
     * Schedule: the number of rounds in each leg. Written by the round-robin
     * scheduler.
     */
    public const string ROUNDS_PER_LEG = 'rounds_per_leg';

    /**
     * Schedule: the participant ID that sits out each round, keyed by round
     * number. Written by the round-robin scheduler and the whole-schedule
     * Swiss preset, empty for a field of even size. Schedule::getByesByRound()
     * reads the byes from the events instead, and holds for every schedule.
     */
    public const string BYES = 'byes';

    /**
     * Schedule: the number of rounds asked for. Written by the whole-schedule
     * Swiss preset, beside TOTAL_ROUNDS, which holds the same number.
     */
    public const string ROUNDS = 'rounds';

    /**
     * Schedule: the number of pots. Written by the pot draw.
     */
    public const string POTS = 'pots';

    /**
     * Schedule: the number of participants in each pot. Written by the pot
     * draw.
     */
    public const string POT_SIZE = 'pot_size';

    /**
     * Schedule: how many opponents each participant meets from each pot.
     * Written by the pot draw.
     */
    public const string OPPONENTS_PER_POT = 'opponents_per_pot';

    /**
     * Schedule: the seed of the draw. Written by the pot draw.
     */
    public const string SEED = 'seed';

    /**
     * Schedule: why a round-robin schedule has the generator's roles and not
     * balanced ones, as the value of a RoleAssignment\RoleFallback case.
     * Written only on a schedule that fell back
     * (RoleAssignment\RoleFallback::METADATA_KEY is the same key).
     */
    public const string ROLE_FALLBACK = 'role_fallback';

    /**
     * Round: the label of a bracket round ('semifinal', 'losers round 2').
     * Written by the elimination engines; read as typed data by
     * Stage\RoundLabel::ofRound().
     */
    public const string ROUND_LABEL = 'label';

    /**
     * Event: which leg of a two-legged knockout tie the event is, 1 or 2.
     * Written by the elimination engines on both events of such a tie, and
     * on no other event.
     */
    public const string TIE_LEG = 'tie_leg';

    /**
     * Event: which tie of the last round of a single elimination bracket
     * with a third-place tie the event belongs to. Written by the single
     * elimination engine; read through Stage\BracketTie::of()
     * (Stage\BracketTie::METADATA_KEY is the same key).
     */
    public const string BRACKET_TIE = 'bracket_tie';

    private function __construct() {}
}
