<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketFold;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketRound;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\BracketSlot;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\SlotPlaceholder;
use MissionGaming\Tactician\Stage\SlotSource;

/**
 * Builds the bracket view (Stage\Bracket) from what an elimination
 * engine's walk through a state kept (BracketReading::$walked).
 *
 * The rounds the walk went through are shown as it read them. The rounds
 * after the one it stopped at are the shape of the bracket from there: for
 * single elimination, halving the nodes of the last round it reached; for
 * double elimination, the fixed sequence of stages the engine walks. Every
 * slot is given its source, and a slot without an entrant its placeholder,
 * from the node it is filled from as that node now stands.
 *
 * @internal Not public API: the bracket view of the two elimination engines.
 *
 * @phpstan-import-type WalkedNode from BracketReading
 * @phpstan-import-type WalkedRound from BracketReading
 *
 * @phpstan-type SourceSpec array{source: SlotSource, nodes: list<string>, reseeded: bool, draw: int|null}
 * @phpstan-type SlotDraft array{participant: Participant|null, spec: SourceSpec, notPlayed: bool}
 * @phpstan-type NodeDraft array{side: BracketSide, round: int, position: int, kind: BracketTie|null, slots: list<SlotDraft>, status: BracketNodeStatus, advancer: Participant|null, losers: list<Participant>, events: list<Event>, number: int|null}
 * @phpstan-type RoundDraft array{side: BracketSide, round: int, slotCount: int, number: int|null, manual: bool, next: bool, nodes: list<NodeDraft>}
 */
final class BracketViewBuilder
{
    /**
     * The view of a single elimination bracket.
     *
     * @param int $size The number of slots of the entry fold
     */
    public static function singleElimination(BracketReading $reading, bool $reseeded, bool $thirdPlace, int $size): Bracket
    {
        /** @var list<RoundDraft> $drafts */
        $drafts = [];
        /** @var array<string, NodeDraft> $nodes */
        $nodes = [];
        $previous = null;

        foreach ($reading->walked as $walked) {
            $specs = self::mainSpecs(count($walked['slots']), $previous, $reseeded, $size);
            $draft = self::walkedRound($walked, $specs);

            if ($walked['extra'] !== null) {
                $extra = $walked['extra'];
                $slots = [];
                foreach ($extra['entrants'] as $entrant) {
                    $slots[] = [
                        'participant' => $entrant,
                        'spec' => self::loserSpecFor($entrant, $previous, $reseeded),
                        'notPlayed' => false,
                    ];
                }
                $draft['nodes'][] = self::nodeDraft(BracketSide::Main, $walked['round'], count($draft['nodes']) + 1, BracketTie::ThirdPlace, $slots, $extra, $walked['number']);
            }

            self::index($draft, $nodes);
            $drafts[] = $draft;
            $previous = $draft;
        }

        // The rounds still to come, from the nodes of the last round reached
        if (!$reading->isComplete() && $previous !== null) {
            $slotCount = count(self::mainNodes($previous));
            $round = $previous['round'] + 1;

            while ($slotCount > 1) {
                $specs = self::mainSpecs($slotCount, $previous, $reseeded, $size);
                $draft = self::projectedRound(BracketSide::Main, $round, $specs, $nodes, $reading->withdrawn, false);

                if ($slotCount === 2 && $thirdPlace) {
                    $candidates = array_values(array_filter(
                        self::mainNodes($previous),
                        static fn(array $node): bool => self::mayHaveALoser($node, $nodes)
                    ));
                    if (count($candidates) === 2) {
                        $thirdSpecs = [];
                        foreach ($candidates as $candidate) {
                            $thirdSpecs[] = [
                                'source' => SlotSource::LoserOf,
                                'nodes' => [BracketNode::idOf($candidate['side'], $candidate['round'], $candidate['position'])],
                                'reseeded' => $reseeded,
                                'draw' => null,
                            ];
                        }
                        $third = self::projectedRound(BracketSide::Main, $round, $thirdSpecs, $nodes, $reading->withdrawn, true);
                        $node = $third['nodes'][0];
                        $node['position'] = count($draft['nodes']) + 1;
                        $node['kind'] = BracketTie::ThirdPlace;
                        $draft['nodes'][] = $node;
                    }
                }

                self::index($draft, $nodes);
                $drafts[] = $draft;
                $previous = $draft;
                $slotCount = count(self::mainNodes($draft));
                ++$round;
            }
        }

        return self::build($drafts, $reading, [BracketSide::Main->value => count($drafts)], true);
    }

