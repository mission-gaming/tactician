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
- `ExpectedSchedule`/`AlgorithmPlan` abstraction: expected events, rounds, and completeness rules supplied by the algorithm rather than inferred by generic services
- Wire `LegStrategyInterface::planGeneration()` into plan-driven generation
- Unify the incremental engines (Swiss, elimination, groups) behind a shared results-driven interface

## Phase 4: Timeline Assignment 📅
- Time and venue assignment system
- Slot-based scheduling with patterns
- Blackout periods and availability constraints
- Time-based constraint validation integration

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
