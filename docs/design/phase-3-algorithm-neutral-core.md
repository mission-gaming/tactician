# Design: Phase 3 — Algorithm-Neutral Core

**Status: PROPOSAL (revision 2)** — nothing in this document is implemented.
Code blocks are design sketches, not executable examples. Decisions marked
✅ were resolved with the maintainer; items marked ❓ remain open.

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
   code. A consuming platform must write one integration per format instead
   of one integration total.
5. **Duplicated shape vocabulary.** `ExpectedEventCalculator`,
   `ScheduleValidationContext`, `GenerationPlan`,
   `ConstraintSatisfiabilityReport`, and loose metadata keys all describe
   fragments of "what should this stage look like", with hand-copied
   conversions between them.

## Goals

- Algorithms declare their shape once; context, validation, diagnostics, and
  constraints consume that declaration instead of inferring.
- One typed options object per algorithm; no overloaded scalars, no `mixed`.
- One driver loop and one completion product for every results-driven format.
- The plan is load-bearing: generation reads from it, so plan/generator
  drift becomes impossible.

## Non-goals

- Timeline/date assignment (see `docs/design/timeline-assignment.md`).
- Backtracking generation (ROADMAP Phase 5).
- Soft/preference constraints.
- N-participant event *algorithms* (racing heats, lobbies) — but see the
  design principles: Phase 3 abstractions must not foreclose them.
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
in, a schedule (or round-by-round pairings) out, and — new in this revision —
a uniform **stage outcome** when play completes. Everything above the stage
is application domain, permanently. Each abstraction here is per-stage: one
plan, one engine, one options object, one (future) timeline.

The naming in this document follows that scoping: **`StagePlan`**,
**`StageState`**, **`StageOutcome`**, **`StageEngineInterface`** (see the
naming decision below).

Two properties of real platforms shape the design:

- **Stages compose, including concurrently and multi-route.** One finished
  stage can feed several destinations under different rules — group winners
  to a winners' route, the rest to a losers' route; a knockout round's
  winners forward and its losers into a repechage. The *stage lifecycle is
  uniform*: generate → play → complete → derive who progresses. Tactician
  makes the derivation first-class (progression selectors over a uniform
  `StageOutcome`) and leaves the stage graph itself to the application.
- **Per-stage rules vary.** Points systems, draw permissibility, formats,
  and generation strategies differ stage by stage. This validates typed
  per-stage options and per-stage `PointsSystem` configuration, and rules
  out any library-level "tournament settings" singleton.

### Fixed brackets vs chained knockout stages

Platforms without a bracket engine model each knockout round as its own
stage with a progression rule between rounds. This is not merely a
workaround — it is the natural encoding of a **re-seeded knockout** (NFL
playoffs style, where survivors are re-paired each round) and of arbitrary
progression graphs (losers' routes, repechage). A **fixed bracket** (World
Cup style, where the path is predetermined and 16 entrants structurally
imply 15 ties) is a different format with invariants worth enforcing as a
unit.

Both are legitimate; Tactician should serve both:

- **Fixed bracket** → one stage running `SingleEliminationEngine` /
  `DoubleEliminationEngine`, with bracket-level guarantees (entrant/match
  counts, loss counts, no re-entry) enforced by the engine.
- **Re-seeded knockout or custom graph** → chained single-round elimination
  stages composed by the application, with progression via outcome
  selectors. The graph's correctness is the application's responsibility;
  selectors help by validating cardinality (a selector declares how many
  participants it yields, and a destination stage validates its entrant
  count).

The one practice this design treats as an error in any model: **deriving
match winners through points arithmetic**. Progression must read recorded
outcomes (winners/losers) or standings ranks — both provided directly —
never reconstruct one from the other.

## Design principles (new in this revision)

**Game-agnosticism.** Tactician serves football, American football, racing,
combat sports, and shooting games alike. Concretely:

- **Roles are positional; "home/away" is a label.** The core concept is the
  participant's position within an event (position 0, position 1). Football
  reads them as home/away, combat sports as red/blue corner, shooters as
  sides. Factory names like `homeAway()` remain as conveniences, but no
  core behavior may depend on the home/away *interpretation*.
