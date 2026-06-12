# Design System Specification: The Kinetic Data Aesthetic

## 1. Overview & Creative North Star
The North Star for this design system is **"The Precision Architect."** 

In the crowded landscape of multi-channel marketing, "Standard SaaS" feels like a template. We are moving beyond the generic grid to create an editorialized data experience. This system balances the cold efficiency of data-first logic with the high-end feel of a premium architectural firm. We achieve this through "The Breathing Layout"—using intentional asymmetry, massive internal whitespace, and layered transparency to ensure that even the most complex marketing datasets feel effortless and curated.

## 2. Colors & Surface Philosophy
The palette is rooted in a deep, oceanic navy and a high-performance cobalt. We do not use color to "decorate"; we use it to signal intent and hierarchy.

### The "No-Line" Rule
**Borders are a failure of hierarchy.** In this system, 1px solid borders for sectioning are strictly prohibited. Boundaries must be defined through background color shifts.
*   **Action:** To separate a sidebar from a main content area, place a `surface-container-low` (#161a33) sidebar against a `surface` (#0d112a) background. The transition of tone is your divider.

### Surface Hierarchy & Nesting
Treat the UI as a physical stack of frosted glass sheets.
*   **Base:** `surface-container-lowest` (#080c25) — Use for the deepest background layer.
*   **Sectioning:** `surface-container-low` (#161a33) — Use for primary workspace areas.
*   **Elevated Components:** `surface-container-high` (#242842) — Use for cards and interactive modules.
*   **The "Glass" Rule:** For highlight cards (e.g., "Top Performing Campaign"), use a background of `rgba(22, 27, 69, 0.6)` with a `backdrop-filter: blur(12px)`. This allows the deep navy tones to bleed through, creating a "soulful" depth that flat hex codes cannot achieve.

### Signature Textures
Avoid flat primary blocks for hero moments. Use a subtle linear gradient for primary CTAs and active states:
*   **Primary Gradient:** From `primary-container` (#0064ff) to `inverse-primary` (#0054d8) at a 135-degree angle. This adds a microscopic level of "weight" to the button that feels more tactile and premium.

## 3. Typography: The Editorial Scale
We use two distinct typefaces to separate "Action" from "Insight."

*   **Display & Headlines (Manrope):** This is our "Editorial" voice. Its geometric, wide stance commands authority. Use `display-lg` (3.5rem) for high-level data summaries (e.g., Total Revenue) to make numbers feel like art.
*   **Body & Labels (Inter):** This is our "Utility" voice. Inter’s high x-height ensures readability in dense marketing tables.
*   **The Contrast Rule:** Always pair a `headline-sm` in Manrope with a `label-md` in Inter. The shift in font family creates a clear cognitive break between "Title" and "Instruction."

## 4. Elevation & Depth
Traditional drop shadows are too "heavy" for a precision data tool. We use **Tonal Layering.**

*   **The Layering Principle:** Depth is achieved by "stacking" the surface tiers. A `surface-container-highest` (#2f334e) card placed on a `surface-container-low` (#161a33) background creates a natural lift.
*   **Ambient Shadows:** If a floating element (like a Popover) is required, use a shadow with a 32px blur and 4% opacity, tinted with `#00174a` (on-primary-fixed). This mimics natural light reflecting off blue-toned glass.
*   **The "Ghost Border" Fallback:** If a border is required for accessibility, use the `outline-variant` token at **15% opacity**. It should be felt, not seen.

## 5. Components & Primitive Logic

### Buttons
*   **Primary:** Gradient fill (Cobalt to Deep Blue), 10px radius, white text. No border.
*   **Secondary:** `surface-container-highest` background. Subtle ghost border (15% opacity).
*   **Tertiary:** No background. `primary` (#b3c5ff) text. On hover, apply `primary-subtle` (rgba(0,100,255,0.08)) background.

### Cards & Lists
*   **Forbidden:** Horizontal divider lines.
*   **Required:** Use a `1.5rem` (xl) vertical spacing gap to separate list items. If items must be grouped, use a subtle background shift to `surface-container-low` on hover.

### Input Fields
*   **State:** Default inputs use `surface-container-highest` with a 10px radius. 
*   **Focus:** Instead of a heavy border, use a 2px outer glow (shadow) of `primary` at 20% opacity. The field background remains dark, keeping the focus on the data entered.

### Marketing-Specific Components
*   **Performance Chips:** Use `secondary-container` (#0566d9) for neutral metrics and `error_container` (#93000a) for declining trends. Chips should have a `full` (9999px) radius to contrast against the 10px/14px logic of the layout.
*   **Channel Indicators:** Small 8px circular "pips" using the status colors to denote channel health (Success for Active, Warning for Paused).

## 6. Do’s and Don'ts

### Do:
*   **Do** use asymmetrical margins. If a sidebar is 240px, let the right-side padding be 48px to give the data room to "breathe."
*   **Do** use `headline-lg` for large-scale metrics. Numbers are the hero of this application.
*   **Do** lean into the Dark Mode. Light mode should feel like a "high-contrast" alternative, while Dark Mode is the "Signature" experience.

### Don’t:
*   **Don't** use 100% black. Always use the specified navy-tinted backgrounds (`#080c25`) to maintain the premium feel.
*   **Don't** use standard 1px grey borders. They break the "frosted glass" immersion.
*   **Don't** crowd the dashboard. If you can't fit a widget without reducing whitespace to less than `1rem`, it belongs on a different tab or a nested layer.