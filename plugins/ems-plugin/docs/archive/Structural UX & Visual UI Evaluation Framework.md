# Structural UX & Visual UI Evaluation Framework

**Project:** Expedition Management System (EMS) / Expedition Management Utility (EMU)

**Target:** WordPress Admin & Public/Parent Portal

---

## 1. Overview & Strategy

Evaluating a custom WordPress plugin requires two distinct layers of analysis: **Structural UX (Information Architecture & Workflow Efficiency)** and **Visual/Tactical UI (Styling & Consistency)**.

* **Micro-Audits (Visual UI):** Focus on micro-level styling—padding, color palettes, font scaling, contrast ratios, and responsive breakpoints. These ensure the app looks clean and readable.
* **Macro-Audits (Structural UX):** Focus on user flows, cognitive friction, context-switching, and information density. These ensure the app is actually fast, logical, and easy to use under real-world conditions (e.g., a volunteer managing 30+ explorers on a tight deadline).

---

## 2. Structural UX Evaluation Framework

The core functionality of EMS is a **logistics pipeline**:

$$\text{Sign-ups} \longrightarrow \text{Team Placement} \longrightarrow \text{Training Check} \longrightarrow \text{Route Processing}$$

To evaluate whether the application's layout effectively supports this funnel, review screens against three primary user workflows:

### Workflow A: Team Formation & Placement

* **Goal:** Match sign-ups to expedition teams while honoring participant availability and preferred teammates.
* **UX Criteria:**
* **Side-by-Side Context:** Can the admin see constraints (availability and requested friends) on the screen *at the exact moment* of assigning a team?
* **Low Friction:** Does team assignment support inline editing or drag-and-drop interactions, or does it require navigating into separate edit screens for each individual explorer?
* **Immediate Validation:** Are constraint conflicts (e.g., date mismatches) flagged automatically upon assignment?



### Workflow B: Readiness Tracking (Training & Routes)

* **Goal:** Track training progress and route submissions across explorers and teams.
* **UX Criteria:**
* **Management by Exception:** Does the interface highlight incomplete items and blockers, or force the user to manually audit full lists of completed items?
* **Batch Operations:** Can group leads approve requirements (e.g., "First Aid Completed") across an entire team in a single action?



### Workflow C: The Parent / Explorer Portal

* **Goal:** Provide clear, scoped status updates to non-admin users on desktop and mobile devices.
* **UX Criteria:**
* **Role-Scoped Projections:** Does the portal display a clean subset of admin data without exposing internal administrative controls?
* **Actionability:** Are outstanding tasks (e.g., consent forms, medical updates) highlighted clearly at the top of the mobile viewport?

### Workflow D: Volunteer & Leader Coverage
* Goal: Coordinate volunteer supervisors and assessors for expeditions based on their availability.
* UX Criteria:
    * Can admins easily match volunteers to specific expedition dates using the availability recorded in ems_volunteer_availability?
    * Is there clear feedback showing whether an expedition has sufficient supervisor coverage?

### Workflow E: Data Sync Control Flow (System UX)
* Goal: Allow leaders to trigger sync actions with Online Scout Manager (OSM) without exposing raw API tokens or breaking UI
responsiveness.
* UX Criteria: Does the interface provide clear loading/progress feedback during OSM import processes and handle failures gracefully?



---

## 3. Visual & Tactical UI Evaluation Framework

To convert subjective aesthetic targets (*"looks great"*, *"easy to navigate"*) into explicit, machine-evaluatable rules, use the following visual design criteria:

| Category | Objective Requirement | AI / Automated Evaluatable Criterion |
| --- | --- | --- |
| **Typography** | Clear Hierarchy | H1, H2, and H3 headers follow a consistent type scale. Body text is legible with standard line heights ($1.4\text{--}1.6$). |
| **Color & Contrast** | Accessibility (WCAG AA) | Text-to-background contrast ratio is $\ge 4.5:1$. Semantic states (e.g., errors, warnings, sync locks) use distinct status colors plus explicit textual badges. |
| **Spacing & Grid** | Visual Balance | Elements strictly adhere to spacing increments ($8\text{px} / 16\text{px} / 24\text{px}$). Containers display uniform internal padding without cramped text. |
| **Usability & Touch** | Interactive Elements | Interactive buttons and inputs maintain minimum touch target dimensions ($\ge 44 \times 44\text{px}$). Hover and focus states are visual and clear. |
| **Responsive Layout** | Mobile Readiness | No horizontal scrolling or overflowing elements occur on small viewports (e.g., $375\text{px}$ width). Flex/grid layouts wrap cleanly. |

---

## 4. Execution Tools & Setup

### Option 1: Native Google Antigravity Agent Workflow (Recommended for UI & UX Reviews)

Google Antigravity natively integrates browser automation, subagent delegation, and custom **Skills** defined in `.agents/skills/`.

* **Custom Skill Definition:** Save your rules to `.agents/skills/ui-auditor/SKILL.md`. Include visual spacing rules, accessibility constraints, and workflow heuristics.
* **Browser Integration:** Antigravity agents drive local or headless Chrome instances to navigate live WordPress dashboards, emulate mobile viewports, and capture visual states.
* **CLI Execution (`agy`):** Run background or multi-agent audits directly from the command line:
```bash
agy "Audit the EMS dashboard and parent portal on http://localhost:8080 against .agents/skills/ui-auditor/SKILL.md. Evaluate both structural UX friction and visual styling. Output screenshots to tests/ui-audit/ and write a summary to docs/ui-design-audit-report.md."

```


* **Artifact Generation:** Antigravity produces structured Markdown reports and marked-up visual artifacts to review asynchronously.

### Option 2: Scripted Automated Testing (Playwright + `playwright-bdd`)

Useful if you want deterministic, code-level test suites that execute inside continuous integration (CI) pipelines:

* **Mock API Isolation:** Run tests with `ems_api_mode === 'mock'` to simulate external dependencies (e.g., Online Scout Manager endpoints) safely and deterministically.
* **Edge-Case Scenarios:** Write `.feature` files in standard Gherkin syntax covering auth boundaries, non-ASCII name handling (e.g., `François-Marie`), malformed dates, and API rate-limit statuses.
* **Asset Pipeline:** Capture baseline PNG images during test runs (`tests/ui-audit/screenshots/`) for visual comparison or documentation generation (`docs/reviewer-guide.md`).

---

## 5. Implementation Roadmap

```
┌────────────────────────────────────────────────────────┐
│               PHASE 1: STRUCTURAL UX AUDIT             │
│  - Define primary user journeys (A, B, C)              │
│  - Execute Cognitive Walkthroughs on wireframes/screens│
│  - Eliminate unnecessary click depth & tab-switching   │
└──────────────────────────┬─────────────────────────────┘
                           │
                           v
┌────────────────────────────────────────────────────────┐
│            PHASE 2: CONFIGURE AGENT SKILLS             │
│  - Create `.agents/skills/ui-auditor/SKILL.md`          │
│  - Define spacing tokens, contrast, & touch target rules│
│  - Set up local environment/mock data fixtures         │
└──────────────────────────┬─────────────────────────────┘
                           │
                           v
┌────────────────────────────────────────────────────────┐
│              PHASE 3: RUN ANTIGRAVITY AUDIT            │
│  - Execute `agy` prompt for interactive browser run    │
│  - Review generated UI Audit Report Artifact           │
│  - Apply layout fixes and polish visual hierarchy       │
└────────────────────────────────────────────────────────┘

```