- **Nothing forecloses N-participant events.** Racing heats and lobbies
  have many participants per event. Phase 3 does not implement N-participant
  algorithms, but its abstractions must not be pairwise-shaped:
  `StagePlan` expresses expected *events*, with pairwise meeting counts as
  a capability round-robin-family plans expose rather than a universal
  method. `Result` already supports N participants; ordered placements
  (finishing order) are a known future extension it must not preclude.
- **Preset naming stays neutral.** `PointsSystem::football()` /`chess()`
  should gain (or be replaced by) neutral constructors —
  `PointsSystem::threeOneZero()` or similar — with the sport names kept, if
  at all, as documented aliases. ❓ exact naming.

**Config-constructibility.** Consuming platforms (Metronome explicitly) are
config-driven, event-driven, strategy-derived systems: behavior is selected
by strategy IDs plus JSON config, not code. Therefore every Phase 3 option
object and selector must be constructible from plain data — named
constructors and `fromArray()`, no required closures. (`CallableConstraint`
remains available for code-level consumers but is explicitly the
non-config-friendly escape hatch.) This also means stable string
identifiers for algorithms and selectors, so platforms can map config to
library objects predictably.

## Proposed design

### 1. `StagePlan` — the algorithm's declaration of shape

Constructed by the scheduler/engine before generation and carried everywhere
the shape is needed:

```php
// PROPOSED — not implemented
interface StagePlan
{
    public function getAlgorithm(): string;          // stable identifier, e.g. 'round-robin'

    /** Total rounds the stage will contain, when knowable up front. */
    public function getTotalRounds(): ?int;

    /** Legs, for formats that have them; null otherwise (Swiss, brackets). */
    public function getLegs(): ?int;
    public function getRoundsPerLeg(): ?int;

    /** Expected total events, when knowable up front. */
    public function getExpectedEventCount(): ?int;

    /** Format-specific integrity validation of a complete schedule. */
    public function validateIntegrity(Schedule $schedule): array;
}

// Round-robin-family plans additionally expose pairwise expectations:
interface PairwisePlan extends StagePlan
{
    public function getExpectedMeetings(Participant $a, Participant $b): int;
}
```

Implementations: `RoundRobinPlan` (pairwise, knows everything), `SwissPlan`
(knows rounds and per-round event counts), `EliminationPlan` (knows match
totals, stage structure, and — with two-legged ties — events per tie),
`GroupStagePlan` (composes per-pool plans).

**What it replaces:** `ExpectedEventCalculator`,
`ScheduleIntegrityValidator`, `ScheduleValidationContext`, the shape-related
metadata keys, and the shape half of `GenerationPlan`. `SchedulingContext`
gains `getPlan(): StagePlan` and drops its inference fallbacks;
`SeedProtectionConstraint` reads `getPlan()->getTotalRounds()`; diagnostics
read expected meetings from `PairwisePlan` instead of recomputing formulas.

### 2. Typed per-algorithm options

```php
// PROPOSED — not implemented
$schedule = $scheduler->schedule($participants, new RoundRobinOptions(
    legs: 2,
    strategy: new MirroredLegStrategy(),
));

$schedule = $swissScheduler->schedule($participants, new SwissOptions(rounds: 5));
```

Every options object is config-constructible (`RoundRobinOptions::fromArray(
['legs' => 2, 'strategy' => 'mirrored'])`) per the design principles.
`validateConstraints()` and `getExpectedEventCount()` fold into the plan
(`getPlan(participants, options): StagePlan`).

### 3. Plan-driven generation

`RoundRobinScheduler` builds its `RoundRobinPlan` first (consulting the leg
strategy), then generates *from* it: rounds-per-leg, role-parity scheme, and
expected pairing multiplicities are read from the plan by the generator, the
validator, and the diagnostics alike. `GenerationPlan` and
`ConstraintSatisfiabilityReport` fold into plan construction: an
unsatisfiable configuration fails while building the plan, with the same
diagnostics.

### 4. One engine interface, one driver loop, one completion product

