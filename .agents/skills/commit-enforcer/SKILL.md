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

To avoid manual password entry during `git push`, use the `.env.local` file.

1. **Setup**: Create a `.env.local` file (excluded via `.gitignore`).
2. **Content**: Add `GITHUB_PASSPHRASE=your_passphrase`.
3. **Execution**: Wrap the push command to use the passphrase if possible, or remind the user of its availability.

## Commit Procedure

1. **Check Status**: `git status`
2. **Review Changes**: `git diff HEAD`
3. **Stage Specific Files**: `git add <file1> <file2>` (Avoid `git add .`)
4. **Draft Message**: Propose a message following the `type: description` pattern (e.g., `feat:`, `fix:`, `chore:`, `docs:`).
5. **Commit & Push**:
   ```bash
   git commit -m "..." && git push origin main
   ```