    /**
     * The view of a double elimination bracket.
     *
     * @param int $size The number of slots of the entry fold
     */
    public static function doubleElimination(BracketReading $reading, int $size, bool $reset): Bracket
    {
        $winnersRounds = (int) log($size, 2);
        $losersRounds = 2 * ($winnersRounds - 1);
        $shape = self::doubleShape($size, $reset);

        /** @var list<RoundDraft> $drafts */
        $drafts = [];
        /** @var array<string, NodeDraft> $nodes */
        $nodes = [];

        foreach ($shape as $index => [$side, $round, $specs]) {
            $walked = $reading->walked[$index] ?? null;
            if ($walked !== null && $walked['side'] === $side && $walked['round'] === $round) {
                $draft = self::walkedRound($walked, $specs);
            } elseif ($side === BracketSide::GrandFinal && $round === 2 && $reading->isComplete()) {
                // The reset is not due: the grand final decided the bracket
                $draft = self::notPlayedRound($side, $round, $specs);
            } else {
                $draft = self::projectedRound($side, $round, $specs, $nodes, $reading->withdrawn, false);
            }

            self::index($draft, $nodes);
            $drafts[] = $draft;
        }

        return self::build($drafts, $reading, [
            BracketSide::Winners->value => $winnersRounds,
            BracketSide::Losers->value => $losersRounds,
            BracketSide::GrandFinal->value => $reset ? 2 : 1,
        ]);
    }

    /**
     * The stages of a double elimination bracket in the order the engine
     * plays them, each with where its slots are filled from.
     *
     * @return list<array{BracketSide, int, list<SourceSpec>}>
     */
    private static function doubleShape(int $size, bool $reset): array
    {
        $winnersRounds = (int) log($size, 2);
        $stages = [];

        $draw = [];
        foreach (BracketFold::positions($size) as $position) {
            $draw[] = ['source' => SlotSource::Draw, 'nodes' => [], 'reseeded' => false, 'draw' => $position];
        }
        $stages[] = [BracketSide::Winners, 1, $draw];

        if ($winnersRounds === 1) {
            $grandFinal = [self::spec(SlotSource::AdvancerOf, BracketSide::Winners, 1, 1), self::spec(SlotSource::LoserOf, BracketSide::Winners, 1, 1)];
        } else {
            // Losers round 1: the losers of winners round 1, in order
            $specs = [];
            for ($position = 1; $position <= $size / 2; ++$position) {
                $specs[] = self::spec(SlotSource::LoserOf, BracketSide::Winners, 1, $position);
            }
            $stages[] = [BracketSide::Losers, 1, $specs];
            $losersRound = 1;
            $survivors = intdiv($size, 4);

            for ($winnersRound = 2; $winnersRound <= $winnersRounds; ++$winnersRound) {
                $winnersNodes = intdiv($size, 2 ** $winnersRound);
                $specs = [];
                for ($position = 1; $position <= 2 * $winnersNodes; ++$position) {
                    $specs[] = self::spec(SlotSource::AdvancerOf, BracketSide::Winners, $winnersRound - 1, $position);
                }
                $stages[] = [BracketSide::Winners, $winnersRound, $specs];

                // The survivors meet the droppers of this winners round, in
                // reverse order after an even winners round
                $specs = [];
                for ($index = 0; $index < $survivors; ++$index) {
                    $dropper = $winnersRound % 2 === 0 ? $winnersNodes - $index : $index + 1;
                    $specs[] = self::spec(SlotSource::LoserOf, BracketSide::Winners, $winnersRound, $dropper);
                    $specs[] = self::spec(SlotSource::AdvancerOf, BracketSide::Losers, $losersRound, $index + 1);
                }
                ++$losersRound;
                $stages[] = [BracketSide::Losers, $losersRound, $specs];

                if ($winnersRound < $winnersRounds) {
                    $specs = [];
                    for ($position = 1; $position <= $survivors; ++$position) {
                        $specs[] = self::spec(SlotSource::AdvancerOf, BracketSide::Losers, $losersRound, $position);
                    }
                    ++$losersRound;
                    $stages[] = [BracketSide::Losers, $losersRound, $specs];
                    $survivors = intdiv($survivors, 2);
                }
            }

            $grandFinal = [self::spec(SlotSource::AdvancerOf, BracketSide::Winners, $winnersRounds, 1), self::spec(SlotSource::AdvancerOf, BracketSide::Losers, $losersRound, 1)];
        }

        $stages[] = [BracketSide::GrandFinal, 1, $grandFinal];
        if ($reset) {
            $stages[] = [BracketSide::GrandFinal, 2, [self::spec(SlotSource::LoserOf, BracketSide::GrandFinal, 1, 1), self::spec(SlotSource::AdvancerOf, BracketSide::GrandFinal, 1, 1)]];
        }

        return $stages;
    }

