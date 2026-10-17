<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * A figure of the outcome that a repack is asked to keep low, after the
 * things that always come first.
 *
 * A repack places as many events as it can, then leaves as few gap slots
 * as it can, and neither is ever traded for an objective: a request with
 * objectives places at least as many events as the same request without
 * them, with no more gap slots. Among schedules that are equal on those
 * two, the objectives of {@see RepackOptions::$objectives} decide, the
 * first one first and a later one only between schedules equal on every
 * one before it. The two weights of the options, and late starts, come
 * after every objective.
 *
 * None of the three is optimised exactly. How each is pursued is stated on
 * its case, and the design note (`docs/design/schedule-repack.md`,
 * "Objectives") has what was measured. Each is the figure the outcome
 * reports whether or not it is an objective.
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
     * where it fits and nothing that comes before it gets worse.
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
     * that comes before it gets worse.
     */
    case FewestIdleSessions = 'fewest_idle_sessions';
}