```php
// PROPOSED — not implemented
interface StageEngineInterface
{
    public function getPlan(StageState $state): StagePlan;

    /** @throws NoValidPairingException|InvalidConfigurationException */
    public function pairNextRound(StageState $state): RoundPairing;

    public function isComplete(StageState $state): bool;

    /** The uniform completion product; null while the stage is unfinished. */
    public function getOutcome(StageState $state): ?StageOutcome;
}

final readonly class StageState        // serializable: toArray()/fromArray()/JSON  ✅ first cut
{
    /** @param array<Participant> $participants Active participants */
    public static function start(array $participants): self;

    /** Record a completed round: its results and any byes it awarded. */
    public function withRoundPlayed(RoundPairing $pairing, array $results): self;

    /** Withdrawals: the participant leaves; their results remain. */
    public function withoutParticipant(Participant $participant): self;
}

final readonly class RoundPairing      // unifies SwissRoundPairing + EliminationRoundPairing
{
    public function getRoundNumber(): int;
    public function getLabel(): ?string;      // 'semifinal', 'losers round 2'; null for Swiss
    /** @return array<Event> */
    public function getEvents(): array;
    /** @return array<Participant> */
    public function getByes(): array;
}

final readonly class StageOutcome
{
    public function getStandings(): Standings;      // meaningful for every format
    /** @return array<Result> */
    public function getResults(): array;
    /** @return array<string, int> Bye counts by participant ID */
    public function getByes(): array;
    public function getWinner(): ?Participant;      // bracket champion; league leader; null if N/A
}
```

The driver loop is the single integration a platform writes, and it ends
uniformly:

```php
// PROPOSED — not implemented
$state = StageState::start($participants);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    $results = playRound($pairing);              // application-side
    $state = $state->withRoundPlayed($pairing, $results);
}
$outcome = $engine->getOutcome($state);          // feed progression selectors
```

`StageState` absorbs the bookkeeping the Swiss engine currently pushes onto
callers (bye threading, round numbers) and gives withdrawals a first-class
verb. It serializes, so platforms persist state between rounds instead of
re-deriving it. ✅ Serialization is first-cut scope.

`StageOutcome` is what makes the stage lifecycle uniform end-to-end: every
format finishes as "an outcome you can select from" — which is precisely the
mental model consuming platforms already hold. Format-specific conveniences
(`getChampion()`, `getQualifiers()`) remain on concrete engines but delegate
to the outcome. ✅ Lifted into the interface.

### 5. Progression selectors — the hand-off between stages

Selectors consume a `StageOutcome` and produce an ordered, reseeded
participant list for a destination stage. Two built-in families cover the
two legitimate progression substrates:

```php
// PROPOSED — not implemented
interface ProgressionSelector
{
    /** @return array<Participant> Reseeded via Participant::withSeed() */
    public function select(StageOutcome $outcome): array;

    /** How many participants this selector yields (destination validation). */
    public function getSelectionSize(): ?int;
}

// Standings-based (rank slices):
RankRangeSelector::topPerGroup(2);            // today's getQualifiers(2)
RankRangeSelector::perGroup(from: 3, to: 4);  // the losers' route
RankRangeSelector::overall(from: 1, to: 8);   // best N across all pools

// Outcome-based (recorded match results — never points arithmetic):
MatchOutcomeSelector::winners();              // knockout round -> next round
MatchOutcomeSelector::losers();               // knockout round -> repechage/losers' route
```

Multi-route progression is several selector calls against one outcome.
Which selectors run for which destination stage is application
configuration; the selectors themselves — including reading winners from
recorded results with certainty — are library-guaranteed. All selectors are
config-constructible with stable identifiers.

### 6. Pools as a generic composition primitive

`GroupStageEngine` currently hard-codes: serpentine distribution → round
robin per group → standings → top-K qualifiers. Consuming platforms model
pools more generically — a pool is a bucket of participants; what format the
bucket plays, how it is scored, and how it is displayed are separate,
configurable concerns. The engine decomposes accordingly:

```php
// PROPOSED — not implemented
$pools = PoolDistributor::serpentine($participants, pools: 4);  // array<string, array<Participant>>
// each pool then runs ANY per-stage format (round robin, Swiss, ...)
// per-pool standings via StandingsCalculator as today
// progression via selectors over the pools' combined StageOutcome
```

✅ Resolves the "engine or composer" question: `GroupStageEngine` is
retired in favour of a distributor plus ordinary per-pool stages plus
selectors. What a pool *plays* is no longer fixed to round robin, and
"group stage vs bracket" becomes — as it should be — a matter of per-pool
format and display, not a different kind of object.