    /**
     * @return SourceSpec
     */
    private static function spec(SlotSource $source, BracketSide $side, int $round, int $position): array
    {
        return ['source' => $source, 'nodes' => [BracketNode::idOf($side, $round, $position)], 'reseeded' => false, 'draw' => null];
    }

    /**
     * Where the slots of a round of a single elimination bracket are filled
     * from: the entry fold for the first round, and otherwise the nodes of
     * the round before, one each on a fixed path and all of them in a
     * re-seeded bracket.
     *
     * @param RoundDraft|null $previous
     *
     * @return list<SourceSpec>
     */
    private static function mainSpecs(int $slotCount, ?array $previous, bool $reseeded, int $size): array
    {
        $specs = [];
        if ($previous === null) {
            $positions = BracketFold::positions($size);
            for ($index = 0; $index < $slotCount; ++$index) {
                $specs[] = ['source' => SlotSource::Draw, 'nodes' => [], 'reseeded' => false, 'draw' => $positions[$index] ?? null];
            }

            return $specs;
        }

        $ids = array_map(
            static fn(array $node): string => BracketNode::idOf($node['side'], $node['round'], $node['position']),
            self::mainNodes($previous)
        );
        for ($index = 0; $index < $slotCount; ++$index) {
            $specs[] = match (true) {
                $reseeded => ['source' => SlotSource::AdvancerOf, 'nodes' => $ids, 'reseeded' => true, 'draw' => null],
                isset($ids[$index]) => ['source' => SlotSource::AdvancerOf, 'nodes' => [$ids[$index]], 'reseeded' => false, 'draw' => null],
                default => ['source' => SlotSource::Recorded, 'nodes' => [], 'reseeded' => false, 'draw' => null],
            };
        }

        return $specs;
    }

    /**
     * Where a slot of a third-place tie the walk reached is filled from:
     * the node of the round before that the entrant did not go on from.
     *
     * @param RoundDraft|null $previous
     *
     * @return SourceSpec
     */
    private static function loserSpecFor(?Participant $entrant, ?array $previous, bool $reseeded): array
    {
        foreach ($previous === null ? [] : self::mainNodes($previous) as $node) {
            foreach ($node['losers'] as $loser) {
                if ($entrant instanceof Participant && $loser->getId() === $entrant->getId()) {
                    return [
                        'source' => SlotSource::LoserOf,
                        'nodes' => [BracketNode::idOf($node['side'], $node['round'], $node['position'])],
                        'reseeded' => $reseeded,
                        'draw' => null,
                    ];
                }
            }
        }

        return ['source' => SlotSource::Recorded, 'nodes' => [], 'reseeded' => false, 'draw' => null];
    }

