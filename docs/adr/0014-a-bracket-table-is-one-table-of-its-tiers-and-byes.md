# 0014. A bracket's table forms its tied sets inside a tier and credits its byes

Status: accepted. Amends
[0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) (for
brackets: a strategy without a bye value credits a bye with nothing, and
is refused nothing) and
[0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md).
Its rule for a strategy without a bye value is extended to Swiss by
[0019](0019-a-swiss-bye-under-a-strategy-without-a-bye-value-is-worth-nothing.md).

## Context

The table of an elimination outcome is ordered by the bracket first: the
round an entrant went out in is a tier (`Standings::getTiers()`), and the
records order the entrants inside a tier only. Two of the rules the
records were calculated by did not fit that.

**The tied sets of a tiebreaker between the tied participants ignored the
tiers.** The calculator formed them from the whole table
([0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md)),
and the bracket engines sorted the table into tiers afterwards. An event
against an entrant of another tier with the same ranking value counted in
the value, and could order two entrants of one tier who were level on
everything inside it. A double elimination bracket of six entrants, with
the chain `[BetweenTiedParticipants(RankingValueTiebreaker)]`: p3 and p5
went out in the same round with 1-2 and never met, so nothing inside their
tier separates them. p5 was placed first because p5 had beaten p4, who
went out a round later, in another tier, with the same 3 points. 0010
recorded this as a consequence, and the roadmap as a known limitation.

**The placement table credited no bye.** A bracket credits a bye in the
ranking a re-seeded round is folded from (an entrant who sat out a round
ranks with those who won a tie in it), and
[0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) made that
credit what the ranking strategy says a bye is worth. The table of the
outcome was calculated without the byes. So the two tables of one bracket
disagreed, and the outcome placed an entrant who had a bye below one of its
tier who won a tie where it sat out. Single elimination of six entrants:
A and B, in positions 1 and 2, have byes, and A loses its semifinal. The
two semifinal losers share a tier, and C (won a quarter-final, 1-1,
3 points) was placed above A (0-1, 0 points). The usage guide stated it
("a bye is not an event, so it is no win in a record") and pinned it with
an executed example.

## Decision

**A table can be given its tiers, and its tied sets are formed inside
them.** `StandingsCalculator::calculateWith()` takes a sixth argument,
`$tiers`, the tier of each participant by ID. The table it returns is in
tier order and carries the tiers; the figures order the entries inside a
tier. A tied set of a `TiedParticipantsTiebreaker` is formed among the
participants of one tier only: the tier is the first figure the sets are
read from. So an event against a participant of another tier counts in no
value of that kind. Where nothing inside a tier separates two entrants,
the entrant list does ([0004](0004-position-decides-where-results-do-not.md)).
Eight entrants, two legs a tie, with the same chain: p1 and p4 each won a
leg of their quarter-final and went out level on 3 points without having
met. p4 was placed first for the leg it won from p5, who went out a round
later on 3 points; now nothing separates them inside their tier, and p1
is first.

**The placement table credits the byes of the bracket.** The engines give
the calculator the same byes the re-seeded ranking credits, each credited
once for each leg of a tie. That is one rule for every table of a bracket,
the one 0009 states: a bye is worth what the ranking strategy says. In the
example A and C are level on 3 points and the entrant list puts A first.
Played, wins and losses still count results only, and
`StandingEntry::getByes()` counts the byes beside them.

**A bye is what the bracket view shows as one.** A bye of a bracket is a
node of its view (`Bracket`, `BracketNodeStatus::Bye`) in which one
entrant stood alone and went on: in a first round that is not full, in a
stage that took no round number because none of its entrants had anyone
to meet (a double elimination losers round whose entrants all stand
alone), and where the other slot is empty because the tie that would have
filled it was void. The table counts those, the ranking of a re-seeded
round counts those, and so does the outcome (`StageOutcome::getByes()`):
the three, and the view, agree. A walkover is no bye. An entrant whose
opponent withdrew from their tie goes on from an uncontested tie
(`BracketNodeStatus::Uncontested`), which no table credits, and so does
the entrant who takes the third place from a third-place tie the other
left. The round still lists such an entrant among its byes
(`RoundPairing::getByes()`), because the round has no event for it; that
listing is about pairing, not about what the tie is worth. The library's
glossary and 0009 define a bye as a round sat out, and the library has a
way for the application to say what a walkover is worth: an awarded result
(`DTO\Award`) for the tie, which counts as the result it names. Crediting
a walkover by itself would decide a rule of the competition for it, and
count it twice where the application records the award.

