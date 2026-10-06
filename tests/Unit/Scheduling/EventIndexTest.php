<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\EventIndex;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Tests\Support\GeneratedEvents;
use Random\Engine\Mt19937;
use Random\Randomizer;

// What each lookup answered before there was an index: a scan of the event
// list. The index must answer the same events, under the same keys, in the
// same order.

/**
 * @param array<array-key, Event> $events
 * @return array<array-key, Event>
 */
function scanForParticipant(array $events, Participant $participant): array
{
    return array_filter($events, static fn(Event $event): bool => $event->hasParticipant($participant));
}

/**
 * @param array<array-key, Event> $events
 * @return array<array-key, Event>
 */
function scanBetween(array $events, Participant $first, Participant $second): array
{
    return array_filter(
        $events,
        static fn(Event $event): bool => $event->hasParticipant($first) && $event->hasParticipant($second)
    );
}

/**
 * @param array<array-key, Event> $events
 * @return array<array-key, Event>
 */
function scanRoundRange(array $events, int $firstRound, int $lastRound): array
{
    return array_filter($events, static function (Event $event) use ($firstRound, $lastRound): bool {
        $number = $event->getRound()?->getNumber();

        return $number !== null && $number >= $firstRound && $number <= $lastRound;
    });
}

/**
 * hasEventBetween() as it scanned: some event holds every given participant.
 *
 * @param array<array-key, Event> $events
 * @param array<Participant> $participants
 */
function scanHasEventBetween(array $events, array $participants, int $participantsPerEvent): bool
{
    if (count($participants) !== $participantsPerEvent) {
        return false;
    }

    foreach ($events as $event) {
        $holdsAll = true;
        foreach ($participants as $participant) {
            if (!$event->hasParticipant($participant)) {
                $holdsAll = false;
                break;
            }
        }
        if ($holdsAll) {
            return true;
        }
    }

    return false;
}

/**
 * Every lookup of a context, against a scan of its own event list.
 *
 * @param list<Participant> $everyone The field and an outsider
 */
function expectContextToAgreeWithAScan(SchedulingContext $context, array $everyone, int $maxRound): void
{
    $events = $context->getExistingEvents();
    $plan = $context->getPlan();
    $legs = $plan->getLegs() ?? 1;
    $roundsPerLeg = $plan->getRoundsPerLeg() ?? 0;

    foreach ($everyone as $participant) {
        expect($context->getEventsForParticipant($participant))->toBe(scanForParticipant($events, $participant));
    }

    foreach ($everyone as $first) {
        foreach ($everyone as $second) {
            $between = scanBetween($events, $first, $second);
            expect($context->getEventsBetween($first, $second))->toBe($between);
            expect($context->haveParticipantsPlayed($first, $second))
                ->toBe($first->getId() !== $second->getId() && $between !== []);

            foreach ([[$first], [$first, $second], [$first, $second, $everyone[0]], [$second, $second]] as $group) {
                expect($context->hasEventBetween($group))
                    ->toBe(scanHasEventBetween($events, $group, $context->getParticipantsPerEvent()));
            }
        }
    }
    expect($context->hasEventBetween([]))->toBe(scanHasEventBetween($events, [], $context->getParticipantsPerEvent()));

    for ($round = -1; $round <= $maxRound + 1; ++$round) {
        expect($context->getEventsInRound($round))->toBe(scanRoundRange($events, $round, $round));
    }

    for ($leg = -1; $leg <= $legs + 1; ++$leg) {
        $expected = [];
        if ($leg >= 1 && $leg <= $legs) {
            if ($legs === 1) {
                $expected = $events;
            } elseif ($roundsPerLeg > 0) {
                $expected = scanRoundRange($events, ($leg - 1) * $roundsPerLeg + 1, $leg * $roundsPerLeg);
            }
        }
        expect($context->getEventsForLeg($leg))->toBe($expected);
    }
}

