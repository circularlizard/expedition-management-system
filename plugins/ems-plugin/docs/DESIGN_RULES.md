# EMS Design Rules & Visual Guidelines

This document outlines the visual identity and structural UX guidelines for the Expedition Management System (EMS) WordPress plugin. It defines our design tokens, layout principles, and custom aesthetic rules to ensure consistency and a premium feel.

---

## 1. Aesthetic Identity & Target Profile

* **Aesthetic Baseline:** Preset B - Modern B2B SaaS (Stripe/Tailwind UI style)
* **Goal:** A clean, spacious, high-contrast dashboard that feels premium and custom-built, avoiding generic admin panel templates.

---

## 2. Design Tokens & CSS Variables

Ensure all CSS variables are declared in the root stylesheet and utilized consistently across all components:

```css
:root {
  /* Color Palette (Bespoke Expedition Theme) */
  --ems-bg-main: #FAFAF9;         /* Stone 50 - warm background */
  --ems-card-bg: #FFFFFF;
  --ems-border: #E7E5E4;          /* Stone 200 - soft borders */
  
  --ems-primary: #15803D;         /* Green 700 - rich outdoor green */
  --ems-primary-hover: #166534;   /* Green 800 */
  --ems-primary-light: #DCFCE7;   /* Green 100 - soft badge backgrounds */
  
  --ems-accent: #C2410C;          /* Orange 700 - copper/rust active states */
  --ems-accent-hover: #9A3412;
  
  /* Typography */
  --ems-font-family-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  --ems-font-family-display: 'Outfit', var(--ems-font-family-sans);
  
  /* Layout Tokens */
  --ems-radius: 8px;
  --ems-radius-badge: 9999px;
  --ems-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --ems-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
```

---

## 3. Visual Layout Guidelines

### Card-Based Architecture
* Wrap tabular screens and lists inside defined white containers (`var(--ems-card-bg)`) utilizing a rounded radius of `var(--ems-radius)` and soft shadow elevation (`var(--ems-shadow-md)`).
* Avoid flat, bare borders separating major list zones.

### Spacing Consistency
* Adhere strictly to an **8px base spacing grid** for all margins and paddings:
  * Cards / Containers padding: `24px` (`3rem` equivalent)
  * Inner cell padding: `12px` to `16px`
  * Form inputs vertical spacing: `16px`

### Modern Badge & Status System
* Shift status colors from generic, highly-saturated colors (e.g. bright red or plain yellow backgrounds) to soft-filled backgrounds with high-contrast text:
  * **Success/Completed:** Soft green bg (`--ems-primary-light`) with deep forest-green text (`--ems-primary`).
  * **Warning/Pending:** Soft amber bg with deep amber text.
  * **Critical/Action Needed:** Soft red bg with deep red text.

---

## 4. UX & Interaction Principles

### Avoiding the "Generic AI template" Feel
* **Domain-Specific Icons:** Integrate bespoke outdoor SVG icons (e.g., hiking boots, compass, tent, bike) rather than generic UI icons (like checkboxes, gear settings).
* **Context-Driven Copywriting:** Utilize highly specific copywriting describing expedition-specific tasks (e.g., *"Awaiting Route Card Submission"*, *"Coverage Gap on H-SP1"*) instead of generic administrative headings.
* **Management by Exception:** Prioritize outstanding tasks, coverage gaps, and blocked approvals at the top of lists rather than giving equal visual weight to completed records.
* **Optimized Touch Targets:** Ensure all buttons, links, and switches have touch target dimensions of $\ge 44 \times 44\text{px}$ on mobile viewports and $\ge 32\times 32\text{px}$ on desktop.

---

## 5. CSS Architecture & Code Implementation Rules

To ensure performance, maintainability, and clean DOM separation, follow these implementation rules:

* **No Inline Styles:** HTML `style` attributes are strictly prohibited in production components. Do not write `<div style={{ margin: '10px' }}>`.
* **Centralized Styles:** Define all styles inside central CSS files (e.g. `resources/css/` or component-specific stylesheets loaded via the webpack/vite build pipeline).
* **Utility Classes or Classnames:** Use centralized token-based utility classes or static className references to bind styles.
* **Skill Enforcement:** Compliance with this CSS architecture is audited programmatically by the [ui-implementation-auditor](file:///Users/davidstrachan/Projects/expedition-management-system/.agents/skills/ui-implementation-auditor/SKILL.md) skill.

---

## 6. DofE Award Branding

To make the various Duke of Edinburgh levels instantly recognizable across the application, use the following official branding palette for highlighting Bronze, Silver, and Gold content:

| Level | Primary Hex | Primary Pantone | Light Accent (Contrast) |
|---|---|---|---|
| **Gold Award** | `#B4975A` | Pantone 872 | `#F6F3EC` (Warm cream/gold tint) |
| **Silver Award** | `#A7A9AC` | Pantone 877 | `#F0F1F2` (Cool grey/silver tint) |
| **Bronze Award** | `#BA8748` | Pantone 876 | `#F8F3EC` (Warm peach/bronze tint) |
| **Multiple** | `#4F46E5` | Pantone 2386 C | `#EEF2FF` (Cool blue/indigo tint) |

### Usage Rules:
* Apply these colors to level badges, headers, and iconography specific to Bronze, Silver, and Gold expeditions.
* Ensure text rendered on light accent backgrounds has high contrast by styling it with the corresponding primary hex color or a darker variant of the level's color.


