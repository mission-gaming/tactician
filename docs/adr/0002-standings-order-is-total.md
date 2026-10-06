# 0002. Standings order is total

Status: accepted

## Context

Standings drive decisions, not only display: Swiss pairing, bye selection,
re-seeded brackets and the rank-based progression selectors all read the order
of the table. Two participants can be level on the ranking value and on every
configured tiebreaker, most often before any result exists. If their order
then depended on input order or on the sort implementation, the same results
could give different pairings on different calls.

## Decision

`StandingsCalculator` never leaves two entries unordered. After the ranking
strategy's value and the configured tiebreakers it compares, in this order:

1. score difference, higher first;
2. score for, higher first;
3. seed, lower first, with unseeded participants after seeded ones;
4. label, in case-insensitive natural order;
5. participant ID.

IDs are unique within a stage, so the last step decides, with one exception
found after this record was written: the step compares the two IDs with
PHP's `<=>`, which compares numeric strings as numbers. Two IDs that are
different strings and equal numbers (`'01'` and `'1'`) are not ordered by
it, and two such entries that are level on everything before keep their
input order. The [roadmap](../ROADMAP.md#known-limitations) records it as a
known limitation; changing the comparison reorders existing tables, so it
waits for a minor release.

## Consequences

- The same participants and results always give the same table, so everything
  derived from it is reproducible.
- With no results recorded, the table is in seed order, with unseeded
  participants after it in label order. That is what lets the Swiss engine
  pair a first round from standings alone.
- The fallback steps are part of the output. Changing them changes generated
  pairings, which the golden fixtures will report as an output change.
- An application that wants a different final order (a play-off, drawing of
  lots) applies it to the entrant list it passes to the next stage; position
  is authoritative there.
