# 0014. A bracket's table forms its tied sets inside a tier and credits its byes

Status: accepted. Amends
[0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) and
[0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md).

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
the calculator the same byes the re-seeded ranking credits: every advance
without a tie (the byes the rounds list, a walkover, and an advance through
a stage that had no tie to play and took no round number), each credited
once for each leg of a tie. That is one rule for every table of a bracket,
the one 0009 states: a bye is worth what the ranking strategy says. In the
example A and C are level on 3 points and the entrant list puts A first.
Played, wins and losses still count results only, and
`StandingEntry::getByes()` counts the byes beside them.

**A bracket that has byes needs a value for them, re-seeded or not.** A
bracket whose field is not a power of two is refused under a ranking
strategy that does not say what a bye is worth (`IncompatibleOptions`),
from the first call, before a round is paired. That refusal was made for a
re-seeded bracket only, the one table that credited a bye; every bracket's
table credits them now, so single elimination on a fixed path and double
elimination are refused too, rather than refusing the outcome once the
bracket has been played.

## Consequences

- Output changes only inside a tier, and only for a bracket with a bye or
  a chain with a tiebreaker between the tied participants: no entrant
  changes tier, and a bracket with neither has the table it had. The
  default chain has no tiebreaker between the tied, so with the defaults
  the change is the credit of the byes. Ranking values of entrants with
  byes are higher by the credit. Three golden fixtures changed, each by
  the credit of a bye: the brackets of five entrants in
  `single-elimination.txt` and `elimination-decided-ties.txt` (the entrant
  in position 3, who had a bye, now above the entrant in position 4 in the
  tier of the semifinals) and the final table of example 27.
- A bracket under an application's own ranking strategy that does not
  implement `RankingStrategyWithByeValue` and whose field is not a power of
  two is refused where it was played before. The strategy implements the
  interface (one method) to go on.
- A bye a bracket credits can be one no round lists: an advance through a
  stage with no tie to play takes no round number (a double elimination
  losers round whose entrants all stand alone, a round whose ties were all
  given away). The outcome's bye counts (`StageOutcome::getByes()`) are the
  byes the rounds list, so for such a bracket they can be fewer than the
  entries' `getByes()`.
- A subclass of `StandingsCalculator` is calculated through its own
  `calculate()`, which forms the tied sets over the whole table: given
  tiers with a tiebreaker between the tied participants, it is refused
  (`IncompatibleOptions`). Without such a tiebreaker its table is put into
  tiers after `calculate()`, in the order `calculate()` gave.
- The consequence of 0010 that the tied sets are read without regard to
  tiers, and the known limitation in the roadmap, no longer hold.
- `tests/Feature/BracketTablesTest.php` pins both examples and holds the
  table of generated brackets to a calculation of its own;
  `tests/Unit/Standings/TiersTest.php` pins the calculator with tiers, and
  `tests/Feature/ByesAndTiebreakersInvariantsTest.php` holds a table with
  tiers to its oracle over generated cases.