describe('EventIndex', function (): void {
    it('answers what a scan answers, for any event list', function (): void {
        $randomizer = new Randomizer(new Mt19937(20_261_006));

        for ($case = 0; $case < 150; ++$case) {
            $field = GeneratedEvents::field($randomizer->getInt(2, 9));
            $everyone = [...$field, new Participant('outsider', 'Outsider')];
            $maxRound = 3 * count($field);
            $events = GeneratedEvents::rekeyed(
                $randomizer,
                GeneratedEvents::events($randomizer, $field, $randomizer->getInt(0, 30), $maxRound)
            );
            $index = new EventIndex($events);

            // The maps are built on first use, in whatever order they are asked for.
            $questions = $randomizer->shuffleArray(['participant', 'pair', 'round', 'range']);
            foreach ($questions as $question) {
                if ($question === 'participant') {
                    foreach ($everyone as $participant) {
                        expect($index->forParticipant($participant->getId()))->toBe(scanForParticipant($events, $participant));
                    }
                } elseif ($question === 'pair') {
                    foreach ($everyone as $first) {
                        foreach ($everyone as $second) {
                            expect($index->between($first->getId(), $second->getId()))->toBe(scanBetween($events, $first, $second));
                        }
                    }
                } elseif ($question === 'round') {
                    for ($round = 0; $round <= $maxRound + 1; ++$round) {
                        expect($index->inRound($round))->toBe(scanRoundRange($events, $round, $round));
                    }
                } else {
                    foreach ([[1, 1], [1, $maxRound], [3, 7], [5, 4], [$maxRound, $maxRound + 9]] as [$firstRound, $lastRound]) {
                        expect($index->inRoundRange($firstRound, $lastRound))->toBe(scanRoundRange($events, $firstRound, $lastRound));
                    }
                }
            }
        }
    });

    it('tells apart ids that PHP treats as the same number or the same array key', function (): void {
        $one = new Participant('1', 'One');
        $paddedOne = new Participant('01', 'Padded one');
        $thousand = new Participant('1000', 'Thousand');
        $exponent = new Participant('1e3', 'Exponent');
        $events = [new Event([$one, $thousand]), new Event([$paddedOne, $exponent]), new Event([$one, $exponent])];
        $index = new EventIndex($events);

        expect($index->forParticipant('1'))->toBe([0 => $events[0], 2 => $events[2]]);
        expect($index->forParticipant('01'))->toBe([1 => $events[1]]);
        expect($index->between('1', '1000'))->toBe([0 => $events[0]]);
        expect($index->between('1000', '1'))->toBe([0 => $events[0]]);
        expect($index->between('01', '1000'))->toBe([]);
        expect($index->between('1e3', '1'))->toBe([2 => $events[2]]);
    });

    it('counts an event once for a participant it lists twice', function (): void {
        $first = new Participant('a', 'A');
        $second = new Participant('b', 'B');
        $events = [new Event([$first, $first]), new Event([$first, $second, $first])];
        $index = new EventIndex($events);

        expect($index->forParticipant('a'))->toBe($events);
        expect($index->between('a', 'a'))->toBe($events);
        expect($index->between('a', 'b'))->toBe([1 => $events[1]]);
    });
});

