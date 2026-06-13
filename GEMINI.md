# System Safeguards & Operational Mandates

To prevent catastrophic workspace failure and protect the integrity of the project, the following mandates are foundational and absolute.

## 1. Protected Directories
The following directories and files are **STRICTLY PROTECTED**. No agent or tool call may delete, move, or rename them under any circumstances:
- `.git/` (All Git history, configuration, and metadata)
- `.github/` (Workflows and CI/CD configuration)
- `.agents/` (Agent-specific logic and skills)
- `GEMINI.md` (These mandates)

## 2. Destructive Command Restrictions
- **No Recursive Deletion**: Tools like `run_shell_command` must NEVER execute `rm -rf` on any path containing more than one subdirectory depth without explicit user confirmation.
- **No Path Wildcards**: Avoid using wildcards (`*`) in destructive commands (e.g., `rm`, `mv`) that could resolve to system-critical files.
- **Git Decoupling Forbidden**: Any action that results in the loss of the `.git` directory or decoupling from the repository is a critical failure. If a task requires refactoring the project structure, the `.git` directory MUST be preserved.

## 3. Safe File Management
- **Verification before Modification**: Before running any script or command that modifies the file system at scale (e.g., `package.sh`, mass refactoring), the agent MUST verify the target path using `ls` or `stat`.
- **Backup on High-Risk Ops**: For operations involving structural changes to the workspace, the agent should recommend or perform a manual backup of critical configuration files (`docker-compose.yml`, `wp-config.php`).

## 4. Recovery Protocol
- In the event of accidental deletion, stop immediately. Do not attempt further destructive calls. Use session history and project memory to identify the point of failure before proposing a recovery plan.
