# 0013. A finished bracket keeps its winner

Status: accepted. Replaces, in [0011](0011-a-tie-both-entrants-left-is-void.md),
what it says of the last tie of a bracket: that it is void whenever both of
its entrants have withdrawn.

## Context

[0011](0011-a-tie-both-entrants-left-is-void.md) made a tie both entrants
left void, and read an entrant given a tie as having gone through it only if
it was still in the stage when its next tie was paired. A last tie (the
final, the third-place tie, the grand final or its reset) has no next tie,
so 0011 made it void whenever both of its entrants had left, whenever they
left. An entrant who had the final given to it, because the other finalist
withdrew, lost the title if it withdrew afterwards, also long after the
bracket was over; a bracket's winner could change with a withdrawal from
whatever came next, and a composition that had already taken the winner on
to a later stage read another outcome the next time it asked.

A competition does not take a title back from an entrant who leaves after
winning it. Once the last tie is decided the bracket is over, and leaving
afterwards is leaving the next stage.

## Decision

**A last tie is decided at the first moment it is decided.** That is when
its results decide it, or when one of its entrants withdraws while the
other is still in the stage: the other has it then. A withdrawal of either
entrant after that takes nothing back. In single elimination with a
third-place tie the final and the third-place tie are each decided on their
own: a final given away stays given while the third-place tie is played, and
the other way round.

**A bracket is over when its last ties are decided**, and is read as it
stood then: every withdrawal after that moment is left out of the reading,
the winner's included. "Over" is, precisely:

- single elimination: the final is decided, and the third-place tie where
  the bracket has one;
- double elimination: the grand final is decided and no reset is due (the
  winners champion won it, or the bracket plays none), or the reset is
  decided;
- the same for a bracket that re-seeds each round, which only orders its
  slots otherwise.

**The moments are those of the state's clock.** The rounds are in their
order, and the withdrawals in the order they happened, which a state keeps
(`StageState::getWithdrawals()`) and a history gives as the order of the
withdrawals of one round. A withdrawal of an entrant the last recorded
round names happened after that round was paired, whatever round it
states, as everywhere ([0007](0007-recorded-rounds-are-read-not-derived.md)).
The engines read the bracket counting the withdrawals one by one from the
first that happened after the last recorded round was paired: the first
count at which the bracket is complete is the end, and the entrants of a
last tie decided at a count keep it from there on
(`EliminationBracketSupport::readUntilOver()`). A walk that does not reach
the last round of the bracket is read once, as it stands.

**A withdrawal after the end is ignored by the bracket, not refused.** The
state cannot refuse it: `StageState::withoutParticipant()` does not know
which engine reads the state, and the same participant can be in a later
stage that the state also serves. An engine that refused a state with such
a withdrawal would refuse it on every call, so a finished stage would lose
its outcome for good over a fact that is true. The loud-failure rule of the
library is for what cannot be read correctly; this can, and the reading is
the one stated here. The withdrawal stays in the state as recorded.

**A last tie is void only where the times are not known.** Data stored by
0.2.x keeps withdrawals without a time; a last tie both of whose entrants
left there is still void, as 0011 says. With the times known the first of
two entrants to leave a last tie gave it to the other, so a bracket every
entrant leaves is won by the one still in it when the others had left.

A last tie is pairwise, and so is this rule ([0003](0003-multi-participant-events-are-a-2-0-goal.md)).

## Consequences

- A bracket's outcome, its table, its settled ties, the selector and the
  recorded rounds read the same after every withdrawal from a finished
  bracket (`tests/Feature/EliminationInvariantsTest.php`, "takes back
  nothing once the bracket is over"), in single and double elimination,
  with a third-place tie, two legs, a reset and re-seeding.
- The order of withdrawals after one round now decides something: who has
  a last tie both entrants left. 0011 said it decided nothing.
- An entrant who has withdrawn can be listed among the byes of the last
  round the engine pairs: the finalist who was given the final before it
  withdrew, where the round still holds the third-place tie. The round
  names it because it holds the final, as the round names the entrant who
  takes the third place without a tie.
- A walk that reaches the last round costs one more walk for each
  withdrawal after the last recorded round, until the bracket is complete;
  other calls cost nothing more.
- In a state that records a round only once it has been played, a
  withdrawal during the last round of an entrant that round does not name
  (an entrant of the third-place tie that was not paired, say) is read as
  made before the round was paired. Recording each round when it is paired
  (`StageState::withRoundPaired()`) leaves nothing to read so.
- `tests/Feature/StageTimesTest.php` ("a tie both of whose entrants have
  withdrawn", "a finished bracket keeps its winner") holds the cases.