### 7. Two-legged elimination ties ✅ first Phase 3 cut

Knockout ties played over two legs (mirrored roles), with the aggregate
deciding advancement under rules Tactician must never own (away goals,
extra time, penalties):

- `EliminationOptions(legsPerTie: 2)` makes the engine emit two events per
  pairing within a bracket round; the plan's expected counts include both.
- The engine advances on a **tie result**: the application resolves the
  aggregate under its own rules and records which participant won the tie.
  Per-leg `Result`s remain ordinary results feeding standings/statistics.

## Naming decision

Candidates considered for the shape object, with the reasoning:

- **`ExpectedSchedule`** — names the validation role well ("what the
  schedule should look like"), but the object is not a schedule (it has no
  events), it is consulted mid-generation and mid-tournament (not only at
  validation time), and the name collides conceptually with the `Schedule`
  DTO. Rejected.
- **`AlgorithmPlan`** — names the producer, not the content; consumers ask
  "what shape is this stage", not "what did the algorithm produce".
  "Algorithm" is also implementation jargon in an API whose users think in
  formats. Rejected.
- **`TournamentPlan`** — names the content and reads well, but overclaims:
  the plan describes one *stage*, and the scope-alignment section makes the
  stage Tactician's unit. A multi-stage tournament has many plans.
- **`StagePlan`** ✅ **(adopted provisionally)** — precise about scope,
  reads naturally (`$context->getPlan()->getTotalRounds()`), and anchors a
  coherent family: `StagePlan` / `StageState` / `StageOutcome` /
  `StageEngineInterface`. One knock-on: the bracket pairing accessor
  formerly sketched as `RoundPairing::getStage()` ('semifinal') is renamed
  `getLabel()` so "stage" is never ambiguous.

## What happens to existing classes

| Current | Fate |
|---------|------|
| `ExpectedEventCalculator`, `RoundRobinEventCalculator`, `SimpleSwissEventCalculator` | Folded into `StagePlan` implementations |
| `ScheduleValidationContext` | Removed; validators take the plan |
| `ScheduleIntegrityValidator` | Becomes `StagePlan::validateIntegrity()` |
| `GenerationPlan`, `ConstraintSatisfiabilityReport` | Folded into plan construction |
| `LegStrategyInterface::planGeneration()` / `canSatisfyConstraints()` | Replaced by a plan-construction hook ❓ (naming open) |
| `SwissRoundPairing`, `EliminationRoundPairing` | Replaced by `RoundPairing` |
| `SwissPairingEngine::pairNextRound(4 args)` | `pairNextRound(StageState)` |
| `SimpleSwissScheduler` | ✅ Removed: the Swiss engine gains an optional `Randomizer` (shuffling within equal-standings groups), which with no recorded results reproduces random non-repeat pairing; a preset covers the whole-schedule convenience |
| `GroupStageEngine` | ✅ Retired in favour of `PoolDistributor` + per-pool stages + selectors (section 6) |
| Shape metadata keys on `Schedule` | Kept for serialization/display, but written *from* the plan |

## Sequencing

Five milestones, each shippable green:

1. **Plan introduction** — `StagePlan` + implementations; context,
   validation, diagnostics, and constraints consume it; calculators and
   validation-context classes removed.
2. **Options objects** — `SchedulerInterface` rework; config-constructible
   options; the legs/rounds overload dies.
3. **Engine unification** — `StageState` (serializable), `RoundPairing`,
   `StageEngineInterface`, `StageOutcome`; Swiss/elimination engines
   conform; `SimpleSwissScheduler` removed.
4. **Progression + pools** — selectors (rank- and outcome-based),
   `PoolDistributor`, `GroupStageEngine` retirement; two-legged ties.
5. **Sweep** — docs, examples, memory bank, deprecated-class removals.

## Remaining open questions

1. **Plan-construction hook naming** on leg strategies (replaces
   `planGeneration()`/`canSatisfyConstraints()`).
2. **Neutral `PointsSystem` preset naming** (`threeOneZero()`?), and whether
   the sport-named aliases stay.
3. **Cross-pool `StageOutcome` shape**: one combined outcome for a pooled
   stage (with per-pool standings inside) vs one outcome per pool. The
   selector API prefers the former; the composition story prefers the
   latter.
