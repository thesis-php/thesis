# Reviews

Read before reviewing a change or acting on the findings of a review.

## Rules

- A task states when it is done (a test that passes, a behaviour, `make check P=<name>`). Without that
  the author decides when to stop and the reviewer decides what counts as a defect.
- For a behaviour defect, give an input or state that produces a wrong result, crash or broken invariant.
- For a violation of a written project rule, cite the rule and the changed line that violates it.
- The author decides preferences that no rule covers. They do not start another round.
- Close the review once the confirmed behaviour defects and violations of written rules have been resolved.
- Findings of one kind that keep coming back mean a gap in the rules or the tests, not a need for more rounds.

## Procedure

1. Before the review, `make test`, `make phpstan`, `make fixer` and `make rector` of the package pass:
   a reviewer does not spend itself on what a tool catches.
2. Start a fresh agent with the best available model for code review. Use high reasoning effort if the model
   supports it. The reviewer gets the diff, the task with its "done" criterion and the rules, never the
   reasoning of the author: an agent that sees it agrees with it instead of checking
   the code. Report each finding with the evidence required above.
3. The author reproduces a behaviour defect with a failing test where applicable, or verifies a rule violation
   against the cited line. Fix the confirmed finding and explain why it was missed. The answer
   goes where it prevents the whole class: a PHPStan, fixer or rector rule if a tool can catch it, otherwise
   an invariant or a trap in the narrowest `AGENTS.md` or topic. A rejected finding goes to "Decisions"
   of the narrowest file with its reason, so the next review does not raise it again.
4. The next round reviews only the diff of the fixes. If the second round still has confirmed findings,
   stop and report to the user instead of a third round.
5. The report: what was fixed, what was rejected and why, which rules and checks were added.

## Decisions

- Rejected: review rounds until a review finds nothing. A reviewer always finds something and every fix adds code
  to review, so the loop does not converge.
