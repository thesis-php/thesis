# Reviews

Read before reviewing a change, acting on findings, or checking a change to agent instructions.

## Rules

- A task states when it is done (a test that passes, a behaviour, `make check P=<name>`). Without that
  the author decides when to stop and the reviewer decides what counts as a defect.
- For a behaviour defect, give an input or state that produces a wrong result, crash or broken invariant.
- For a violation of a written project rule, cite the rule and the changed line that violates it.
- The author decides preferences that no rule covers. They do not start another round.
- Close the review once the confirmed behaviour defects and violations of written rules have been resolved.
- Findings of one kind that keep coming back mean a gap in the rules or the tests, not a need for more rounds.

## Procedure

1. Before reviewing a code change, check the affected package with `make test`, `make phpstan`,
   `make fixer-check` and `make rector-check`. For a documentation-only change, verify the claims and links.
2. Start a fresh agent with the best available model for code review. Use high reasoning effort if the model
   supports it. The reviewer gets the diff, the task with its "done" criterion and the rules, never the
   reasoning of the author: an agent that sees it agrees with it instead of checking
   the code. Report each finding with the evidence required above.
3. The author reproduces a behaviour defect with a failing test where applicable, or verifies a rule violation
   against the cited line. Fix the confirmed finding. If the same mistake can recur, use an existing check or
   rule where possible. Add a check or a rule when it would catch a concrete future case. Record a rejected
   approach in the narrowest topic only when another change is likely to face that choice and the reason still applies.
4. The next round reviews only the diff of the fixes. If the second round still has confirmed findings,
   stop and report to the user instead of a third round.
5. The report: what was fixed, what was rejected and why, which rules and checks were added.

## Checking agent instructions

Pick a past change of the kind the instruction covers. Give a fresh agent the original task in an isolated copy
of its parent commit with the instructions under test, or reverse just the fix and its regression test on a later
revision. Remove notes that disclose the historical solution. Keep source, tests, dependencies and configuration
compatible. Check that the relevant suite passes before the task. Keep the historical fix and the
acceptance criteria outside the prompt.

Decide what evidence would satisfy the task before the run. For PHPDoc, check the contract owner and claims against
the implementation. For a bug, run the regression on the old and fixed code. For a change users can observe,
also check the README and the resulting `[Unreleased]` entry. Compare outcomes even if the patch differs from
the historical fix. Consult the agent's tool log when you need to tell a missed instruction from a misapplied one.
Record the base and fix commits, any setup edits, the instruction revision or patch, the prompt, the acceptance
checks, and the observed outcome. Report hints given after the start separately from what the agent did on its own.

## Decisions

- Rejected: review rounds until a review finds nothing. A reviewer always finds something and every fix adds code
  to review, so the loop does not converge.
