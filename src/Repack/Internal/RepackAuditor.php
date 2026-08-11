<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackViolation;

/**
 * The final audit: violations are computed from the returned schedule's
 * observed occupancy (pins plus assignments), never from solver
 * intentions — what is reported is what is true of the output.
 *
 * Double-booking is audited even though the packer cannot produce it;
 * the brief calls it unreachable, and this audit is the proof.
 * Contiguity and late starts are reported only for (participant,
 * session) cells holding at least one movable assignment — pinned slots
 * shape the pattern, but a purely-pinned session is historical fact the
 * repack cannot influence.
 *
 * @internal
 */
final class RepackAuditor
{
    /**
     * @param array<int, array{int, int}> $positions Movable event index => [session, slot]
     * @param array<int, array{int, int}> $edges Movable event index => participant index pair
     * @param array<PinnedEvent> $pins
     * @param array<string> $eventIds Event index => caller event id
     * @param array<Participant> $participants Participant index => participant
     * @param array<string, int> $participantIndexes Participant id => index
     *
     * @return array<RepackViolation>
     */
    public function audit(
        array $positions,
        array $edges,
        array $pins,
        array $eventIds,
        array $participants,
        array $participantIndexes
    ): array {
        $occupants = [];
        $movableSlots = [];
        $pinnedSlots = [];

        foreach ($positions as $eventIndex => [$session, $slot]) {
            foreach ($edges[$eventIndex] as $pid) {
                $occupants[$session][$slot][$pid][] = $eventIds[$eventIndex];
                $movableSlots[$pid][$session][] = $slot;
            }
        }

        foreach ($pins as $pin) {
            foreach ($pin->getParticipants() as $participant) {
                $pid = $participantIndexes[$participant->getId()];
                $occupants[$pin->getSession()][$pin->getSlot()][$pid][] = $pin->getId();
                $pinnedSlots[$pid][$pin->getSession()][] = $pin->getSlot();
            }
        }

        $violations = [];

        ksort($occupants);
        foreach ($occupants as $session => $slots) {
            ksort($slots);
            foreach ($slots as $slot => $byParticipant) {
                ksort($byParticipant);
                foreach ($byParticipant as $pid => $collidingIds) {
                    if (count($collidingIds) < 2) {
                        continue;
                    }
                    sort($collidingIds, SORT_STRING);
                    $violations[] = new ParticipantDoubleBooked(
                        $participants[$pid],
                        $session,
                        $slot,
                        $collidingIds
                    );
                }
            }
        }

        ksort($movableSlots);
        foreach ($movableSlots as $pid => $sessions) {
            ksort($sessions);
            foreach ($sessions as $session => $slots) {
                $occupied = array_values(array_unique(array_merge(
                    $slots,
                    $pinnedSlots[$pid][$session] ?? []
                )));
                sort($occupied);

                $first = $occupied[0];
                $last = $occupied[count($occupied) - 1];
                $gapSlots = ($last - $first + 1) - count($occupied);
                if ($gapSlots > 0) {
                    $violations[] = new ContiguityBroken($participants[$pid], $session, $gapSlots, $occupied);
                }
            }
        }

        foreach ($movableSlots as $pid => $sessions) {
            foreach ($sessions as $session => $slots) {
                $occupied = array_merge($slots, $pinnedSlots[$pid][$session] ?? []);
                $first = min($occupied);
                if ($first > 0) {
                    $violations[] = new LateStart($participants[$pid], $session, $first);
                }
            }
        }

        return $violations;
    }
}
