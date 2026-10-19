<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The metadata keys the experimental formats write, as constants: the
 * Swiss preset and the pot draw on a schedule, the elimination engines on
 * a round and an event.
 *
 * DTO\MetadataKey holds the keys whose meaning the stable surface owns,
 * the keys the round-robin scheduler writes. The keys here are as
 * experimental as the formats that write them: their values are the
 * strings these formats have always written, and they change with the
 * formats, in a minor release before 1.0.0, with a changelog entry.
 *
 * Which object carries a key, and when, is said on each constant. A key that
 * is not there is read as missing (`hasMetadata()` is false), never as a
 * default the library meant.
 *
 * @experimental
 */
final class FormatMetadataKey
{
    /**
     * Schedule: the number of rounds asked for. Written by the whole-schedule
     * Swiss preset (Scheduling\SwissScheduler), beside
     * DTO\MetadataKey::TOTAL_ROUNDS, which holds the same number.
     */
    public const string ROUNDS = 'rounds';

    /**
     * Schedule: the number of pots. Written by the pot draw
     * (Scheduling\PotDrawScheduler).
     */
    public const string POTS = 'pots';

    /**
     * Schedule: the number of participants in each pot. Written by the pot
     * draw.
     */
    public const string POT_SIZE = 'pot_size';

    /**
     * Schedule: how many opponents each participant meets from each pot.
     * Written by the pot draw.
     */
    public const string OPPONENTS_PER_POT = 'opponents_per_pot';

    /**
     * Schedule: the seed of the draw. Written by the pot draw.
     */
    public const string SEED = 'seed';

    /**
     * Round: the label of a bracket round, as text ('semifinal',
     * 'losers round 2': RoundLabel::toString()). Written by the elimination
     * engines; read as typed data by RoundLabel::ofRound().
     */
    public const string ROUND_LABEL = 'label';

    /**
     * Event: which leg of a two-legged knockout tie the event is, 1 or 2.
     * Written by the elimination engines on both events of such a tie, and
     * on no other event.
     */
    public const string TIE_LEG = 'tie_leg';

    /**
     * Event: which tie of the last round of a single elimination bracket
     * with a third-place tie the event belongs to. Written by the single
     * elimination engine; read through BracketTie::of()
     * (BracketTie::METADATA_KEY is the same key).
     */
    public const string BRACKET_TIE = 'bracket_tie';

    private function __construct() {}
}
