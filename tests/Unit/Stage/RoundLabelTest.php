<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\MetadataKey;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundLabel;
use MissionGaming\Tactician\Stage\RoundLabelKind;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;

/**
 * The pairings of a stage an engine plays to the end. The winner of an
 * event is the participant the given rule names, for both legs of a tie,
 * except in a first grand final, which the second-named participant (the
 * champion of the losers bracket) wins, so that the bracket resets.
 *
 * @param Closure(Participant, Participant): Participant $winner
 * @return list<RoundPairing>
 * @throws InvalidConfigurationException
 * @throws NoValidPairingException
 */
function roundLabelPairings(StageEngineInterface $engine, int $size, Closure $winner): array
{
    $field = [];
    for ($i = 1; $i <= $size; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}");
    }

    $state = StageState::start($field);
    $pairings = [];
    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            function (Event $event) use ($winner, $pairing): Result {
                [$first, $second] = $event->getParticipants();

                return new Result($event, $pairing->getLabel() === 'grand final' ? $second : $winner($first, $second));
            },
            $pairing->getEvents()
        ));
        $pairings[] = $pairing;
    }

    return $pairings;
}

describe('RoundLabel', function (): void {
    it('reads every label the elimination engines write, and gives the text back', function (
        StageEngineInterface $engine,
        bool $favouriteWins,
        int $smallest
    ): void {
        $winner = fn(Participant $first, Participant $second): Participant => (((int) substr($first->getId(), 1) < (int) substr($second->getId(), 1)) === $favouriteWins) ? $first : $second;
        $seen = [];

        for ($size = $smallest; $size <= 34; ++$size) {
            foreach (roundLabelPairings($engine, $size, $winner) as $pairing) {
                $text = $pairing->getLabel();
                $label = $pairing->getRoundLabel();

                expect($text)->not->toBeNull()
                    ->and($label)->not->toBeNull("'{$text}' was not read")
                    ->and($label?->toString())->toBe($text)
                    ->and((string) $label)->toBe($text);

                foreach ($pairing->getEvents() as $event) {
                    $round = $event->getRound();
                    expect($round)->not->toBeNull()
                        ->and($round?->getMetadataValue(MetadataKey::ROUND_LABEL))->toBe($text)
                        ->and($round === null ? null : RoundLabel::ofRound($round)?->toString())->toBe($text);
                }

                $seen[$label?->getKind()->value] = true;
            }
        }

        expect($seen)->not->toBe([]);
    })->with([
        'single elimination' => [new SingleEliminationEngine(), true, 2],
        'single elimination, two legs and a third place' => [new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true)), true, 3],
        'double elimination, the favourite wins' => [new DoubleEliminationEngine(), true, 2],
        'double elimination, the outsider wins' => [new DoubleEliminationEngine(), false, 2],
    ]);

    it('meets every kind in the brackets the engines play', function (): void {
        $favourite = fn(Participant $first, Participant $second): Participant => ((int) substr($first->getId(), 1) < (int) substr($second->getId(), 1)) ? $first : $second;
        $outsider = fn(Participant $first, Participant $second): Participant => $favourite($first, $second) === $first ? $second : $first;

        $kinds = [];
        foreach ([
            [new SingleEliminationEngine(), 32, $favourite],
            [new DoubleEliminationEngine(), 8, $favourite],
            [new DoubleEliminationEngine(), 8, $outsider],
        ] as [$engine, $size, $winner]) {
            foreach (roundLabelPairings($engine, $size, $winner) as $pairing) {
                $kind = $pairing->getRoundLabel()?->getKind();
                if ($kind !== null) {
                    $kinds[$kind->value] = $kind;
                }
            }
        }

        $expected = array_map(fn(RoundLabelKind $kind): string => $kind->value, RoundLabelKind::cases());
        $found = array_keys($kinds);
        sort($expected);
        sort($found);

        expect($found)->toBe($expected);
    });

    it('reads the parts of a label', function (string $text, RoundLabelKind $kind, ?int $number): void {
        $label = RoundLabel::fromString($text);

        expect($label?->getKind())->toBe($kind)
            ->and($label?->getNumber())->toBe($number)
            ->and(RoundLabel::of($kind, $number)->toString())->toBe($text);
    })->with([
        ['final', RoundLabelKind::Final, null],
        ['semifinal', RoundLabelKind::Semifinal, null],
        ['quarterfinal', RoundLabelKind::Quarterfinal, null],
        ['round of 16', RoundLabelKind::RoundOf, 16],
        ['winners round 1', RoundLabelKind::WinnersRound, 1],
        ['winners final', RoundLabelKind::WinnersFinal, null],
        ['losers round 12', RoundLabelKind::LosersRound, 12],
        ['losers final', RoundLabelKind::LosersFinal, null],
        ['grand final', RoundLabelKind::GrandFinal, null],
        ['grand final reset', RoundLabelKind::GrandFinalReset, null],
    ]);

    it('does not guess at text the engines do not write', function (string $text): void {
        expect(RoundLabel::fromString($text))->toBeNull();
    })->with([
        'empty' => [''],
        'a numbered kind without its number' => ['round of'],
        'a number on a kind that names none' => ['final 2'],
        'a leading zero' => ['round of 08'],
        'zero' => ['losers round 0'],
        'other case' => ['Final'],
        'extra space' => ['round of  16'],
        'a wording of the application' => ['Matchday 3'],
        'a negative number' => ['winners round -1'],
    ]);

    it('refuses a number where the kind names none and none where it names one', function (Closure $build): void {
        expect($build)->toThrow(InvalidInputException::class);
    })->with([
        'numbered kind without a number' => [fn() => RoundLabel::of(RoundLabelKind::RoundOf)],
        'numbered kind with zero' => [fn() => RoundLabel::of(RoundLabelKind::LosersRound, 0)],
        'unnumbered kind with a number' => [fn() => RoundLabel::of(RoundLabelKind::Final, 1)],
    ]);

    it('reads no label from a round without one, or from a Swiss round', function (): void {
        $field = [new Participant('a', 'A'), new Participant('b', 'B'), new Participant('c', 'C'), new Participant('d', 'D')];
        $pairing = (new SwissPairingEngine())->pairNextRound(StageState::start($field));

        expect($pairing->getLabel())->toBeNull()
            ->and($pairing->getRoundLabel())->toBeNull()
            ->and(RoundLabel::ofRound(new Round(1)))->toBeNull()
            ->and(RoundLabel::ofRound(new Round(1, [MetadataKey::ROUND_LABEL => 3])))->toBeNull()
            ->and((new RoundPairing(1, 'Matchday 1', []))->getRoundLabel())->toBeNull();
    });
});
