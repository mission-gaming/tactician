# Design: Phase 3 — Algorithm-Neutral Core

**Status: PROPOSAL** — nothing in this document is implemented. Code blocks
are design sketches, not executable examples. Decisions marked ❓ need
maintainer input before implementation starts.

## Why

Tactician grew from a round-robin library into a multi-format one, and the
round-robin assumptions are still baked into the generic services. Five
concrete debts, each with a bug or wart already attributable to it:

1. **Generic services infer tournament shape instead of being told it.**
   `SchedulingContext::getRoundsPerLeg()` falls back to pairwise round-robin
   math; `SchedulingDiagnostics` hardcodes `n(n-1)/2`; expected event counts
   default to round-robin formulas. Correct for round robin, silently wrong
   for everything else — the Swiss + `SeedProtectionConstraint` window
   collapse was exactly this class of bug.
2. **The scheduler interface overloads one scalar.** `schedule(..., int $legs,
   mixed $options)` means legs for round robin and rounds for Swiss (now
   documented, still a wart), and `mixed $options` is a typed-language
   escape hatch.
3. **Planning is ornamental.** `LegStrategyInterface::planGeneration()`
   returns a `GenerationPlan` nobody consumes (only the
   `canSatisfyConstraints()` preflight is wired). The plan and the generator
   are parallel implementations of the same math — which is how the
   odd-participant rounds-per-leg bug shipped in the plan while the
   generator was correct.
4. **The results-driven engines share a rhythm but not an interface.**
   `SwissPairingEngine::pairNextRound(participants, results, byeIds, round)`,
   the elimination engines' `pairNextRound(participants, results)`, and
   `GroupStageEngine`'s four-method lifecycle each require bespoke driver
   code. A consuming platform (e.g. Metronome) must write one integration
   per format instead of one integration total. The Swiss engine also makes
   the caller thread bye IDs through every call by hand.
5. **Duplicated shape vocabulary.** `ExpectedEventCalculator`,
   `ScheduleValidationContext`, `GenerationPlan`,
   `ConstraintSatisfiabilityReport`, and loose metadata keys
   (`rounds_per_leg`, `total_rounds`, `expected_event_count`) all describe
   fragments of "what should this tournament look like", with hand-copied
   conversions between them.

## Goals

- Algorithms declare their shape once; context, validation, diagnostics, and
  constraints consume that declaration instead of inferring.
- One typed options object per algorithm; no overloaded scalars, no `mixed`.
- One driver loop for every results-driven format.
- The plan is load-bearing: generation reads from it, so plan/generator
  drift becomes impossible.

## Non-goals

- Timeline/date assignment (see `docs/design/timeline-assignment.md`).
- Backtracking generation (ROADMAP Phase 5).
- Soft/preference constraints.
- Backwards compatibility: the library is unreleased; this is a breaking
  redesign done once, before first release.

## Proposed design

### 1. `TournamentPlan` — the algorithm's declaration of shape

A per-algorithm implementation of one interface, constructed by the
scheduler/engine before generation and carried everywhere the shape is
needed:

```php
// PROPOSED — not implemented
interface TournamentPlan
{
    public function getAlgorithm(): string;

    /** Total rounds the tournament will contain, when knowable up front. */
    public function getTotalRounds(): ?int;

    /** Legs, for formats that have them; null otherwise (Swiss, brackets). */
    public function getLegs(): ?int;
    public function getRoundsPerLeg(): ?int;

    /** Expected total events, when knowable up front. */
    public function getExpectedEventCount(): ?int;

    /** How many times a given pairing should meet (integrity checking). */
    public function getExpectedMeetings(Participant $a, Participant $b): ?int;

    /** Format-specific integrity validation of a complete schedule. */
    public function validateIntegrity(Schedule $schedule): array;
}
```

Implementations: `RoundRobinPlan` (knows everything), `SwissPlan` (knows
rounds and per-round event counts, pairings unknowable), `EliminationPlan`
(knows match totals and stage structure), `GroupStagePlan` (composes
per-group `RoundRobinPlan`s).

**What it replaces:** `ExpectedEventCalculator`,
`ScheduleIntegrityValidator`, `ScheduleValidationContext`, the shape-related
`Schedule`/`SchedulingContext` metadata keys, and the shape half of
`GenerationPlan`. `SchedulingContext` gains `getPlan(): TournamentPlan` and
drops its inference fallbacks; `SeedProtectionConstraint` reads
`getPlan()->getTotalRounds()`; diagnostics read expected meetings from the
plan instead of recomputing round-robin formulas.

### 2. Typed per-algorithm options

```php
// PROPOSED — not implemented
$schedule = $scheduler->schedule($participants, new RoundRobinOptions(
    legs: 2,
    strategy: new MirroredLegStrategy(),
));

$schedule = $swissScheduler->schedule($participants, new SwissOptions(rounds: 5));
```

`SchedulerInterface::schedule(array $participants, AlgorithmOptions
$options): Schedule`. Each algorithm's option class names its parameters in
its own vocabulary — the legs/rounds overload dissolves instead of being
documented around. `participantsPerEvent` moves into the options of
algorithms that support varying it. `validateConstraints()` and
`getExpectedEventCount()` fold into the plan (`getPlan(participants,
options): TournamentPlan` on the scheduler).

