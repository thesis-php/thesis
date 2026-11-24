---
name: sync-worktree
description: Synchronizes a worktree branch with local main when the user asks to bring both branches to the same commit.
---

# Sync a worktree with main

1. Identify the branch with `git branch --show-current` and locate the worktree for
   `refs/heads/main` with `git worktree list --porcelain`. If the request comes from
   `main` or a detached HEAD, use the worktree the user named, or ask which branch to sync.
2. Inspect the status of both worktrees. Preserve uncommitted work. If it blocks the sync,
   report the blocking paths and ask how to proceed. Leave any unrelated Git operation in progress alone.
3. In the branch worktree run `git rebase --no-update-refs main`. For each conflict, inspect
   the commit being replayed and the changes on main. Resolve it yourself when you can preserve
   both changes' intent. Stage the resolved paths and continue the rebase. If the intent is unclear
   or the two changes require incompatible behavior, leave the rebase paused and ask a focused
   question naming the file and the decision. Verify each resolution with the relevant checks.
4. In the main worktree run `git merge --ff-only <branch>`. If another worktree advanced main
   in the meantime, rebase onto its new tip and retry. If main keeps moving, report the contention.
5. Compare both branch commit IDs. Report the shared commit and, for each conflict, the file,
   how you preserved the changes, and what you checked. The sync ends locally.