    /**
     * The nodes of a round that stand in its slots: all but a third-place
     * tie.
     *
     * @param RoundDraft $round
     *
     * @return list<NodeDraft>
     */
    private static function mainNodes(array $round): array
    {
        return array_values(array_filter(
            $round['nodes'],
            static fn(array $node): bool => $node['kind'] !== BracketTie::ThirdPlace
        ));
    }

    /**
     * Whether somebody can still go out of a node: it has a loser, or it
     * is not settled and both of its slots hold or await an entrant.
     *
     * @param NodeDraft $node
     * @param array<string, NodeDraft> $nodes The nodes of the rounds before, by ID
     */
    private static function mayHaveALoser(array $node, array $nodes): bool
    {
        if ($node['status']->isSettled()) {
            return $node['losers'] !== [];
        }

        $contenders = 0;
        foreach ($node['slots'] as $slot) {
            $contenders += $slot['participant'] instanceof Participant || self::isPending($slot['spec'], $nodes) ? 1 : 0;
        }

        return $contenders === 2;
    }

    /**
     * A round the walk went through, as it read it.
     *
     * Its nodes stand in the pairs of its slots, in order, where the round
     * pairs the slots as the bracket does; each slot then takes the source
     * of the slot it stands in. A round drawn by hand that pairs them
     * otherwise has a node for each of its ties and each entrant left
     * alone, and an entrant takes the source of the slot it was entered
     * with, or none.
     *
     * @param WalkedRound $walked
     * @param list<SourceSpec> $specs Where each slot the round is entered with is filled from
     *
     * @return RoundDraft
     */
    private static function walkedRound(array $walked, array $specs): array
    {
        $slots = $walked['slots'];
        $pairs = array_chunk($slots, 2);
        $aligned = count($walked['nodes']) === count($pairs);
        foreach ($walked['nodes'] as $index => $node) {
            $aligned = $aligned && self::sameEntrants($node['entrants'], $pairs[$index] ?? []);
        }

        $slotOf = [];
        foreach ($slots as $index => $slot) {
            if ($slot instanceof Participant) {
                $slotOf[$slot->getId()] = $index;
            }
        }

        $nodes = [];
        foreach ($walked['nodes'] as $index => $node) {
            $nodeSlots = [];
            foreach ($node['entrants'] as $offset => $entrant) {
                $at = $aligned ? 2 * $index + $offset : ($entrant instanceof Participant ? ($slotOf[$entrant->getId()] ?? null) : null);
                $nodeSlots[] = [
                    'participant' => $entrant,
                    'spec' => $at !== null && isset($specs[$at])
                        ? $specs[$at]
                        : ['source' => SlotSource::Recorded, 'nodes' => [], 'reseeded' => false, 'draw' => null],
                    'notPlayed' => false,
                ];
            }
            $nodes[] = self::nodeDraft($walked['side'], $walked['round'], $index + 1, null, $nodeSlots, $node, $walked['number']);
        }

        return [
            'side' => $walked['side'],
            'round' => $walked['round'],
            'slotCount' => count($slots),
            'number' => $walked['number'],
            'manual' => $walked['manual'],
            'next' => $walked['next'],
            'nodes' => $nodes,
        ];
    }