### 3. Plan-driven generation

`RoundRobinScheduler` builds its `RoundRobinPlan` first (consulting the leg
strategy), then generates *from* it: rounds-per-leg, role-parity scheme,
and expected pairing multiplicities are read from the plan by the
generator, the validator, and the diagnostics alike. `GenerationPlan` and
`ConstraintSatisfiabilityReport` fold into this: the satisfiability
preflight becomes plan construction (an unsatisfiable configuration fails
while building the plan, with the same diagnostics).

### 4. One engine interface for results-driven formats

```php
// PROPOSED — not implemented
interface TournamentEngineInterface
{
    public function getPlan(TournamentState $state): TournamentPlan;

    /** @throws NoValidPairingException|InvalidConfigurationException */
    public function pairNextRound(TournamentState $state): RoundPairing;

    public function isComplete(TournamentState $state): bool;
}

final readonly class TournamentState
{
    /** @param array<Participant> $participants Active participants */
    public static function start(array $participants): self;

    /** Record a completed round: its results and any byes it awarded. */
    public function withRoundPlayed(RoundPairing $pairing, array $results): self;

    /** Handle withdrawals: participant leaves, their results remain. */
    public function withoutParticipant(Participant $participant): self;
}

final readonly class RoundPairing   // unifies SwissRoundPairing + EliminationRoundPairing
{
    public function getRoundNumber(): int;
    public function getStage(): ?string;          // 'semifinal', 'losers round 2', null for Swiss
    /** @return array<Event> */
    public function getEvents(): array;
    /** @return array<Participant> */
    public function getByes(): array;             // Swiss: 0 or 1; brackets: any number
}
```

The driver loop becomes format-agnostic — this is the single integration a
consuming platform writes:

```php
// PROPOSED — not implemented
$state = TournamentState::start($participants);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    $results = playRound($pairing);              // application-side
    $state = $state->withRoundPlayed($pairing, $results);
}
```

`TournamentState` absorbs the state the Swiss engine currently makes callers
thread by hand (`previousByeIds`, round numbers) and gives withdrawals a
first-class verb. It serializes like the DTOs (`toArray()`/`fromArray()`),
so a platform can persist state between rounds instead of re-deriving it.

Format-specific outcomes stay on the concrete engines (`getChampion()`,
`getQualifiers()`) — the shared interface covers the loop, not the
trophies. ❓ Alternatively, an `getOutcome(): ?TournamentOutcome` could be
lifted into the interface; deferred until a second consumer needs it.

### What happens to existing classes

| Current | Fate |
|---------|------|
| `ExpectedEventCalculator`, `RoundRobinEventCalculator`, `SimpleSwissEventCalculator` | Folded into `TournamentPlan` implementations |
| `ScheduleValidationContext` | Removed; validators take the plan |
| `ScheduleIntegrityValidator` | Becomes `TournamentPlan::validateIntegrity()` |
| `GenerationPlan`, `ConstraintSatisfiabilityReport` | Folded into plan construction |
| `LegStrategyInterface::planGeneration()` / `canSatisfyConstraints()` | Replaced by a single `contributeToPlan(RoundRobinPlanBuilder)` hook ❓ (naming open) |
| `SwissRoundPairing`, `EliminationRoundPairing` | Replaced by `RoundPairing` |
| `SwissPairingEngine::pairNextRound(4 args)` | `pairNextRound(TournamentState)` |
| `SimpleSwissScheduler` | ❓ Keep as a whole-schedule generator, or reimplement as a preset over the engine loop |
| Shape metadata keys on `Schedule` | Kept for serialization/display, but written *from* the plan |

## Sequencing

Four milestones, each shippable green:

1. **Plan introduction** — `TournamentPlan` + implementations; context,
   validation, diagnostics, and constraints consume it; calculators and
   validation-context classes removed. Biggest milestone, mostly internal.
2. **Options objects** — `SchedulerInterface` rework; the legs/rounds
   overload dies.
3. **Engine unification** — `TournamentState`, `RoundPairing`,
   `TournamentEngineInterface`; Swiss/elimination engines conform;
   `GroupStageEngine` keeps its lifecycle but its knockout hand-off emits a
   `TournamentState` ❓.
4. **Sweep** — docs, examples, memory bank, and the deprecated-class
   removals.

## Open questions for the maintainer

1. **Naming**: `TournamentPlan` (used here) vs `AlgorithmPlan` vs
   `ExpectedSchedule` (both appear in older notes). One name, everywhere.
2. **`GroupStageEngine`**: conform to `TournamentEngineInterface` (it is
   round-driven per group) or stay a composer that *produces* engine inputs?
3. **`SimpleSwissScheduler`**: worth keeping once the Swiss engine drives
   the same loop with a random-results-independent preset?
4. **`TournamentState` persistence**: is `toArray()`/JSON round-tripping a
   requirement for the first cut (Metronome-style platforms would use it) or
   a fast-follow?
5. **Trophy accessor**: lift `getOutcome()` into the engine interface now,
   or keep champions/qualifiers format-specific?
