# 0007. Recorded rounds are read, not derived

Status: accepted. Narrows the first sentence of
[0001](0001-reseeded-brackets-rank-from-earlier-rounds.md): an engine still
keeps no state of its own, and it no longer resolves the recorded rounds
again.

## Context

A results-driven engine keeps nothing between calls. Through 0.2.x the two
elimination engines answered every call by working the whole bracket out
again from the participant list of the `StageState`: they folded the list,
derived each round, and looked the recorded results up against the ties they
had derived. The rounds recorded on the state were not read as rounds at
all. Three things followed.

- A state whose recorded round was not the round the engine derives was
  unreadable. The single elimination engine refused it
  (`RecordedRoundMismatch`); the double elimination engine found no result
  for its own ties and offered the round again. That is what happened to a
  re-seeded bracket stored by a release with another re-seeding order, to
  any round an organiser had drawn by hand, and to a re-seeded bracket with
  an adjustment recorded after a round it would have changed (issue #154).
- A withdrawal changed the participant list and therefore the fold: the
  whole bracket was drawn again, around rounds that had been played.
- An application could not hold the stage in its own tables. Its fixture
  rows are the rounds, and an engine that derives the rounds itself can
  disagree with rows that are already published, after any release that
  changes a pairing order.

The Swiss engine never had the first problem: it pairs one round from the
table and reads the recorded pairings only to avoid repeats. It had another:
it paired the next round from whatever results were recorded, so a state
with a round still being played and a state of a schedule drawn up front
without results were the same thing to it.

## Decision

**A recorded round is a fact.** An engine reads the rounds recorded on the
state as they are and derives only the round that does not exist yet.

1. **The bracket engines read recorded ties.** The slots of the first
   round are the fold of the entrant list. For each round after that, where
   the state holds a round, its two-participant events are the ties: each is
   settled from its results, and the entrants who advance are the slots of
   the next round, in the order of the slots they stood in (a tie stands
   where the earlier of its two entrants stood). Where the state holds no
   round, the round the engine derives from the slots is the round to pair.
   A recorded round that pairs the slots as the engine would gives exactly
   the bracket 0.2.x derived, so every pairing and table of a state the
   engines paired themselves is unchanged
   (`EliminationBracketSupport::readRound()`).
2. **A round the engine would have paired otherwise is reported, not
   refused.** `HistoryReadingEngine::getRecordedRounds()` gives each
   recorded round with the engine's own derivation beside it and says
   whether the two differ (`RecordedRound::isManual()`). The flag is
   computed on demand and is not stored: a state does not know its engine,
   and an application's rows have no column for it. Two refusals remain. A
   single elimination engine without the third-place option still refuses a
   state that holds a tie marked as one, because the mark is how an
   unstamped state says which configuration paired it. A double elimination
   stage recorded so that it leaves another number of entrants than the
   stage has places is refused, because the later stages take their
   entrants by place.
3. **A round that is not finished is read, and only the next round is
   refused.** `isComplete()` is false, `getOutcome()` is null, and
   `pairNextRound()` throws an `UnresolvedRoundException` that carries the
   events still awaited. It keeps the reason `RoundPartiallyResolved` and is
   an `InvalidConfigurationException`, so code that caught the bracket
   engines' refusal still does. `getReadiness()` answers the same question
   without an exception. This holds for the Swiss engine too, which is the
   one behaviour change: it no longer pairs past an unfinished round unless
   it is asked to (`pairNextRoundOnIncompleteResults()`, which marks the
   round) or is in results-free mode.
4. **Pairing without results is a mode, and part of the fingerprint.**
   `SwissPairingEngine(resultsFree: true)` is the engine the whole-schedule
   preset drives. The option is in the fingerprint when it is on and absent
   otherwise, so every stamp written before it existed is still the stamp
   of an engine that waits.
5. **A withdrawal keeps the entrant's place.** `StageState` keeps the
   entrant list with the withdrawn in their places once a round is recorded
   (`getEntrants()`; before that, a withdrawal removes the entrant, as it
   always did). A bracket folds from that list, so a tie of a withdrawn
   entrant is not played and the other entrant advances.
6. **`fromHistory()` records through the verbs and checks three rules
   more.** It orders the rounds and the results as the verbs would have
   recorded them and goes through the same private path as `fromArray()`,
   so there is one notion of a state. The three hard rules (round numbers
   1, 2, 3; every participant an entrant; nobody twice in a round) are
   checked by `fromHistory()` only. `fromArray()` and the verbs keep
   accepting what they accepted (a gap in the round numbers among them),
   because data stored by earlier releases holds it and must keep loading.

## Consequences

- An upgrade cannot disagree with rounds that exist. What a release changes
  is the next round it pairs.
- A state does not say when an entrant withdrew or when an adjustment was
  made. The reading needs neither: results decide a tie where they are
  complete, and where both entrants of a tie have withdrawn, the one a later
  recorded round names went on. The derivation shown beside a recorded
  round does need them and does not have them: it counts every adjustment
  and treats a withdrawn entrant as present in every round that names it,
  so a round paired before an adjustment can be reported as manual when it
  was not. Recording when each was made would remove that, and would add
  keys to the stored form.
- The refusal issue #154 describes is gone: an adjustment recorded at any
  point of a re-seeded bracket leaves every recorded round as it was.
- A bracket's recorded round is no longer offered again by
  `pairNextRound()`: a round that is recorded with none of its results is
  an unfinished round like any other. Read it from the state.
- An event of a bracket round that is no tie of the bracket (between two
  entrants neither of whom is in the round, or with an entrant who is in
  another tie of that round) is still read by nothing.
- A round with no tie left to play, because of withdrawals, takes no round
  number unless it is recorded. Whether a recorded round is such a round is
  told by whom it names: it is that round when it names both entrants of one
  of the ties the bracket has there, as the tie itself or, in a round drawn
  by hand, each against somebody else. The round after it cannot name both,
  because one of the two had withdrawn before it was paired. A round drawn
  by hand that names at most one entrant of every such tie is read as the
  round after it, which pairs the same entrants.
- Everyone a state has seen and no longer lists as active is read as
  withdrawn, also a participant whose place the state does not know (a state
  stored before the entrant list was kept): such a participant is never
  paired again, and a tie it advanced to is uncontested.
- `tests/Feature/StageHistoryTest.php` holds the rules: for every engine, a
  state built from a history equals the state the verbs built at every
  point of a stage, a shuffled supply reads the same, manual rounds never
  throw, each hard rule has its reason, and a bracket with withdrawals at
  any point is played to its end.