    /**
     * Whether a node holds the entrants of a pair of slots, in order.
     *
     * @param list<Participant|null> $entrants
     * @param array<Participant|null> $pair
     */
    private static function sameEntrants(array $entrants, array $pair): bool
    {
        $pair = array_values($pair);
        if (count($entrants) !== count($pair)) {
            return false;
        }

        foreach ($entrants as $index => $entrant) {
            if ($entrant?->getId() !== $pair[$index]?->getId()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SlotDraft> $slots
     * @param WalkedNode $node
     *
     * @return NodeDraft
     */
    private static function nodeDraft(BracketSide $side, int $round, int $position, ?BracketTie $kind, array $slots, array $node, ?int $number): array
    {
        return [
            'side' => $side,
            'round' => $round,
            'position' => $position,
            'kind' => $kind,
            'slots' => $slots,
            'status' => $node['status'],
            'advancer' => $node['advancer'],
            'losers' => $node['losers'],
            'events' => $node['events'],
            'number' => $number,
        ];
    }

    /**
     * A round the walk did not reach: each slot holds the entrant its
     * source has settled, where it has, and the nodes stand where their
     * slots now leave them.
     *
     * @param list<SourceSpec> $specs Where each slot of the round is filled from
     * @param array<string, NodeDraft> $nodes The nodes of the rounds before, by ID
     * @param array<int|string, true> $withdrawn Who has withdrawn, by ID
     * @param bool $voidDrops Whether the loser of a void node is the first of its two entrants (a
     *                        third-place tie) rather than nobody (a dropper)
     *
     * @return RoundDraft
     */
    private static function projectedRound(BracketSide $side, int $round, array $specs, array $nodes, array $withdrawn, bool $voidDrops): array
    {
        $drafts = [];
        foreach (array_chunk($specs, 2) as $index => $pair) {
            $slots = [];
            foreach ($pair as $spec) {
                $slots[] = ['participant' => self::filledBy($spec, $nodes, !$voidDrops), 'spec' => $spec, 'notPlayed' => false];
            }

            $present = [];
            $pending = false;
            foreach ($slots as $slot) {
                if ($slot['participant'] instanceof Participant) {
                    $present[] = $slot['participant'];
                } elseif (self::isPending($slot['spec'], $nodes)) {
                    $pending = true;
                }
            }

            $advancer = null;
            $losers = [];
            if ($pending) {
                $status = BracketNodeStatus::AwaitingEntrants;
            } elseif (count($present) === 2) {
                $status = BracketNodeStatus::ToBePaired;
            } elseif (count($present) === 1 && !isset($withdrawn[$present[0]->getId()])) {
                $status = BracketNodeStatus::Bye;
                $advancer = $present[0];
            } elseif (count($present) === 1) {
                $status = BracketNodeStatus::Void;
                $losers = $present;
            } else {
                $status = BracketNodeStatus::Empty;
            }

            $drafts[] = [
                'side' => $side,
                'round' => $round,
                'position' => $index + 1,
                'kind' => null,
                'slots' => $slots,
                'status' => $status,
                'advancer' => $advancer,
                'losers' => $losers,
                'events' => [],
                'number' => null,
            ];
        }

        return [
            'side' => $side,
            'round' => $round,
            'slotCount' => count($specs),
            'number' => null,
            'manual' => false,
            'next' => false,
            'nodes' => $drafts,
        ];
    }

    /**
     * The entrant a source has settled for a slot of a round the walk did
     * not reach, or null: the advancer of a settled node, or the entrant
     * who did not go on from it. A re-seeded slot is placed only when its
     * round is reached.
     *
     * @param SourceSpec $spec
     * @param array<string, NodeDraft> $nodes
     * @param bool $voidDropsNobody Whether a void node has no loser to give
     */
    private static function filledBy(array $spec, array $nodes, bool $voidDropsNobody): ?Participant
    {
        if ($spec['reseeded'] || count($spec['nodes']) !== 1) {
            return null;
        }

        $node = $nodes[$spec['nodes'][0]] ?? null;
        if ($node === null || !$node['status']->isSettled()) {
            return null;
        }

        return match ($spec['source']) {
            SlotSource::AdvancerOf => $node['advancer'],
            SlotSource::LoserOf => $voidDropsNobody && $node['advancer'] === null ? null : ($node['losers'][0] ?? null),
            default => null,
        };
    }

    /**
     * Whether a slot without an entrant is still to be filled: one of the
     * nodes it is filled from is not settled.
     *
     * @param SourceSpec $spec
     * @param array<string, NodeDraft> $nodes
     */
    private static function isPending(array $spec, array $nodes): bool
    {
        foreach ($spec['nodes'] as $id) {
            if (!isset($nodes[$id]) || !$nodes[$id]['status']->isSettled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RoundDraft $round
     * @param array<string, NodeDraft> $nodes
     */
    private static function index(array $round, array &$nodes): void
    {
        foreach ($round['nodes'] as $node) {
            $nodes[BracketNode::idOf($node['side'], $node['round'], $node['position'])] = $node;
        }
    }

    /**
     * Turn the drafts into the view.
     *
     * A void node whose entrant stands in a slot it fills went through
     * after all: one of its two entrants was still in the stage when its
     * next tie was paired (see Withdrawal). The node is shown as the
     * uncontested tie it was, with that entrant as its advancer.
     *
     * @param list<RoundDraft> $drafts
     * @param array<string, int> $roundsOnSide By the backing string of the side
     * @param bool $finalKind Whether the one node of a round entered with two slots is the final
     *                        (BracketTie::Final): the main side of single elimination
     */
    private static function build(array $drafts, BracketReading $reading, array $roundsOnSide, bool $finalKind = false): Bracket
    {
        $where = [];
        foreach ($drafts as $roundIndex => $round) {
            foreach ($round['nodes'] as $nodeIndex => $node) {
                $where[BracketNode::idOf($node['side'], $node['round'], $node['position'])] = [$roundIndex, $nodeIndex];
            }
        }

        $revived = self::revivals($drafts);

        $settled = [];
        foreach ($drafts as $round) {
            foreach ($round['nodes'] as $node) {
                $id = BracketNode::idOf($node['side'], $node['round'], $node['position']);
                $settled[$id] = isset($revived[$id]) ? BracketNodeStatus::Uncontested : $node['status'];
            }
        }

        // A node that is empty because every slot of it is a bye passes a
        // bye on, not a void
        $byesOnly = [];

        $rounds = [];
        foreach ($drafts as $round) {
            $nodes = [];
            foreach ($round['nodes'] as $node) {
                $id = BracketNode::idOf($node['side'], $node['round'], $node['position']);
                $advancer = $node['advancer'];
                $losers = $node['losers'];
                $status = $node['status'];
                if (isset($revived[$id])) {
                    $advancer = $revived[$id];
                    $losers = array_values(array_filter(
                        $losers,
                        static fn(Participant $loser): bool => $loser->getId() !== $revived[$id]->getId()
                    ));
                    $status = BracketNodeStatus::Uncontested;
                }
                $kind = $node['kind'] ?? ($finalKind && $round['slotCount'] === 2 && $node['position'] === 1 ? BracketTie::Final : null);

                $slots = [];
                foreach ($node['slots'] as $slot) {
                    $participant = $slot['participant'];
                    $spec = $slot['spec'];
                    $slots[] = new BracketSlot(
                        $participant,
                        $participant instanceof Participant ? null : self::placeholder($slot, $settled, $byesOnly),
                        $spec['source'],
                        $spec['nodes'],
                        $spec['reseeded'],
                        $spec['draw'],
                        $participant instanceof Participant && isset($reading->withdrawn[$participant->getId()])
                    );
                }

                $byesOnly[$id] = $status === BracketNodeStatus::Empty && array_filter(
                    $slots,
                    static fn(BracketSlot $slot): bool => $slot->getPlaceholder() !== SlotPlaceholder::Bye
                ) === [];

                $nodes[] = new BracketNode(
                    $node['side'],
                    $node['round'],
                    $node['position'],
                    $kind,
                    $slots,
                    $status,
                    $advancer,
                    $losers,
                    $node['events'],
                    $node['number']
                );
            }

            $rounds[] = new BracketRound(
                $round['side'],
                $round['round'],
                $roundsOnSide[$round['side']->value] ?? 1,
                $round['slotCount'],
                $nodes,
                $round['number'],
                $round['manual'],
                $round['next']
            );
        }

        return new Bracket($rounds, $reading->isComplete());
    }

    /**
     * The void nodes an entrant went through after all, by ID: an entrant
     * of the node stands in a slot the node fills.
     *
     * @param list<RoundDraft> $drafts
     *
     * @return array<string, Participant>
     */
    private static function revivals(array $drafts): array
    {
        $void = [];
        foreach ($drafts as $round) {
            foreach ($round['nodes'] as $node) {
                if ($node['status'] === BracketNodeStatus::Void && $node['advancer'] === null) {
                    $void[BracketNode::idOf($node['side'], $node['round'], $node['position'])] = $node['losers'];
                }
            }
        }

        $revived = [];
        foreach ($drafts as $round) {
            foreach ($round['nodes'] as $node) {
                foreach ($node['slots'] as $slot) {
                    $participant = $slot['participant'];
                    if (!$participant instanceof Participant || $slot['spec']['source'] !== SlotSource::AdvancerOf) {
                        continue;
                    }
                    foreach ($slot['spec']['nodes'] as $id) {
                        foreach ($void[$id] ?? [] as $loser) {
                            if ($loser->getId() === $participant->getId()) {
                                $revived[$id] = $participant;
                            }
                        }
                    }
                }
            }
        }

        return $revived;
    }

    /**
     * A round the bracket does not play: the reset of a grand final that
     * is not due.
     *
     * @param list<SourceSpec> $specs
     *
     * @return RoundDraft
     */
    private static function notPlayedRound(BracketSide $side, int $round, array $specs): array
    {
        $nodes = [];
        foreach (array_chunk($specs, 2) as $index => $pair) {
            $nodes[] = [
                'side' => $side,
                'round' => $round,
                'position' => $index + 1,
                'kind' => null,
                'slots' => array_map(static fn(array $spec): array => ['participant' => null, 'spec' => $spec, 'notPlayed' => true], $pair),
                'status' => BracketNodeStatus::NotPlayed,
                'advancer' => null,
                'losers' => [],
                'events' => [],
                'number' => null,
            ];
        }

        return ['side' => $side, 'round' => $round, 'slotCount' => count($specs), 'number' => null, 'manual' => false, 'next' => false, 'nodes' => $nodes];
    }

    /**
     * What stands in a slot that holds no entrant.
     *
     * @param SlotDraft $slot
     * @param array<string, BracketNodeStatus> $statuses The status of every node, by ID
     * @param array<string, bool> $byesOnly Of every node built so far, by ID, whether it is
     *                                      empty because its every slot is a bye
     */
    private static function placeholder(array $slot, array $statuses, array $byesOnly): SlotPlaceholder
    {
        if ($slot['notPlayed']) {
            return SlotPlaceholder::NotPlayed;
        }

        $spec = $slot['spec'];
        if ($spec['source'] === SlotSource::Draw || $spec['source'] === SlotSource::Recorded) {
            return SlotPlaceholder::Bye;
        }

        $sources = array_map(static fn(string $id): ?BracketNodeStatus => $statuses[$id] ?? null, $spec['nodes']);
        foreach ($sources as $status) {
            if (!$status instanceof BracketNodeStatus || !$status->isSettled()) {
                return match (true) {
                    $spec['reseeded'] => SlotPlaceholder::Reseeded,
                    $spec['source'] === SlotSource::LoserOf => SlotPlaceholder::LoserOf,
                    default => SlotPlaceholder::AdvancerOf,
                };
            }
        }

        $status = $sources[0] ?? null;

        return match (true) {
            $status === BracketNodeStatus::NotPlayed => SlotPlaceholder::NotPlayed,
            $spec['source'] === SlotSource::LoserOf && $status === BracketNodeStatus::Bye => SlotPlaceholder::Bye,
            !$spec['reseeded'] && ($byesOnly[$spec['nodes'][0] ?? ''] ?? false) => SlotPlaceholder::Bye,
            default => SlotPlaceholder::Void,
        };
    }
}
