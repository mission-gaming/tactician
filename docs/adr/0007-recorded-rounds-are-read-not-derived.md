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
   round) or is in results-free mode. A bracket tie that has every result,
   finished level and carries no decider is the same kind of thing, a
   round that awaits a fact, and is read the same way: the bracket is not
   complete, `getReadiness()` lists the events of the tie apart from those
   that await a result (`getUndecidedEvents()`), and `pairNextRound()`
   keeps the refusal it had (`UndecidedTie`, with the round and the two
   participants). A state that says something no decision can mend (two
   results of one event, a decider on a tie its results decide) is still
   refused by every call.
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
6. **A state says when.** A round is paired from what is known at that
   moment (ADR 0001), so reading it afterwards takes the same knowledge,
   and two facts a state holds can come later than a round: an adjustment
   and a withdrawal. Each is recorded with the number of the last round
   that was recorded when it happened (`Adjustment::getAfterRound()`, 0
   before the first round; `Stage\Withdrawal`, kept in the order the
   withdrawals happened, `StageState::getWithdrawals()`). This is option 2
   of issue #154. The verbs record it without being told, because a state
   knows its rounds; a history states it (`Adjustment::madeAfterRound()`, a
   `Withdrawal` among the withdrawals). An engine then reads a recorded
   round N from the adjustments made after a round before N and from the
   entrants who had not withdrawn after a round before N.

   **To a state a round is paired when it is recorded**, and an
   application may record a round only once it has been played (pair,
   play, record). What happens while that round is played is then recorded
   with the round before it. For a withdrawal the rounds put that right
   themselves: a round that names an entrant, in an event or as a bye, was
   paired while the entrant was in the stage, and so was every round
   before it, and that is read before the round the withdrawal states, or
   does not state. For an adjustment nothing in a round does: it is read as
   known when the round was paired, and the remedy is the application's,
   which records a round when it is paired where adjustments are made
   while a round is played. With that:
   - a re-seeded bracket ranks the survivors of a recorded round from
     those adjustments, and the round that is paired next from all of them;
   - the derivation shown beside a recorded round, and with it
     `isManual()`, is the round the engine pairs from that knowledge, in
     all three engines;
   - a pair of slots a bracket round holds no tie for is settled from who
     had withdrawn when the round was paired, and from nobody who left
     later;
   - whether a recorded round is the round the bracket has next, or the
     one after a round nobody played, is told by whether a tie could be
     played at the time;
   - where both entrants of a tie have withdrawn and its results are not
     complete, the first of them to withdraw gave the tie to the other,
     who went on. That holds whether the round holds the tie or not and
     whenever the bracket is read: the answer for a history does not
     depend on the moment an engine was asked.

   The stored form has two optional keys for it, `after_round` in an
   adjustment and `withdrawals` in a stage state, both of version 1 of
   their forms (ADR 0005: 0.3.0 is not released, and the version of a form
   is raised for a key added after a release, not before it).

   **An absent round means that the time is not known, and nothing else.**
   Data stored before the keys existed has none, and neither has a history
   that does not state it. It loads. It is not read as "after the last
   round" (which would make every round look like the engine's own) nor as
   "before the first" (which is how the engines read an adjustment before,
   and what issue #154 is about). The engines read what the recorded rounds
   themselves show, and where that does not decide, they claim nothing:
   - an adjustment without a round counts in every table and in the round
     paired next, where the time makes no difference;
   - beside a recorded round whose pairing it could have changed (every
     round of a Swiss stage, every round after the first of a re-seeded
     bracket) the engine gives no derivation and reports nothing as manual;
   - a re-seeded bracket reads such a round where the order of the
     survivors makes no difference to the reading, which is wherever every
     survivor was still in the bracket when the round was paired (each is
     in a tie of the round or went on). Where a survivor had withdrawn
     before the round, what became of it depends on whom it was folded
     with, and taking either answer can change the size of the next round:
     the state is refused (`RecordedRoundMismatch`, with the round, the
     adjusted participants and the withdrawn ones), on every call;
   - a withdrawal without a round is read from the rounds: the entrant was
     in the stage for every round that names it and for those before, and
     for no round after the last that does. Where both entrants of a
     tie have withdrawn that way, the one a later recorded round names went
     on, and where a later round names neither, or both, neither is read as
     having gone on: they share the tier of the round, and a bracket that
     ends with such a tie has nobody in its first tier.
7. **The outcome of a bracket says who went on from its last tie.** The
   results do not say it where a finalist withdrew: the final then has no
   complete result, or no round at all. `StageOutcome::getSettledTies()`
   carries the tie as the engine settled it (`Stage\SettledTie`), and
   `MatchOutcomeSelector` reads that where an outcome has it and the
   results where it has not, so a selection of the bracket winner and the
   table agree.
8. **`fromHistory()` records through the verbs and checks three rules
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
- Issue #154 is closed by its option 2. An adjustment recorded at any
  point of a re-seeded bracket leaves every recorded round as it was, the
  ties and what is read from the ranking alike: whom an entrant would have
  met who had withdrawn before a round was paired, for which the round has
  no event, is taken from the ranking of the time. A round an engine paired
  is not reported as manual for what was recorded after it.
- A state whose adjustments and withdrawals carry their rounds, which is
  every state built by the verbs and every history that states them, is
  read exactly. What is read without them is in the decision above, and it
  is less: no derivation, one refusal, and two things about a withdrawal
  that the rounds alone do not show (which of two withdrawn entrants of a
  tie went on, and whether an entrant a round does not name had left
  before it or had a bye in it that the round does not list).
- **A round supplied without its byes is not refused.** A bye is a fact of
  a round, and a history is to supply it. An engine can tell that a bye is
  missing only while the entrant who had it is still in the stage (it is in
  the round's slots, in no tie and not listed), and such an entrant is read
  as having gone on anyway, so that refusal would catch nothing that is
  misread. The case that is misread is the other one: the entrant has
  withdrawn since, and a round that does not list it looks the same as a
  round it had left before. Nothing in the round tells the two apart. A
  later round that names the entrant does, and is read; where there is
  none, the round of the withdrawal does, and with it the round is read
  correctly whether its byes are listed or not. So the fix is the
  withdrawal's round, and the limit of a history without it is stated with
  the others.
- `MatchOutcomeSelector::select()` no longer raises the deprecation of
  `tie_winner` for the outcome of an engine: it reads the tie as settled,
  and the engine raised the notice when it built the outcome.
- `withAdjustment()` returns a state whose adjustment is another object
  than the one given when that one stated no round, and a stored state
  with adjustments or live withdrawals is two keys longer.
- A bracket's recorded round is no longer offered again by
  `pairNextRound()`: a round that is recorded with none of its results is
  an unfinished round like any other. Read it from the state.
- An event of a bracket round that is no tie of the bracket (between two
  entrants neither of whom is in the round, or with an entrant who is in
  another tie of that round) is still read by nothing.
- A round with no tie left to play, because of withdrawals, takes no round
  number unless it is recorded. Whether a recorded round is such a round is
  told by who was in the bracket when it was paired: it is that round when
  one of the ties the bracket has there could be played then. An entrant
  the recorded round or a later one names was in the bracket, whatever its
  withdrawal states; one that no round from there on names was not, where
  its withdrawal states an earlier round or none. So a round drawn by hand
  is known by naming the entrants of one of the bracket's ties, as the tie
  itself or each against somebody else, and the round after a round nobody
  played cannot name both, because one of the two had withdrawn before it
  was paired. A round drawn by hand that leaves out, of every tie the
  bracket has there, an entrant who has withdrawn at a time the state does
  not know and whom no later round names, is read as the round after it,
  which pairs the same entrants.
- Everyone a state has seen and no longer lists as active is read as
  withdrawn, also a participant whose place the state does not know (a state
  stored before the entrant list was kept): such a participant is never
  paired again, and a tie it advanced to is uncontested.
- `tests/Feature/StageHistoryTest.php` holds the rules: for every engine, a
  state built from a history equals the state the verbs built at every
  point of a stage, a shuffled supply reads the same, manual rounds never
  throw, each hard rule has its reason, and a bracket with withdrawals at
  any point is played to its end.
