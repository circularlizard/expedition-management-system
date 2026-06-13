---
name: commit-enforcer
description: Enforces a regular commit and push workflow at key milestones. Use this skill when completing a sub-task, implementing a new feature, or fixing a bug to ensure work is safely persisted with clear commit messages and SSH passphrase automation.
---

# Commit Enforcer

This skill formalizes the workflow for frequent, safe commits and pushes.

## Core Rules

1. **Commit Early, Commit Often**: Commit at every logical milestone (e.g., after a successful test run, after implementing a single class, after a batch refactor).
2. **Push Immediately**: Always push after a commit to ensure remote synchronization.
3. **Descriptive Messages**: Use concise, "why"-focused commit messages.
4. **Safety First**: NEVER commit `.env`, secrets, or PII.

## Milestones for Committing

Trigger a commit when:
- A new unit test passes.
- A new feature or utility class is completed.
- A bug is successfully reproduced and fixed.
- A documentation phase is finished.

## SSH Passphrase Automation

To avoid manual password entry during `git push`, the skill uses a helper script.

1. **Setup**: Ensure `.env.local` exists in the project root (ignored by git).
2. **Content**: Add `GITHUB_PASSPHRASE=your_passphrase` to `.env.local`.
3. **Execution**: The push command must be prefixed with `SSH_ASKPASS` and `SSH_ASKPASS_REQUIRE`:
   ```bash
   SSH_ASKPASS=.agents/skills/commit-enforcer/scripts/askpass.sh SSH_ASKPASS_REQUIRE=force DISPLAY=:0 git push origin main
   ```
   *Note: Ensure the script is executable (`chmod +x`).*

## Commit Procedure

1. **Check Status**: `git status`
2. **Review Changes**: `git diff HEAD`
3. **Stage Specific Files**: `git add <file1> <file2>` (Avoid `git add .`)
4. **Draft Message**: Propose a message following the `type: description` pattern (e.g., `feat:`, `fix:`, `chore:`, `docs:`).
5. **Commit & Push**:
   ```bash
   git commit -m "..." && SSH_ASKPASS=.agents/skills/commit-enforcer/scripts/askpass.sh SSH_ASKPASS_REQUIRE=force DISPLAY=:0 git push origin main
   ```
