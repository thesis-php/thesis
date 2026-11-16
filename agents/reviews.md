# Reviews

Read before reviewing a change or acting on the findings of a review.

## Rules

- A task states when it is done (a test that passes, a behaviour, `make check P=<name>`). Without that
  the author decides when to stop and the reviewer decides what counts as a defect.
- A finding is a defect only with a failure scenario: a concrete input or state that leads to a wrong result,
  a crash or a broken invariant. Without one it is a matter of taste: the author decides once,
  it does not go another round.
- The yardstick is the rules of the project (`php.md`, the topics, `AGENTS.md` of the package), not general taste.
  A preference that no rule covers is either written down as a rule or dropped.
- A review is done when it has no confirmed correctness findings. Style and simplification findings
  do not keep it open.
- Findings of one kind that keep coming back mean a gap in the rules or the tests, not a need for more rounds.

## Procedure

1. Before the review, `make test`, `make phpstan`, `make fixer` and `make rector` of the package pass:
   a reviewer does not spend itself on what a tool catches.
2. The reviewer is a new agent on Fable with effort `high`. It gets the diff, the task with its "done" criterion
   and the rules, never the reasoning of the author: an agent that sees it agrees with it instead of checking
   the code. It reports only findings with a failure scenario.
3. The author confirms a finding with a failing test, then fixes it and answers why it was missed. The answer
   goes where it prevents the whole class: a PHPStan, fixer or rector rule if a tool can catch it, otherwise
   an invariant or a trap in the narrowest `AGENTS.md` or topic. A rejected finding goes to "Decisions"
   of the narrowest file with its reason, so the next review does not raise it again.
4. The next round reviews only the diff of the fixes. If the second round still has confirmed findings,
   stop and report to the user instead of a third round.
5. The report: what was fixed, what was rejected and why, which rules and checks were added.

## Decisions

- Rejected: review rounds until a review finds nothing. A reviewer always finds something and every fix adds code
  to review, so the loop does not converge.
