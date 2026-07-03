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
 */
final readonly class StageTransition
{
    /**
     * @param string $label Destination stage name, used in violation messages
     * @param int $expectedEntrants The entrant count the destination stage declares
     * @param ProgressionSelector|null $selector How entrants derive from the previous stage;
     *                                           null for consumer-derived selections
     */
    public function __construct(
        public string $label,
        public int $expectedEntrants,
        public ?ProgressionSelector $selector = null
    ) {
    }
}
