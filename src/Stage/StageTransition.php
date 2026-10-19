<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * One hand-off in a declared multi-stage composition: how entrants derive
 * from the previous stage, and how many the destination expects.
 *
 * Consumer-derived selections participate by declaring the expected
 * entrant count without a selector — validation participation is opt-in,
 * not mandatory.
 *
 * @experimental
 */
final readonly class StageTransition
{
    /**
     * Nothing is checked here: CompositionValidator::validateChain()
     * reports an entrant count below 2 and a selector whose yield does
     * not match.
     *
     * @param string $label Destination stage name, used in violation messages
     * @param int $expectedEntrants The entrant count the destination stage declares
     * @param ProgressionSelector|null $selector How entrants derive from the previous stage;
     *                                           null for consumer-derived selections
     * @param bool $afterWholeBracket Whether the previous stage plays a whole elimination
     *                                bracket, down to its final, and not one knockout round.
     *                                The validator runs nothing and cannot see it. It matters
     *                                for a MatchOutcomeSelector only, which reads the last
     *                                round of the stage: after a whole bracket that is the
     *                                final, and the selection is one participant
     * @param int|null $sourcePools The number of pools the previous stage is played in; null
     *                              when not declared. It matters for a selector whose yield is
     *                              a count per pool (a per-group rank range): with it the
     *                              validator checks that count times the pools, and without it
     *                              such a transition passes unchecked
     */
    public function __construct(
        public string $label,
        public int $expectedEntrants,
        public ?ProgressionSelector $selector = null,
        public bool $afterWholeBracket = false,
        public ?int $sourcePools = null
    ) {}
}
