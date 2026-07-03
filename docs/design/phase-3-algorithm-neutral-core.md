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

## Scope alignment: the stage is Tactician's unit

Consuming platforms model competition hierarchies above the schedule.
Metronome's is representative:

```
CompetitionEvent            "UEFA Champions League"        (the competition as a concept)
└── CompetitionEventInstance  "Champions League 26/27"     (an edition/season)
    └── CompetitionStage       "Group Stage", "Last 16"    (format + rules + participants)
        └── GameplayEvent       the matches                (fixtures)
```

Tactician's scope is **exactly one stage**: participants and typed options
in, a schedule (or round-by-round pairings) out. Everything above the stage
— competitions, seasons, which stages exist, their ordering — is application
domain, permanently. Each abstraction in this document is therefore
per-stage: one `TournamentPlan`, one engine, one options object, one
(future) timeline per stage. Two properties of real platforms follow:

- **Stages compose, including concurrently.** One source stage can feed
  several destination stages with different rules — e.g. a group's top two
  progress to a winners' route while the bottom two progress to a losers'
  route, each route itself a stage with its own format, rules, and dates.
  Tactician supports this by making the *hand-off* between stages
  first-class (see qualification selectors below), not by modelling the
  stage graph itself.
- **Per-stage rules vary.** Points systems, draw permissibility, formats,
  and generation strategies differ stage by stage. This validates typed
  per-stage options and per-stage `PointsSystem` configuration, and rules
  out any library-level "tournament settings" singleton.

### Critical assessment of the reference model

The identity/edition/stage/fixture separation is sound and matches how
competition data is modelled industry-wide; the stage as format unit is the
right boundary for this library. Two aspects deserve pushback rather than
adoption:

1. **Knockout-as-chained-stages should be a choice, not a necessity.**
   Platforms without a bracket engine model each knockout round as its own
   stage ("last 16" → "last 8" → ...) with a progression rule between each.
   That composes, but it discards bracket-level structural guarantees —
   nothing enforces that 16 entrants yield 15 ties, that a participant
   cannot appear twice, or that progression selects match winners rather
   than whatever a misconfigured rule computes. With a bracket engine, the
   whole knockout is one stage with those invariants enforced, and
   per-round rule variation (extra time from the quarter-finals, say) is
   match-play and tie-resolution policy — which is application-side anyway
   — not a reason to fragment the bracket. Tactician should support both
   granularities but recommend the engine-per-bracket shape.
2. **Progression has two distinct kinds, and conflating them is a trap.**
   Standings-based progression (top 2 of a group) and winner-based
   progression (advancing in a bracket) are different operations. Selectors
   (section 5) model only the first; the second belongs inside the
   elimination engines, which already know the winners. Expressing bracket
   progression as a standings slice technically works for single-round
   stages — winners have more points — but it is fragile (draws, points
   configuration) and re-derives what the engine already guarantees.

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

### 5. Qualification selectors — the hand-off between stages

`GroupStageEngine::getQualifiers()` currently supports exactly one rule:
top K per group. Real stage graphs need arbitrary slices of a finished
stage's standings — including several slices of the *same* stage feeding
different destinations (winners' route / losers' route):

```php
// PROPOSED — not implemented
interface QualificationSelector
{
    /**
     * Slice a finished stage's standings into an ordered, reseeded
     * participant list for a destination stage.
     *
     * @param array<string, Standings> $standings Per-group standings ('A', 'B', ...)
     * @return array<Participant> Reseeded via Participant::withSeed()
     */
    public function select(array $standings): array;
}

// Built-ins covering the common shapes:
RankRangeSelector::topPerGroup(2);          // today's getQualifiers(2)
RankRangeSelector::perGroup(from: 3, to: 4); // the losers' route
RankRangeSelector::overall(from: 1, to: 8);  // best N across all groups
```

The selector's output is exactly what the elimination engines (or another
group stage, or a Swiss stage) take as input, so multi-route progression is
two selector calls against one standings set. The group-play completeness
check moves into the selector path. Selection *policy configuration* —
which selectors run for which destination stage — remains application
domain; Tactician supplies the selectors and the reseeding.

Selectors are deliberately **standings-based only**. Winner-based
progression (who advances within a bracket) is not a selector concern — the
elimination engines own it, with structural guarantees a standings slice
cannot provide (see the critical assessment above).

### 6. Two-legged elimination ties (design consideration)

Knockout stages in football-style competitions are often played over two
legs per tie (home and away), with the aggregate deciding who advances —
including rules Tactician must never own (away goals, extra time,
penalties). Proposed split, consistent with the rest of this document:

- `EliminationOptions(legsPerTie: 2)` makes the engine emit **two events
  per pairing** (roles mirrored) within a bracket stage.
- The engine advances on a **tie result**: the application resolves the
  aggregate under its own rules and records which participant won the tie.
  Per-leg `Result`s remain ordinary results feeding standings/statistics;
  the tie result is what the bracket consumes.

This keeps aggregate policy out of the library while making the fixture
structure (two legs, mirrored venues, both events in the plan's expected
counts) the library's responsibility. ❓ Scope question: first cut of
Phase 3, or a fast-follow once the single-leg engine interface has landed?

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
   The stage-alignment section above suggests the latter: a stage composer
   whose output flows through qualification selectors.
3. **`SimpleSwissScheduler`**: worth keeping once the Swiss engine drives
   the same loop with a random-results-independent preset?
4. **`TournamentState` persistence**: is `toArray()`/JSON round-tripping a
   requirement for the first cut (Metronome-style platforms would use it) or
   a fast-follow?
5. **Trophy accessor**: lift `getOutcome()` into the engine interface now,
   or keep champions/qualifiers format-specific?
6. **Two-legged ties**: in the first Phase 3 cut, or a fast-follow after the
   single-leg engine interface lands (section 6)?
