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
 *
 * @experimental
 */
final readonly class CompositionValidator
{
    /**
     * Validate a linear chain of stage transitions.
     *
     * Two things are checked: that every stage declares at least 2
     * entrants, and that a transition's selector yields as many
     * participants as its destination declares. It never throws; what is
     * wrong is in the returned list.
     *
     * How many a selector yields is known ahead of time in two cases
     * only. A selector that states its size (an overall rank range, or the
     * best few of one rank across pools, whose size is its count) is taken
     * at its word. A match-outcome selector is counted from the
     * shape of the stage before it, which the transition declares because
     * the validator runs nothing and cannot see it. By default that stage
     * is one knockout round of all its entrants: half of them go on,
     * rounded up for winners (the bye goes on) and down for losers. A
     * transition built with `afterWholeBracket: true` says that the stage
     * plays a whole bracket, whose last round is the final: the selection
     * is 1 participant, winner or loser, whatever the size of the field,
     * and also where that round holds a third-place tie, which the
     * selector does not read. A stage needs 2 entrants, so such a transition is always reported:
     * one match-outcome selection cannot fill the stage after a whole
     * bracket. A transition with any other selector (a per-group rank
     * range, a selector of the application's) or with none passes
     * unchecked, apart from its declared entrant count.
     *
     * @param int $initialEntrants Entrants of the first stage
     * @param array<StageTransition> $transitions Each subsequent stage, in order
     * @return array<string> Violations in chain order, as sentences for a person to read;
     *                       empty means nothing checkable is wrong
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

            $yield = $this->selectorYield($transition->selector, $entrants, $transition->afterWholeBracket);
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
     * so winners + byes yield ceil(n/2) and losers yield floor(n/2). Over a
     * whole bracket the round the selector reads is the final, which has one
     * winner and one loser whatever the size of the field.
     */
    private function selectorYield(?ProgressionSelector $selector, int $sourceEntrants, bool $afterWholeBracket): ?int
    {
        if ($selector === null) {
            return null;
        }

        $declared = $selector->getSelectionSize();
        if ($declared !== null) {
            return $declared;
        }

        if ($selector instanceof MatchOutcomeSelector) {
            if ($afterWholeBracket) {
                return 1;
            }

            return $selector->toArray()['mode'] === 'winners'
                ? intdiv($sourceEntrants + 1, 2)
                : intdiv($sourceEntrants, 2);
        }

        return null;
    }
}