describe('SchedulingContext lookups', function (): void {
    it('agree with a scan of the event list, through withEvents() and withNextLeg()', function (): void {
        $randomizer = new Randomizer(new Mt19937(41));

        for ($case = 0; $case < 60; ++$case) {
            $field = GeneratedEvents::field($randomizer->getInt(2, 8));
            $everyone = [...$field, new Participant('outsider', 'Outsider')];
            $maxRound = 3 * count($field);
            $plan = $randomizer->getInt(0, 3) === 0
                ? new SwissPlan($field, $randomizer->getInt(0, 1) === 0 ? null : $randomizer->getInt(1, 6))
                : new RoundRobinPlan($field, $randomizer->getInt(1, 3));
            $make = static fn(int $most): array => GeneratedEvents::rekeyed(
                $randomizer,
                GeneratedEvents::events($randomizer, $field, $randomizer->getInt(0, $most), $maxRound)
            );

            $context = new SchedulingContext($field, $plan, $make(20), $randomizer->getInt(1, 4), [1, 2, 2, 2, 3][$randomizer->getInt(0, 4)]);

            // Sometimes the parent's maps exist before a child is derived
            // from it and sometimes not: the child must be right either way.
            for ($step = 0; $step < 5; ++$step) {
                if ($randomizer->getInt(0, 1) === 1) {
                    expectContextToAgreeWithAScan($context, $everyone, $maxRound);
                }
                $context = $randomizer->getInt(0, 3) === 0 ? $context->withNextLeg() : $context->withEvents($make(6));
            }
            expectContextToAgreeWithAScan($context, $everyone, $maxRound);
        }
    });

    it('appends with the keys an array spread gives', function (): void {
        $field = GeneratedEvents::field(4);
        $plan = new RoundRobinPlan($field, 1);
        $events = [
            new Event([$field[0], $field[1]], new Round(1)),
            new Event([$field[2], $field[3]], new Round(1)),
            new Event([$field[0], $field[2]], new Round(2)),
        ];

        // A list parent: the appended events take the next integers, and a
        // string key is kept.
        $context = (new SchedulingContext($field, $plan, [$events[0]]))->withEvents(['late' => $events[1], 7 => $events[2]]);
        expect($context->getEventsForParticipant($field[2]))->toBe(['late' => $events[1], 1 => $events[2]]);

        // A parent whose keys are not 0, 1, 2, ...: the spread renumbers its
        // integer keys, so nothing built for the parent may be carried over.
        $parent = new SchedulingContext($field, $plan, [5 => $events[0], 9 => $events[1]]);
        expect($parent->getEventsForParticipant($field[2]))->toBe([9 => $events[1]]);
        $child = $parent->withEvents([$events[2]]);
        expect($child->getExistingEvents())->toBe([0 => $events[0], 1 => $events[1], 2 => $events[2]]);
        expect($child->getEventsForParticipant($field[2]))->toBe([1 => $events[1], 2 => $events[2]]);
        expect($child->getEventsBetween($field[0], $field[2]))->toBe([2 => $events[2]]);
    });

    it('leaves a parent context as it was', function (): void {
        $field = GeneratedEvents::field(4);
        $plan = new RoundRobinPlan($field, 1);
        $first = new Event([$field[0], $field[1]], new Round(1));
        $second = new Event([$field[0], $field[2]], new Round(2));

        $parent = new SchedulingContext($field, $plan, [$first]);
        expect($parent->getEventsForParticipant($field[0]))->toBe([$first]);

        $child = $parent->withEvents([$second]);
        expect($child->getEventsForParticipant($field[0]))->toBe([$first, $second]);
        expect($parent->getEventsForParticipant($field[0]))->toBe([$first]);
        expect($parent->getEventsInRound(2))->toBe([]);
        expect($parent->haveParticipantsPlayed($field[0], $field[2]))->toBeFalse();
    });
});

/**
 * A context that answers for its events its own way, as an application's
 * subclass may: here it reports a round 1 meeting of the first two
 * participants that the list it was built with does not hold.
 */
final readonly class RememberedMeetingContext extends SchedulingContext
{
    #[Override]
    public function getExistingEvents(): array
    {
        $participants = $this->getParticipants();

        return [...parent::getExistingEvents(), new Event([$participants[0], $participants[1]], new Round(1))];
    }

    #[Override]
    public function getEventsForLeg(int $leg): array
    {
        return $this->getExistingEvents();
    }
}

