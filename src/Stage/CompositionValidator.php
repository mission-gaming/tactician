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
 * stage's declared entrant count, using the yield each selector declares
 * (ProgressionSelector::getYield()).
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
     * Four things are checked: that every stage declares at least 2
     * entrants, that a transition that declares the pools of the stage
     * before declares at least 1, that it declares no fewer than its
     * selector can select from (SelectionYield::getMinimumPools()), and
     * that a transition's selector yields as many participants as its
     * destination declares. It never throws; what is wrong is in the
     * returned list.
     *
     * How many a selector yields is what it declares
     * (ProgressionSelector::getYield()), counted with
     * SelectionYield::countFrom() from the shape of the stage before, which
     * the transition declares because the validator runs nothing and cannot
     * see it:
     *
     * - a fixed count (an overall rank range, the best few of one rank
     *   across pools) is taken at its word;
     * - a count per pool (a per-group rank range) is multiplied by the
     *   pools the transition declares (`sourcePools`), and passes unchecked
     *   where it declares none;
     * - a count per pool plus a count across the pools (the top of every
     *   pool and the best few of the next rank, BracketQualifiersSelector)
     *   is counted the same way, with the count across the pools added.
     *   Fewer pools than the selector can select from are reported, and
     *   the count is then not checked: the top two of one pool and the
     *   best of its third-placed are three entrants of that one pool, two
     *   of whom meet in the first round of any bracket;
     * - the winners or the losers of the last round (a match-outcome
     *   selector) are counted from the entrants of the stage before. By
     *   default that stage is one knockout round of all its entrants: half
     *   of them go on, rounded up for winners (the bye goes on) and down for
     *   losers. A transition built with `afterWholeBracket: true` says that
     *   the stage plays a whole bracket, whose last round is the final: the
     *   selection is 1 participant, winner or loser, whatever the size of
     *   the field, and also where that round holds a third-place tie, which
     *   the selector does not read. A stage needs 2 entrants, so such a
     *   transition is always reported: one match-outcome selection cannot
     *   fill the stage after a whole bracket;
     * - a yield that depends on the outcome passes unchecked, and so does a
     *   transition with no selector, apart from its declared entrant count.
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

            $pools = $transition->sourcePools;
            if ($pools !== null && $pools < 1) {
                $violations[] = "Stage '{$transition->label}' declares {$pools} pools for the previous stage; a stage is played in at least 1.";
                $pools = null;
            }

            $minimumPools = $transition->selector?->getYield()->getMinimumPools() ?? 1;
            if ($pools !== null && $pools < $minimumPools) {
                $violations[] = sprintf(
                    "Stage '%s' declares %d %s for the previous stage; its selection is made from at least %d.",
                    $transition->label,
                    $pools,
                    $pools === 1 ? 'pool' : 'pools',
                    $minimumPools
                );
            }

            $yield = $transition->selector?->getYield()->countFrom($entrants, $pools, $transition->afterWholeBracket);
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
}
