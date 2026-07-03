# Roadmap

## Phase 1: Round Robin Core ✅
- Round-robin scheduler with circle method algorithm and balanced home/away roles (round-parity alternation)
- Comprehensive DTO system with modern PHP features
- Flexible constraint system with builder pattern
- Advanced constraint types (rest periods, seed protection, consecutive roles, role balance, metadata-based)
- Schedule validation system preventing incomplete tournaments
- Retry-capable generation: alternative participant orderings are tried automatically when constraints reject a schedule
- Multi-leg tournament support with strategy patterns (mirrored, repeated, shuffled) and first-class byes
- Exception handling with diagnostic capabilities
- PHPStan level 8 compliance with zero errors
- Full test suite covering edge cases and mathematical correctness

## Phase 2: Additional Algorithms ✅
- Swiss pairing engine: standings-aware Monrad pairing with repeat avoidance, bye rotation (credited as wins), home/away balancing, and withdrawal handling
- Single and double elimination brackets: fold seeding, byes, stage names, losers bracket, and optional grand-final reset
- Group stages: serpentine-seeded groups, per-group standings, and cross-group knockout qualification
- Results, standings, and tiebreakers: configurable points systems (football/chess presets) with wins, Buchholz, and Sonneborn–Berger tiebreakers
- Schedule serialization: JSON round-tripping for schedules, events, and participants
- Enhanced validation for the new tournament formats (pairing integrity, duplicate/conflicting result rejection, group-play completeness)

## Phase 3: Algorithm-Neutral Core 🔄
- ✅ `StagePlan` abstraction: expected events, rounds, and completeness rules supplied by the algorithm rather than inferred by generic services (milestone 1: context, validation, diagnostics, and constraints consume the plan; the event calculators and validation-context classes are removed, and leg strategies contribute facts via `LegPlanContribution` instead of the ornamental `GenerationPlan`)
- Typed per-algorithm options objects, dissolving the legs/rounds parameter overload
- Unify the incremental engines (Swiss, elimination, groups) behind a shared results-driven interface with a serializable `StageState` carrier

**Design proposal: [docs/design/phase-3-algorithm-neutral-core.md](design/phase-3-algorithm-neutral-core.md)**

## Phase 4: Timeline Assignment 📅
- Slot-based time assignment: round-aligned (all of a round's events together) and staggered kickoffs from one declarative slot model
- Time-aware validation (double-booking, hour-based rest, blackout periods) with the library's diagnostic character
- Mechanism only — slot patterns in, timestamped events out; config parsing, persistence, and notification policy stay application-side
- Venue/resource modelling

**Position paper: [docs/design/timeline-assignment.md](design/timeline-assignment.md)**

## Phase 5: Advanced Features 🚀
- Schedule optimization algorithms and quality metrics
- Backtracking generation for constraint configurations the greedy generator cannot satisfy
- Integration examples with popular frameworks
- Advanced diagnostic reporting and constraint suggestion systems

## Use Cases

- **Gaming Tournaments**: Esports, board games, card games
- **Sports Leagues**: Round-robin leagues, swiss tournaments
- **Academic Competitions**: Debate tournaments, quiz bowls
- **Corporate Events**: Team building competitions, hackathons
