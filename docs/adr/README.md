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
| [0007](0007-recorded-rounds-are-read-not-derived.md) | Recorded rounds are read, not derived (amended by 0018 for round numbers, by 0020 for a participant twice in a round and by 0021 for a participant who is not an entrant) |
| [0008](0008-a-decision-orders-level-entries-and-never-a-result.md) | A decision orders level entries and never a result |
| [0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) | A bye is worth what the ranking strategy says (amended by 0014 and 0019; narrowed by 0017 for a league) |
| [0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md) | A tiebreaker between the tied participants is computed by the calculator, and a run of them is one small table (amended by 0014) |
| [0011](0011-a-tie-both-entrants-left-is-void.md) | A tie both entrants left is void |
| [0012](0012-a-withdrawal-states-its-round.md) | A withdrawal states its round (amended: an adjustment does too) |
| [0013](0013-a-finished-bracket-keeps-its-winner.md) | A finished bracket keeps its winner |
| [0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md) | A bracket's table forms its tied sets inside a tier and credits its byes (amends 0009 and 0010; extended to Swiss by 0019) |
| [0015](0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md) | An engine fingerprint versions the rules of each configuration |
| [0016](0016-qualifiers-for-a-bracket-keep-their-tiers.md) | Qualifiers for a bracket keep their tiers |
| [0017](0017-a-league-rest-earns-nothing.md) | A league rest earns nothing (narrows 0009) |
| [0018](0018-the-rounds-of-a-stage-state-are-numbered-1-2-3.md) | The rounds of a stage state are numbered 1, 2, 3 (amends 0007) |
| [0019](0019-a-swiss-bye-under-a-strategy-without-a-bye-value-is-worth-nothing.md) | A Swiss bye under a strategy without a bye value is worth nothing (amends 0009) |
| [0020](0020-nobody-is-in-a-round-of-a-stage-state-twice.md) | Nobody is in a round of a stage state twice (amends 0007; amended by 0021) |
| [0021](0021-everyone-in-a-round-of-a-stage-state-is-an-entrant.md) | Everyone in a round of a stage state is an entrant (amends 0007 and 0020) |
| [0022](0022-classes-are-final-and-the-interfaces-are-the-extension-points.md) | Classes are final, and the interfaces are the extension points |

## Adding a record

Copy the three headings (Context, Decision, Consequences), take the next
number, and add a row to the table. Keep a record to one decision. When a
decision is replaced, add a new record and mark the old one as superseded by
it; do not rewrite the old one.
