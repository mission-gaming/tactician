# Architecture decision records

Short records of single design decisions: what was decided, why, and what
follows from it. Each one names the code that implements it, so a record that
no longer matches `src/` is a defect to fix, not history to keep.

Larger designs stay in [`docs/design/`](../design/), and the component
inventory in [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md). A decision recorded
there is not repeated here.

| No. | Decision |
| --- | --- |
| [0001](0001-reseeded-brackets-rank-from-earlier-rounds.md) | Re-seeded brackets rank survivors from earlier rounds only |
| [0002](0002-standings-order-is-total.md) | Standings order is total (superseded by 0004) |
| [0003](0003-multi-participant-events-are-a-2-0-goal.md) | Multi-participant events are a goal for 2.0 |
| [0004](0004-position-decides-where-results-do-not.md) | Position decides where results do not |
| [0005](0005-serialized-forms-carry-a-format-version.md) | Serialized forms carry a format version |
| [0006](0006-pools-of-unequal-size-are-compared-by-a-method-the-caller-names.md) | Pools of unequal size are compared by a method the caller names |
| [0007](0007-recorded-rounds-are-read-not-derived.md) | Recorded rounds are read, not derived |
| [0008](0008-a-decision-orders-level-entries-and-never-a-result.md) | A decision orders level entries and never a result |
| [0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) | A bye is worth what the ranking strategy says (amended by 0014) |
| [0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md) | A tiebreaker between the tied participants is computed by the calculator, and a run of them is one small table (amended by 0014) |
| [0011](0011-a-tie-both-entrants-left-is-void.md) | A tie both entrants left is void |
| [0012](0012-a-withdrawal-states-its-round.md) | A withdrawal states its round (amended: an adjustment does too) |
| [0013](0013-a-finished-bracket-keeps-its-winner.md) | A finished bracket keeps its winner |
| [0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md) | A bracket's table forms its tied sets inside a tier and credits its byes (amends 0009 and 0010) |

## Adding a record

Copy the three headings (Context, Decision, Consequences), take the next
number, and add a row to the table. Keep a record to one decision. When a
decision is replaced, add a new record and mark the old one as superseded by
it; do not rewrite the old one.
