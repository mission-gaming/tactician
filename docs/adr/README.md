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
| [0002](0002-standings-order-is-total.md) | Standings order is total |

## Adding a record

Copy the three headings (Context, Decision, Consequences), take the next
number, and add a row to the table. Keep a record to one decision. When a
decision is replaced, add a new record and mark the old one as superseded by
it; do not rewrite the old one.