describe('The pair-history constraints', function (): void {
    it('give the verdict a scan of the context gives', function (): void {
        $randomizer = new Randomizer(new Mt19937(7));

        for ($case = 0; $case < 200; ++$case) {
            $field = GeneratedEvents::field($randomizer->getInt(2, 7));
            $legs = $randomizer->getInt(1, 3);
            $plan = new RoundRobinPlan($field, $legs);
            $roundsPerLeg = $plan->getRoundsPerLeg();
            $maxRound = $legs * $roundsPerLeg;
            $events = GeneratedEvents::rekeyed(
                $randomizer,
                GeneratedEvents::events($randomizer, $field, $randomizer->getInt(0, 25), $maxRound)
            );
            $currentLeg = $randomizer->getInt(1, $legs);
            $context = new SchedulingContext($field, $plan, $events, $currentLeg);
            $legEvents = $legs === 1
                ? $events
                : scanRoundRange($events, ($currentLeg - 1) * $roundsPerLeg + 1, $currentLeg * $roundsPerLeg);
            $minimumRest = $randomizer->getInt(1, 4);

            foreach (GeneratedEvents::events($randomizer, $field, 12, $maxRound) as $candidate) {
                $members = $candidate->getParticipants();
                $repeatAnywhere = false;
                $repeatInLeg = false;
                $rested = true;
                for ($i = 0; $i < count($members); ++$i) {
                    for ($j = $i + 1; $j < count($members); ++$j) {
                        if ($members[$i]->getId() !== $members[$j]->getId()) {
                            $repeatAnywhere = $repeatAnywhere || scanBetween($events, $members[$i], $members[$j]) !== [];
                            $repeatInLeg = $repeatInLeg || scanBetween($legEvents, $members[$i], $members[$j]) !== [];
                        }

                        $lastMeeting = null;
                        foreach (scanBetween($events, $members[$i], $members[$j]) as $meeting) {
                            $number = $meeting->getRound()?->getNumber();
                            if ($number !== null) {
                                $lastMeeting = max($lastMeeting ?? $number, $number);
                            }
                        }
                        $candidateRound = $candidate->getRound()?->getNumber() ?? 0;
                        if ($lastMeeting !== null && $candidateRound - $lastMeeting < $minimumRest) {
                            $rested = false;
                        }
                    }
                }

                expect((new NoRepeatPairings(acrossLegs: true))->isSatisfied($candidate, $context))->toBe(!$repeatAnywhere);
                expect((new NoRepeatPairings())->isSatisfied($candidate, $context))->toBe(!$repeatInLeg);
                expect((new MinimumRestPeriodsConstraint($minimumRest))->isSatisfied($candidate, $context))->toBe($rested);
            }
        }
    });

    it('read a subclassed context through the methods it overrides', function (): void {
        $field = GeneratedEvents::field(4);
        $plan = new RoundRobinPlan($field, 1);
        $candidate = new Event([$field[1], $field[0]], new Round(2));

        // No event is recorded, so the two have not met.
        $plain = new SchedulingContext($field, $plan, []);
        expect((new NoRepeatPairings())->isSatisfied($candidate, $plain))->toBeTrue();
        expect((new NoRepeatPairings(acrossLegs: true))->isSatisfied($candidate, $plain))->toBeTrue();
        expect((new MinimumRestPeriodsConstraint(2))->isSatisfied($candidate, $plain))->toBeTrue();

        // By the subclass's own account they met in round 1. The index knows
        // only the list the context was built with, so a constraint that
        // read it here would miss the meeting.
        $remembering = new RememberedMeetingContext($field, $plan, []);
        expect((new NoRepeatPairings())->isSatisfied($candidate, $remembering))->toBeFalse();
        expect((new NoRepeatPairings(acrossLegs: true))->isSatisfied($candidate, $remembering))->toBeFalse();
        expect((new MinimumRestPeriodsConstraint(2))->isSatisfied($candidate, $remembering))->toBeFalse();
    });
});
