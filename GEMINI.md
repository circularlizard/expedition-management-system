# System Safeguards & Operational Mandates

To prevent catastrophic workspace failure and protect the integrity of the project, the following mandates are foundational and absolute.

## 1. Protected Directories
The following directories and files are **STRICTLY PROTECTED**. No agent or tool call may delete, move, or rename them under any circumstances:
- `.git/` (All Git history, configuration, and metadata)
- `.github/` (Workflows and CI/CD configuration)
- `.agents/` (Agent-specific logic and skills)
- `GEMINI.md` (These mandates)

## 2. Destructive Command & Database Restrictions
- **No Recursive Deletion**: Tools like `run_shell_command` must NEVER execute `rm -rf` on any path containing more than one subdirectory depth without explicit user confirmation.
- **No Path Wildcards**: Avoid using wildcards (`*`) in destructive commands (e.g., `rm`, `mv`) that could resolve to system-critical files.
- **Git Decoupling Forbidden**: Any action that results in the loss of the `.git` directory or decoupling from the repository is a critical failure. If a task requires refactoring the project structure, the `.git` directory MUST be preserved.
- **Zero Database Destruction**: Agents must NEVER run `docker-compose down -v`, `wp db reset`, `DROP TABLE`, `TRUNCATE`, or destructive SQL commands without explicit user confirmation.
- **External Repository Boundary**: `login-with-google` (`oauth-login`) lives in its own repository and must NEVER be deleted, moved into the monorepo, or altered during monorepo tasks.

## 3. Safe File & Architecture Management
- **Verification before Modification**: Before running any script or command that modifies the file system at scale (e.g., `package.sh`, mass refactoring), the agent MUST verify the target path using `ls` or `stat`.
- **Preflight Snapshot on High-Risk Ops**: For operations involving database migrations or structural refactoring, the agent MUST capture a baseline snapshot using `bash bin/preflight-snapshot.sh --mode=local --out=/tmp/ems-snapshot/`.
- **Architectural Blast-Radius Isolation**: New features in `ems-plugin` MUST be isolated behind `ModuleInterface`, wrapped in `Shortcode_Boundary`, and keep third-party dependencies strictly inside `src/Integrations/` adapters.
- **Mandatory Quality Gate**: Before marking any task complete, agents MUST run `npm run verify:all` (or the equivalent PHPUnit, PHPStan, and Vitest suites) and verify local deployment via `bash bin/deploy.sh`.

## 4. Recovery Protocol
- In the event of accidental deletion, stop immediately. Do not attempt further destructive calls. Use session history and project memory to identify the point of failure before proposing a recovery plan.
