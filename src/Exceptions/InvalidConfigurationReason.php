<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Why a configuration was rejected, as a value code can branch on.
 *
 * {@see InvalidConfigurationException::getReason()} returns one of these for
 * a configuration error the library raises, so a caller does not have to
 * match the message text. A case says what kind of mistake was made, not
 * which component found it: `TooFewParticipants` comes from the round-robin
 * scheduler, the Swiss engine and the elimination engines alike. The
 * exception's context (`getContext()`) carries the values involved.
 *
 * The backing strings are stable identifiers for logs, serialization and
 * caller-side dispatch. New cases may be added in any release, so a `match`
 * over the reason needs a `default` arm.
 */
enum InvalidConfigurationReason: string
{
    // Participants

    /** Fewer participants than the format, or one pool of it, needs. */
    case TooFewParticipants = 'too_few_participants';

    /** Two participants in one list share an ID. */
    case DuplicateParticipantIds = 'duplicate_participant_ids';

    // Options and plain-data configuration

    /**
     * The number of legs is not an integer the format accepts: at least 1
     * for a round robin, 1 or 2 for an elimination tie.
     */
    case InvalidLegCount = 'invalid_leg_count';

    /**
     * The number of rounds is not a positive integer, or is more than the
     * format can provide for the participants given.
     */
    case InvalidRoundCount = 'invalid_round_count';

    /** A scheduler was given the options object of another format. */
    case UnsupportedOptions = 'unsupported_options';

    /**
     * Two settings that are each valid cannot be used together, or one
     * setting needs another that was not given.
     */
    case IncompatibleOptions = 'incompatible_options';

    /**
     * A string that selects a strategy, an algorithm or a mode is not one
     * of the known identifiers. The context lists the known ones.
     */
    case UnknownIdentifier = 'unknown_identifier';

    /**
     * A value has the wrong type: a string where an integer is needed, an
     * object of the wrong class, a list entry of the wrong shape. A required
     * key that plain-data configuration leaves out is reported this way too:
     * the value read for it is null, which is not of the type needed.
     */
    case WrongValueType = 'wrong_value_type';

    /**
     * A number has the right type and is outside the range allowed for it:
     * a weight below zero, a count below 1, a range whose start is after
     * its end.
     */
    case ValueOutOfRange = 'value_out_of_range';

    /** A list that needs at least one entry is empty. */
    case EmptyList = 'empty_list';

    /** Two entries of one list carry the same name. */
    case DuplicateName = 'duplicate_name';

    /** The configuration is valid and has no plain-data form to serialize to. */
    case NotSerializable = 'not_serializable';

    /** The leg strategy cannot produce the legs the configuration asks for. */
    case UnsatisfiableLegStrategy = 'unsatisfiable_leg_strategy';

    // Results-driven stages

    /** A further round was asked of a bracket that has already finished. */
    case BracketComplete = 'bracket_complete';

    /**
     * The next round was asked for while some ties of the current round
     * have no complete result.
     */
    case RoundPartiallyResolved = 'round_partially_resolved';

    /**
     * An event has no round number where one is required. Results are
     * recorded against the events the engines produce, which carry one.
     */
    case EventWithoutRoundNumber = 'event_without_round_number';

    /**
     * A recorded result cannot belong to the stage it was recorded in: it
     * names a participant outside the tie or the pools, spans two pools, or
     * its event does not have two participants.
     */
    case InvalidResult = 'invalid_result';

    /** Two results were recorded for the same match. */
    case DuplicateResult = 'duplicate_result';

    /**
     * A tie that must produce a winner has none: it ended level, or it has
     * no complete result, and no tie decision names a winner.
     */
    case UndecidedTie = 'undecided_tie';

    /**
     * A progression selector was given an outcome of a shape it cannot read:
     * one with no final round, or one that is not pooled.
     */
    case IncompatibleOutcome = 'incompatible_outcome';

    /** A progression selector asked for a rank the standings do not have. */
    case RankUnavailable = 'rank_unavailable';

    // Recording a results-driven stage

    /**
     * A round was recorded out of play order: its number is not above the
     * number of the last round recorded.
     */
    case RoundOutOfSequence = 'round_out_of_sequence';

    /**
     * An event, or the event of a result, does not belong to the round it
     * was recorded with: it carries another round number, or the round's
     * pairing does not hold it. An event that carries no round number at
     * all is {@see self::EventWithoutRoundNumber}.
     */
    case EventNotInRound = 'event_not_in_round';

    /**
     * Results were added or replaced in a stage that has no recorded round
     * to hold them.
     */
    case NoRoundRecorded = 'no_round_recorded';

    /** A result was to be replaced for an event that has no recorded result. */
    case ResultNotRecorded = 'result_not_recorded';

    /**
     * A result was to be replaced in a round that a later recorded round
     * was paired from. Only the last recorded round can be corrected.
     */
    case RoundSuperseded = 'round_superseded';

    /** A stage state was to be stamped with an empty engine fingerprint. */
    case EmptyEngineFingerprint = 'empty_engine_fingerprint';

    /**
     * A stage state carries the fingerprint of another engine, or of the
     * same engine under another configuration, than the one reading it.
     */
    case EngineFingerprintMismatch = 'engine_fingerprint_mismatch';

    // Repack requests

    /** A movable or pinned event has an empty ID. */
    case EmptyEventId = 'empty_event_id';

    /** An event names the same participant on both sides. */
    case IdenticalParticipants = 'identical_participants';

    /** Two events of one repack request share an ID. */
    case DuplicateEventId = 'duplicate_event_id';

    /** A pinned event sits at a session and slot the grid does not have. */
    case PinOffGrid = 'pin_off_grid';

    /** More events are pinned at one position than a slot can hold. */
    case PinCapacityExceeded = 'pin_capacity_exceeded';

    /**
     * One participant is pinned in two events at the same session and slot.
     * The exception is a {@see PinConflictException}, which carries the IDs
     * of the two events.
     */
    case PinConflict = 'pin_conflict';

    // Grids, timelines and time

    /**
     * A session, slot, round or resource index is outside the grid or
     * timeline it addresses.
     */
    case PositionOutOfRange = 'position_out_of_range';

    /**
     * A datetime, its timezone or an ISO 8601 duration cannot be parsed, or
     * the datetime does not state an instant by itself: it is relative to
     * the current time or leaves the date or its year out, or it does not
     * mean what it writes (a date or a time that does not exist, a weekday
     * name that is not the weekday of the date, a second timezone that is
     * not the first). The previous exception is the one PHP raised, where
     * PHP raised one: it accepts all of those, so that error has none.
     */
    case UnparseableTime = 'unparseable_time';

    /**
     * A time carries a timezone that contradicts the declared one: a zone
     * or offset embedded in a datetime string, or session starts in
     * different zones.
     */
    case TimezoneMismatch = 'timezone_mismatch';

    /**
     * Something that must move time forward does not: an interval of zero
     * or less, a window that ends before it starts, session starts that
     * are not ascending.
     */
    case NonAdvancingTime = 'non_advancing_time';

    /** The assigned timeline breaks one or more of the time rules given. */
    case TimeRuleViolation = 'time_rule_violation';

    /** A round has more events than the timeline has places for. */
    case TimelineCapacityExceeded = 'timeline_capacity_exceeded';

    // The generic factories on SchedulingException

    /** Built by {@see SchedulingException::constraintViolation()}. */
    case ConstraintViolation = 'constraint_violation';

    /** Built by {@see SchedulingException::invalidSchedule()}. */
    case InvalidSchedule = 'invalid_schedule';
}
