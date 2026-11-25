# 0025. A round drawn by hand is read as drawn

Status: accepted. Narrows, in
[0007](0007-recorded-rounds-are-read-not-derived.md), what point 1 says of
an entrant a recorded round leaves alone, says how the last round of a
single elimination bracket with a third-place tie is read when it is drawn
by hand, and says how a round drawn by hand that names an entrant who has
gone out is read (amended for issue #230).

## Context

A recorded round is a fact ([0007](0007-recorded-rounds-are-read-not-derived.md)):
the bracket engines read its ties as they are, and the entrants who go on
from it are the slots of the next round, one unit for each tie and one for
each entrant who stood in the slots and is in none of the round's ties. Two
hand-drawn rounds were read in a way nobody who drew them meant.

- **A withdrawn entrant kept a place** (issue #172). Sixteen entrants on a
  fixed path; the first withdraws after round 1, and the organiser draws the
  quarterfinals by hand among the seven left: three ties and a bye. The
  withdrawn entrant stood in the slots and in none of the ties, so it was a
  unit of its own. It went out there, but its unit was a slot of the next
  round, an empty one: five slots where the organiser had left four
  entrants. The engine then paired a quarterfinal of one tie, a semifinal of
  one tie and the final, five rounds in all, with a finalist who never
  played. Once a withdrawal states its round
  ([0012](0012-a-withdrawal-states-its-round.md)), the state knows that the
  entrant had left before the round was paired.
- **A last round that swaps entrants was read as an extra round** (issue
  #183). With the third-place option, an organiser draws the last round by
  hand and puts a semifinal winner in the third-place tie and a semifinal
  loser in the final. The tie marked as the third-place tie was read as
  that tie, but the semifinal winner in it also stood in a slot of the
  final and in no other tie of the round, so it was a unit of its own and
  went on alone: two units, a second round labelled final, and the same
  entrant twice in the view of one round (in the third-place tie and as a
  bye).
- **An entrant who had gone out came back** (issue #230, found in the
  review of this record). A recorded tie was read as a tie of the round
  where one of its two entrants was in the round, whoever the other was.
  Eight entrants, round 1 played as paired; round 2 drawn by hand as
  `p8 v p1` and `p2 v p3`, although p8 lost to p1 in round 1. p8 won, went
  on, and was paired in the next round. A tie marked as the third-place tie
  was read as that tie whoever it held, so two quarterfinal losers could be
  placed third and fourth.

The choice for the second was between reading such a round as a manual
pairing of the last round and refusing it as a breach of a hard rule. Every
other round drawn by hand is read and reported as manual, not refused
(0007, point 2), and the two refusals that remain are about a state that
cannot be read at all, not about a draw the engine would not have made.

## Decision

**An entrant a recorded round leaves alone in its slots goes on, unless it
had withdrawn when the round was paired, and only an entrant who goes on
holds a place in the next round.** A withdrawn entrant left alone beside an
entrant whom the round put in a tie goes out in that round, as it did, and
the tie of the other entrant holds the place of their pair of slots. So the
next round has one slot for each tie of the round and each entrant who went
on alone, and nothing for the withdrawn. Where the other slot of the pair is
empty, or the round holds no tie for the pair at all, nothing changes: the
pair is settled as the bracket settles it (a bye, a walkover, or a void tie,
whose slot stays empty so that the halves of the bracket stay apart). A
round the engine paired itself never leaves an entrant alone beside one in
a tie, so no round the engines pair is read otherwise. The rule is
`EliminationBracketSupport::readRound()`, and is read by both engines: in
double elimination it means that such a stage no longer leaves one place
too many, which the engine refused (`RecordedRoundMismatch`). There the
withdrawn entrant drops nobody to the losers bracket and goes out as the
two of a void winners tie do, with those the next stage that puts an
entrant out puts out: where it would have gone out had the engine paired
the round and given its tie away.

**The last round of a single elimination bracket with a third-place tie is
read as drawn.** The tie of the round marked `BracketTie::ThirdPlace` is
the third-place tie, whoever it holds, and the one other tie of the round
is the final, whoever it holds; a tie marked `BracketTie::Final` is the
final also where neither of its entrants stood in a slot of the round, as
long as both are in the round (the two of the third-place tie the engine
would have paired); a tie between two who went out earlier is no tie of the
bracket, marked or not, and is read by nothing. An
entrant of the third-place tie is in a tie of the round, and never goes on
alone. The bracket ends with the round: its winner is the entrant who went
on from the final, the one it beat is second, and the two of the third-place
tie are third and fourth, as the tie decides. The round is reported as
manual (`RecordedRound::isManual()`), beside the round the engine would have
paired. Who was in the final and the third-place tie, for reading the last
ties when they were decided ([0013](0013-a-finished-bracket-keeps-its-winner.md)),
are the entrants of the ties the round holds. A round whose events carry no
mark is read as any round is: nothing in it says which tie is which.

**A round drawn by hand does not bring back an entrant who has gone out.**
An entrant has gone out where an earlier round of the walk put it out of
the bracket: in single elimination the loser of a tie, an entrant that went
out alone, the two of a void tie that nobody went on from; in double
elimination one put out of the stage, not one a winners tie dropped. A tie
of the round between an entrant who is in the round (in its slots, or in
the tie that stands in no slot) and one who has gone out and is not in the
round is given to the entrant who is in the round, whatever its results
say, as a tie is given away that the other entrant has withdrawn from. The
one who had gone out goes out nowhere here: it holds the place it went out
in, in the table and in the outcome, and the node of the tie names only the
entrant of the round (`Uncontested`). The tie is reported as manual, as a
tie the engine would not have paired, and its results are recorded results,
which the table counts as it counts every result. A tie marked as the
third-place tie is that tie only where one of its two is in the round; so
marked between two who went out earlier, it is no tie of the bracket and is
read by nothing, like any tie between two who went out.

The choice was between that, refusing the state, and reading the round as
drawn with the entrant back in the bracket. Bringing it back reads a later
result against an earlier one: the bracket would hold an entrant that its
own results put out. Refusing makes the stage unreadable on every call for
a round an organiser drew, which 0007 (point 2) refuses for no draw the
engine would not have made; the two refusals there are about a state that
cannot be read at all. Reading the entrant as out keeps every earlier
result and reads the round, as an entrant who had withdrawn is read.

**Except where it takes the place of an entrant who had withdrawn, in
single elimination.** Where the entrant of the round in such a tie was to
meet an entrant who had withdrawn when the round was paired, and the round
puts that entrant in no tie and lists it among no byes, the one who had
gone out is in the round in its place: the round brought it in as a lucky
loser, and the tie is read as any tie, its winner going on. The withdrawn
entrant goes out in the round and holds no place, as above. That is what a
redrawn round looked like when 0.2.3 drew the round again after a
withdrawal, and what such a round recorded by that release is read as
(`tests/Feature/StoredByEarlierReleasesTest.php`). Double elimination
does not read it so: the stages there take their entrants by place from
each other, and an entrant outside a stage can hold a place in another.

None of the rules depends on the order in which the facts arrived: the first
reads who had withdrawn when the round was paired, which the rounds and the
withdrawals say whatever order they were recorded in
([0012](0012-a-withdrawal-states-its-round.md)), and the second reads the
marks of the recorded events. Who has gone out is read from the results
of the earlier rounds, and whether the entrant had withdrawn when the
round was paired as the first rule reads it, so the third rule reads the
same facts whatever order they were recorded in.

**The fingerprint keeps its version.** A state that holds such a round is
paired otherwise from it now, which
[0015](0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md)
would answer with a new version of the rules. As there, the case is narrow
(a round drawn by hand, after a withdrawal, across the last two ties or
with an entrant who had gone out) and
was read wrongly, and a new version would refuse the stamp of every bracket
on a fixed path and of every double elimination bracket, which pair as they
did.

## Consequences

- In the case of #172 the bracket has four rounds, the quarterfinals
  drawn by hand, two semifinals and the final, and the withdrawn entrant
  shares the tier of the quarterfinals with the entrants who lost there.
- In the case of #183 the bracket is over once both ties of the last round
  are decided, and its view has one round labelled final, in which every
  entrant is once.
- The bracket view shows the withdrawn entrant of the first rule in a node
  of its own, `Void`, which holds no place: no slot of the next round is
  filled from it.
- In the case of #230 p1 goes on from the tie with p8, p8 stays level
  with the other entrants who went out in round 1, and the next round is
  `p1 v p4` with a bye for p2 (p4 was in no tie and went on alone). A
  third-place tie drawn with a quarterfinal loser against a semifinal loser
  places the semifinal loser third and the other semifinal loser fourth.
- `tests/Feature/HandDrawnRoundsTest.php` holds the cases, and
  `tests/Feature/EliminationInvariantsTest.php` a sweep of brackets with
  rounds drawn by hand among the entrants left after withdrawals, and a
  sweep of single and double elimination brackets with rounds drawn by hand
  with an entrant who had gone out, in which no such entrant is paired again
  or goes on.
