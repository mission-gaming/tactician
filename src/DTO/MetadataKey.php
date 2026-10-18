<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

/**
 * The metadata keys whose meaning the stable surface owns, as constants:
 * the keys the round-robin scheduler writes on a schedule.
 *
 * Metadata is free-form: an application may put any key on a schedule, a
 * round, an event, a participant or a result. These are keys the library
 * itself writes, so that an application reads them by name instead of by a
 * string it copied. The values are the strings the library has always
 * written and do not change between releases. The other generators write
 * some of these keys too, as each constant says.
 *
 * The keys only the experimental formats write (the rounds of the Swiss
 * preset, the pots of the pot draw, the label of a bracket round, the leg
 * of a two-legged tie, the tie of a final with a third-place tie) are in
 * Stage\FormatMetadataKey, and are as experimental as those formats.
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
     * Schedule: why a round-robin schedule has the generator's roles and not
     * balanced ones, as the value of a RoleAssignment\RoleFallback case.
     * Written only on a schedule that fell back
     * (RoleAssignment\RoleFallback::METADATA_KEY is the same key). The key
     * is stable; the reasons it can hold are the cases of RoleFallback, which
     * is experimental.
     */
    public const string ROLE_FALLBACK = 'role_fallback';

    private function __construct() {}
}
