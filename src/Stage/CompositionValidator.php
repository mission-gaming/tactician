<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Validates that a declared multi-stage composition telescopes correctly
 * before any fixture exists.
 *
 * Structural bracket guarantees (16 entrants → 8 → 4 → 2 → 1) become
 * checkable properties of a composition rather than side effects of a
 * monolith: each transition's selector yield is checked against the next
 * stage's declared entrant count, using selector-declared cardinalities
 * where fixed and knockout arithmetic for match-outcome selections.
 *
 * This validates one route at a time; concurrent routes (a winners' route
 * and the losers' route consuming what it rejects) validate as separate
 * chains from the same source. Runtime stage entry still validates count
 * and uniqueness — this is the ahead-of-time check.
 */
final readonly class CompositionValidator
{
    /**
     * Validate a linear chain of stage transitions.
     *
     * @param int $initialEntrants Entrants of the first stage
     * @param array<StageTransition> $transitions Each subsequent stage, in order
     * @return array<string> Violations; empty means the chain telescopes correctly
     */
    public function validateChain(int $initialEntrants, array $transitions): array
    {
        $violations = [];

        if ($initialEntrants < 2) {
            $violations[] = "The opening stage needs at least 2 entrants, {$initialEntrants} declared.";
        }

        $entrants = $initialEntrants;
        foreach ($transitions as $transition) {
            if ($transition->expectedEntrants < 2) {
                $violations[] = "Stage '{$transition->label}' needs at least 2 entrants, {$transition->expectedEntrants} declared.";
            }

            $yield = $this->selectorYield($transition->selector, $entrants);
            if ($yield !== null && $yield !== $transition->expectedEntrants) {
                $violations[] = sprintf(
                    "Stage '%s' expects %d entrants but its selection yields %d from the previous stage's %d.",
                    $transition->label,
                    $transition->expectedEntrants,
                    $yield,
                    $entrants
                );
            }

            $entrants = $transition->expectedEntrants;
        }

        return $violations;
    }

    /**
     * How many participants the selector will yield from a source stage of
     * the given size, when derivable ahead of time.
     *
     * Fixed-cardinality selectors declare their own size. Match-outcome
     * selections over a knockout round derive from the source size: a
     * round of n entrants plays floor(n/2) events (byes advance the rest),
     * so winners + byes yield ceil(n/2) and losers yield floor(n/2).
     */
    private function selectorYield(?ProgressionSelector $selector, int $sourceEntrants): ?int
    {
        if ($selector === null) {
            return null;
        }

        $declared = $selector->getSelectionSize();
        if ($declared !== null) {
            return $declared;
        }

        if ($selector instanceof MatchOutcomeSelector) {
            return $selector->toArray()['mode'] === 'winners'
                ? intdiv($sourceEntrants + 1, 2)
                : intdiv($sourceEntrants, 2);
        }

        return null;
    }
}
