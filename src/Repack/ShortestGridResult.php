<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * What a search for the shortest grid found ({@see ShortestGridFinder}):
 * the fewest sessions on which a repack placed every event, the grid and
 * the outcome there, and what happened at every smaller number, so that
 * a caller can tell "the shortest there is" from "the shortest that was
 * found".
 *
 * @api
 */
final readonly class ShortestGridResult
{
    /**
     * The finder builds these; the constructor is for a result built by
     * hand, in a test for example, and checks nothing.
     *
     * @param int|null $sessionCount The fewest sessions on which every event was placed, or
     *                               null when none of the numbers searched placed them all
     * @param SessionGrid|null $grid The grid of that many sessions, or null
     * @param RepackOutcome|null $outcome The outcome on that grid; when nothing was found, the
     *                                    outcome on the largest grid that was repacked, or null
     *                                    when none was
     * @param array<int, int> $unplacedBySessionCount Number of sessions => events left unplaced,
     *                                                for every grid that was repacked, ascending
     * @param list<int> $ruledOut The numbers of sessions refused without a repack, ascending
     */
    public function __construct(
        private ?int $sessionCount,
        private ?SessionGrid $grid,
        private ?RepackOutcome $outcome,
        private array $unplacedBySessionCount,
        private array $ruledOut
    ) {}

    /**
     * Whether a grid was found on which every event is placed. False
     * means the search stopped at its largest number of sessions without
     * one: no number it was allowed to try placed everything.
     */
    public function isFound(): bool
    {
        return $this->sessionCount !== null;
    }

    /**
     * The fewest sessions, among the numbers searched, on which a repack
     * placed every event; null when there was none.
     */
    public function getSessionCount(): ?int
    {
        return $this->sessionCount;
    }

    /**
     * The grid of that many sessions, as the caller's factory made it;
     * null when nothing was found.
     */
    public function getGrid(): ?SessionGrid
    {
        return $this->grid;
    }

    /**
     * The outcome of the repack on the grid found: nothing unplaced, and
     * whatever gaps and late starts it has. When nothing was found, the
     * outcome on the largest grid that was repacked, which says how far
     * short that one is, or null when every number was ruled out without
     * a repack.
     */
    public function getOutcome(): ?RepackOutcome
    {
        return $this->outcome;
    }

    /**
     * Every repack the search ran: the number of sessions and how many
     * events it left unplaced, in ascending order of sessions. The last
     * entry is 0 when a grid was found.
     *
     * @return array<int, int>
     */
    public function getUnplacedBySessionCount(): array
    {
        return $this->unplacedBySessionCount;
    }

    /**
     * The numbers of sessions the search refused without a repack,
     * ascending, because counting alone shows that not every event fits:
     * a pinned event is not on that grid, the grid has fewer places than
     * the request has events, or a participant has more events than the
     * grid has positions.
     *
     * @return list<int>
     */
    public function getRuledOut(): array
    {
        return $this->ruledOut;
    }

    /**
     * Whether the number found is the fewest sessions on which any
     * placement holds every event, among the numbers searched, and not
     * only the fewest on which the repacker found one.
     *
     * True when a grid was found and every smaller number searched was
     * ruled out by counting, which is a proof. False when a repack left
     * events unplaced on a smaller grid: the repacker is a bounded
     * search, so that shows that it found no placement there, not that
     * there is none. False as well when nothing was found.
     */
    public function isProvenShortest(): bool
    {
        return $this->sessionCount !== null && count($this->unplacedBySessionCount) === 1;
    }
}
