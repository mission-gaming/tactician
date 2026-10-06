<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Benchmark;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Schedule repack of a complete single round robin, at the two field sizes
 * the acceptance of the benchmark suite names: 24 participants (276 events)
 * and 40 (780).
 *
 * Each size is measured on two grids. The even grid has sessions of four
 * slots at half the field per slot, with a couple of sessions more than the
 * events need. The uneven grid is nearer to a real season: sessions of four,
 * five and six slots by turns, and some events pinned where they are. All
 * run with the default step budget, which several of them use up: these
 * measure what a caller waits for, not the best case.
 *
 * Run with `composer bench`. RoundRobinBench says how the classes and the
 * tool that runs them are kept apart.
 */
final class RepackBench
{
    /** @var array<string, RepackRequest> */
    private array $requests;

    /**
     * @throws InvalidConfigurationException
     */
    public function __construct()
    {
        $this->requests = [
            'even 24' => self::request(24, uneven: false),
            'uneven 24' => self::request(24, uneven: true),
            'even 40' => self::request(40, uneven: false),
            'uneven 40' => self::request(40, uneven: true),
        ];
    }

    /**
     * @throws InvalidConfigurationException
     * @throws RepackViolationsException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function bench24OnEvenSessions(): void
    {
        $this->repack('even 24');
    }

    /**
     * @throws InvalidConfigurationException
     * @throws RepackViolationsException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function bench24OnUnevenSessionsWithPins(): void
    {
        $this->repack('uneven 24');
    }

    /**
     * @throws InvalidConfigurationException
     * @throws RepackViolationsException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function bench40OnEvenSessions(): void
    {
        $this->repack('even 40');
    }

    /**
     * @throws InvalidConfigurationException
     * @throws RepackViolationsException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function bench40OnUnevenSessionsWithPins(): void
    {
        $this->repack('uneven 40');
    }

    /**
     * @throws InvalidConfigurationException
     * @throws RepackViolationsException
     * @throws RuntimeException When an event is neither assigned nor reported unplaced
     */
    private function repack(string $name): void
    {
        $request = $this->requests[$name];
        $outcome = (new ScheduleRepacker())->repack($request);

        if (count($outcome->getAssignments()) + count($outcome->getUnplaced()) !== count($request->getMovableEvents())) {
            throw new RuntimeException("The benchmark did not account for every movable event of the {$name} request.");
        }
    }

    /**
     * A complete single round robin of the field, shuffled with a fixed
     * seed so that the events do not arrive round by round.
     *
     * @throws InvalidConfigurationException
     */
    private static function request(int $size, bool $uneven): RepackRequest
    {
        $participants = [];
        for ($i = 1; $i <= $size; ++$i) {
            $participants[] = new Participant(sprintf('p%02d', $i), "Participant {$i}");
        }

        $pairs = [];
        for ($a = 0; $a < $size; ++$a) {
            for ($b = $a + 1; $b < $size; ++$b) {
                $pairs[] = [$a, $b];
            }
        }
        $pairs = (new Randomizer(new Mt19937($size)))->shuffleArray($pairs);

        // Half the field can play at once. The even grid has two sessions
        // more than the events fill; the uneven one a fifth more room.
        $capacity = intdiv($size, 2);
        $roomNeeded = $uneven ? intdiv(count($pairs) * 6, 5) : count($pairs) + 2 * 4 * $capacity;
        $slotsBySession = [];
        for ($room = 0; $room < $roomNeeded; $room += $slots * $capacity) {
            $slots = $uneven ? 4 + count($slotsBySession) % 3 : 4;
            $slotsBySession[] = $slots;
        }
        $grid = SessionGrid::shapeOnly(count($slotsBySession), 4, $slotsBySession, $capacity);

        // On the uneven grid, one event in every 78 stays where it is: a
        // different session and slot each time, so no participant is pinned
        // twice at one position.
        $pinEvery = $uneven ? 78 : 0;
        $movable = [];
        $pinned = [];
        foreach ($pairs as $index => [$a, $b]) {
            $id = sprintf('e%04d', $index);
            if ($pinEvery > 0 && $index % $pinEvery === 0) {
                $session = count($pinned) % count($slotsBySession);
                $pinned[] = new PinnedEvent($id, $participants[$a], $participants[$b], $session, count($pinned) % $slotsBySession[$session]);
            } else {
                $movable[] = new MovableEvent($id, $participants[$a], $participants[$b]);
            }
        }

        return new RepackRequest($movable, $pinned, $grid);
    }
}