**A ranking strategy that does not say what a bye is worth credits it
with nothing, in every table of a bracket.** A bracket under such a
strategy (no `RankingStrategyWithByeValue`) counts each bye and credits it
with nothing: in the placement table and in the ranking a re-seeded round
is folded from alike, so the two still agree, and no bracket is refused
for its byes, before its first round or after its last. The calculator
does this when it is given `$creditsPerBye` 0
(`StandingsCalculator::calculateWith()`), and reads no bye value then. A
refusal could not be complete before the first round: the field says
which byes the first round has, but a withdrawal leaves an entrant
standing alone in a bracket of any size, a power of two too, so a bracket
that was paired could no longer be finished. The table of a fixed-path
bracket under such a strategy is the one it was before byes were credited
anywhere, and a re-seeded bracket, which was refused under it, now ranks
an entrant who had a bye as if the round it sat out gave it nothing, with
the entrant list ordering those who are level. This amends 0009, which
refused a table with a bye wherever no value is known, for brackets: a
competition that wants a bye in a bracket to count implements
`getByeValue()` (one method).

**Swiss is not changed here, and should follow.** The Swiss engine still
refuses a table with a bye to credit under a strategy without a bye value
(0009). The same argument holds for it: a withdrawal can make the field of
a Swiss stage odd after it has started, and the stage is then refused
mid-way. Crediting nothing there changes who is paired with whom under
such a strategy (a participant who had a bye sits in the score group of
those who lost the round), so it is left as a decision of its own.

## Consequences

- Output changes only inside a tier, and only for a bracket with a bye or
  a chain with a tiebreaker between the tied participants: no entrant
  changes tier, and a bracket with neither has the table it had. The
  default chain has no tiebreaker between the tied, so with the defaults
  the change is the credit of the byes. Ranking values of entrants with
  byes are higher by the credit. Four golden fixtures changed, each by
  the credit of a bye: the brackets of five entrants in
  `single-elimination.txt` and `elimination-decided-ties.txt` (the entrant
  in position 3, who had a bye, now above the entrant in position 4 in the
  tier of the semifinals) and the final tables of examples 27 and 30
  (ranking values only). In example 27 entrant 11 has a bye and a
  walkover, and is credited with the bye alone.
- A bracket under a ranking strategy without a bye value has the table it
  had before, with its byes counted in `getByes()`, and a re-seeded one
  that was refused is paired.
- `StageOutcome::getByes()` of a bracket is the count of its view's bye
  nodes, which is the count its table has. It no longer counts a walkover,
  which the round lists among its byes, and now counts a bye in a stage
  that took no round number, which no round lists.
- A subclass of `StandingsCalculator` was calculated through its own
  `calculate()`, which formed the tied sets over the whole table: given
  tiers with a tiebreaker between the tied participants, it was refused
  (`IncompatibleOptions`), and so was a bracket engine given one, before its
  first round. No longer: the class is final from 0.3.0
  ([0022](0022-classes-are-final-and-the-interfaces-are-the-extension-points.md)),
  so every calculator forms the tied sets inside a tier.
- The consequence of 0010 that the tied sets are read without regard to
  tiers, and the known limitation in the roadmap, no longer hold.
- `tests/Feature/BracketTablesTest.php` pins both examples and holds the
  table of generated brackets to a calculation of its own, and its byes and
  the outcome's to the bye nodes of the view, with withdrawals and
  without;
  `tests/Unit/Standings/TiersTest.php` pins the calculator with tiers, and
  `tests/Feature/ByesAndTiebreakersInvariantsTest.php` holds a table with
  tiers to its oracle over generated cases.
