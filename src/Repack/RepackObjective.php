<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * A figure of the outcome that a repack is asked to keep low, after the
 * number of events placed, which always comes first.
 *
 * A repack places as many events as it can, and that is never traded for
 * an objective: a request with objectives places at least as many events
 * as the same request without them. The objectives of
 * {@see RepackOptions::$objectives} then rank the schedules that are equal
 * on events placed, the first one first and a later one only between
 * schedules equal on every one before it. Gap slots are a figure of the
 * same list ({@see self::FewestGapSlots}): where the list does not name
 * them they rank first, ahead of every objective, which is the ranking of
 * a repack that names no objective at all; where it names them they rank
 * where they are named, and the objectives before them may cost gap slots.
 * The two weights of the options, and late starts, come after every
 * objective.
 *
 * None of them is optimised exactly. How each is pursued is stated on its
 * case, and the design note (`docs/design/schedule-repack.md`,
 * "Objectives" and "Objectives ahead of gap slots") has what was
 * measured. Each is the figure the outcome reports whether or not it is
 * an objective.
 *
 * The backing strings are stable identifiers: they are what
 * {@see RepackOptions::fromArray()} reads and `toArray()` writes.
 *
 * @api
 */
enum RepackObjective: string
{
    /**
     * As few moved events as possible ({@see RepackOutcome::getMovedCount()}):
     * an event that states a current position stays there where it can.
     *
     * This one is searched for. The repacker makes a schedule in which
     * every event that can stay where it is does, and places the others
     * around them; where that places fewer events or leaves more gap slots
     * than the schedule made with no objective, it lets the events it
     * held move again where a placement step or the search for fewer gap
     * slots needs them to, then lets go of the ones that had to do with
     * what is still worse and tries again, three times at most. It never
     * returns a schedule that is worse on events placed or on gap slots
     * than the one made with no objective. So where the calendar as it
     * stands has gap slots that another schedule avoids, events move to
     * close them, and the result can be the schedule made with no
     * objective, in which most events have moved. It needs events with
     * current positions to act on, and does nothing without them.
     *
     * Under this objective the current positions also decide where the
     * request would leave it to the ids: between participants it does not
     * otherwise tell apart, and between events of the same two
     * participants. So the same request under other ids comes back with
     * the same figures, the moved events included, wherever the ids would
     * decide only between participants that a symmetry of the request
     * exchanges; the schedule made with no objective, which this one is
     * never worse than, is made in that order too, and is the same
     * schedule up to such a symmetry ({@see ScheduleRepacker}).
     */
    case LeastChurn = 'least_churn';

    /**
     * As few lone-event sessions as possible
     * ({@see RepackOutcome::getLoneEventSessionCount()}): sessions opened
     * for a single event.
     *
     * A tie-break and a repair, not a search: it decides between the
     * schedules the repacker makes anyway where they are equal on what
     * comes before it, and then the one event of a lone-event session is
     * moved to another session, or an event of another session into it,
     * where it fits and nothing that comes before it gets worse. Ranked
     * ahead of gap slots, the schedules laid out on the sessions in other
     * shapes are among those it decides between
     * ({@see self::FewestLoneParticipantSessions}).
     */
    case FewestLoneEventSessions = 'fewest_lone_event_sessions';

    /**
     * As few idle sessions as possible
     * ({@see RepackOutcome::getIdleSessionCount()}): sessions a participant
     * sits out between two sessions it has an event in.
     *
     * A tie-break and a repair, as for the lone-event sessions: it decides
     * between the schedules the repacker makes anyway, and then single
     * events of a participant with an idle session are moved into the
     * sessions between its first and its last where they fit and nothing
     * that comes before it gets worse. Ranked ahead of gap slots, the
     * schedules laid out on the sessions in other shapes are among those
     * it decides between, as for the lone-event sessions: they fill the
     * sessions from the first on, with none left empty between two that
     * are used.
     */
    case FewestIdleSessions = 'fewest_idle_sessions';

    /**
     * As few gap slots as possible ({@see RepackOutcome::getGapSlotCount()}):
     * slots a participant waits between two of its events in one session.
     *
     * Not an objective of its own but the place of gap slots in the
     * ranking. Left out of the list, gap slots rank ahead of every
     * objective, and a list that starts with this case ranks exactly as
     * the same list without it. Named after other objectives, it lets
     * those rank ahead of gap slots: the schedules a repack makes are
     * then compared, after events placed, on the objectives before it,
     * and the searches for those objectives run as described on their
     * cases, with the search for fewer gap slots kept only where it costs
     * nothing that ranks before it.
     */
    case FewestGapSlots = 'fewest_gap_slots';

    /**
     * As few lone participant-sessions as possible
     * ({@see RepackOutcome::getLoneParticipantSessionCount()}): a
     * participant and a session in which the participant has exactly one
     * event, pinned events counted, where that one event is a movable one.
     * A participant that turns up for a single event in a session.
     *
     * It is not {@see self::FewestLoneEventSessions}, which counts
     * sessions that hold one event in all: a session of two events between
     * four participants is no lone-event session and is four lone
     * participant-sessions.
     *
     * Ranked after gap slots, it is a tie-break and a repair, as the
     * lone-event sessions are: it decides between the schedules the
     * repacker makes anyway, and then the event of a lone participant-
     * session is moved into another session of that participant, or
     * another event of the participant into its session, where it fits
     * and nothing that comes before it gets worse.
     *
     * Ranked ahead of gap slots, it is searched for as well. A repack
     * fills the sessions from the first on, so a round robin whose rounds
     * do not divide into the sessions ends with a session of one round,
     * in which every participant has one event. The events are therefore
     * placed again on the first sessions of the grid with their slots cut
     * to as many as the events need (as many as the busiest participant
     * has events, and one more where no shape of that many places every
     * event), spread evenly
     * over the fewest sessions that hold them and up to two more; a
     * session with a pinned event keeps all its slots. The schedule that
     * is best by the ranking is kept, and a participant that has an event
     * in nearly every slot then has two or more in every session it plays
     * in. On complete single round robins of 4 to 24 participants on
     * sessions of four slots it left no lone participant-session at all
     * (the design note has the figures).
     */
    case FewestLoneParticipantSessions = 'fewest_lone_participant_sessions';
}
