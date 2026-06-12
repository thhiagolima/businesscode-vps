# Kinetic Data Aesthetic — Visual Redesign Spec

## Overview

Apply the "Kinetic Data Aesthetic" identity from the wireframes to the entire BusinessCode SaaS platform, keeping Tabler/Bootstrap as the CSS framework and overriding via custom CSS variables + scoped styles.

**Scope:** All 30+ pages — 8 with wireframes, 20+ inheriting the design system.

**Approach:** 3 layers — Foundation (affects everything), Wireframed pages, Non-wireframed pages.

---

## Layer 1: Design System Foundation

### Color Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--bc-bg` | `#0d112a` | Page background |
| `--bc-dark` | `#080c25` | Deepest layer (canvas, deepest bg) |
| `--bc-dark-surface` | `#161a33` | Sidebar, secondary areas |
| `--bc-surface-container` | `#1a1e37` | Intermediate container |
| `--bc-gray` | `#242842` | Elevated cards |
| `--bc-gray-soft` | `#2f334e` | Inputs, surface-highest |
| `--bc-text` | `#dee0ff` | Primary text |
| `--bc-text-muted` | `#8c90a2` | Secondary text |
| `--bc-primary` | `#0064ff` | Primary actions (keep) |
| `--bc-primary-hover` | `#0054d8` | Primary hover |
| `--bc-primary-subtle` | `rgba(0,100,255,0.08)` | Subtle highlights |
| `--bc-success` | `#10b981` | Success states |
| `--bc-danger` | `#ef4444` | Error states |
| `--bc-warning` | `#f59e0b` | Warning states |
| `--bc-white` | `#ffffff` | White text on dark bg |
| `--bc-outline` | `rgba(66,70,86,0.15)` | Ghost borders (if absolutely needed) |

### Typography

| Usage | Font | Weight |
|-------|------|--------|
| Headlines, page titles, KPI numbers | Manrope | 700, 800 |
| Body, labels, buttons | Inter | 400, 500, 600 |
| KPI display (large numbers) | Manrope | 800, size 3.5rem |
| Code/mono fallback | JetBrains Mono | 400 |

### Surface Rules

- **No borders.** Separation by background color shift only.
- **Ghost border fallback:** `rgba(66,70,86,0.15)` — only when accessibility requires it.
- **Cards:** Background `#242842`, border-radius `14px`, no border.
- **Glass cards (highlight):** `rgba(22,27,69,0.6)` + `backdrop-filter: blur(12px)`.
- **Inputs:** Background `#2f334e`, border-radius `10px`, no border. Focus: `box-shadow: 0 0 0 2px rgba(0,100,255,0.2)`.
- **Buttons primary:** Gradient `linear-gradient(135deg, #0064ff, #0054d8)`, white text, no border.
- **Buttons secondary:** Background `#2f334e`, ghost border 15% opacity.
- **Badges:** `border-radius: 9999px` (pill shape).

### Tabler Override Strategy

Override Tabler's CSS variables in `theme.css` under `[data-bs-theme="dark"]` (which becomes the default):

```css
[data-bs-theme="dark"] {
  --tblr-body-bg: #0d112a;
  --tblr-card-bg: #242842;
  --tblr-card-border-color: transparent;
  --tblr-border-color: transparent;
  --tblr-body-color: #dee0ff;
  --tblr-secondary-color: #8c90a2;
  /* ... etc */
}
```

Force dark mode as default in `useTheme.ts` — set initial theme to `'dark'`.

### Layout Changes

**Sidebar (`AppSidebar.vue`):**
- Background: `#161a33` (no border right)
- Logo: gradient icon (cobalt→deep blue) + "BusinessCode" in Manrope + "MARKETING SUITE" subtitle
- Nav items: no background, hover with subtle `rgba(0,100,255,0.08)` background
- Active item: primary background pill
- Bottom: user avatar + name + role
- Settings as last nav item (not in user dropdown)

**Topbar (`AppTopbar.vue`):**
- Background: transparent (blends with page bg)
- Search bar: `#2f334e` rounded full width
- Right: notification bell + dark mode toggle + user avatar with dropdown
- Breadcrumbs: inline, muted text

---

## Layer 2: Wireframed Pages

### Dashboard

**Layout:**
- Header: "Marketing Overview" (Manrope h1) + subtitle + "Export Report" + "New Campaign" buttons
- 3 KPI cards (glass): Total Campaigns (with trend sparkline), Active Campaigns (with icon), Revenue/Credits (Manrope display 3.5rem)
- Campaign Performance: area chart (dark bg, blue gradient fill, no grid lines)
- Top Performing Channels: card with horizontal progress bars per channel
- Recent Campaigns: table (no borders, hover bg shift), columns: name+icon, type badge, status badge, engagement, cost, date

### Contacts

**Layout:**
- Left sidebar: Segments list (with count badges) + "Database Health" glass card
- Main: Table with avatar, name, email/phone, last active, status badge (colored dot)
- Footer: 3 glass KPI cards (Engagement Rate, Active Automations, New Contacts)
- Header: segment title + description + Import button

### Campaign Wizard — 3 Steps

The current 5-step wizard is restructured into 3 macro-steps:

**Step indicator:** Horizontal breadcrumb-style with labels: "Identity & Content" → "Audience & Timing" → "Review & Launch"

#### Step 1: Identity + Content

**Top zone (hero):** Campaign name input (large, centered, Manrope placeholder), channel icon + description.

**Bottom zone (2 columns):** Content varies by channel:

**SMS (AI mode):**
- Left (5/12): Briefing card — Product, Audience, Benefit, CTA, Tone (select), Variations (select), Link (optional), Advanced (avoid words, 160 char limit). "Generate Variations" button.
- Right (7/12): Empty state OR tabs "Current" / "History". Variation cards: numbered, text, char count badge (green ≤160, red >160), copy button. History: past sessions with "Use" button.

**SMS (Manual mode):**
- Left (5/12): Textarea with char counter + SMS split indicator. "Analyze with AI" button.
- Right (7/12): Preview inline.

**Voice (AI mode):**
- Left (5/12): Briefing card (same fields minus link/160 limit) + Voice Library (cards with play/preview, name, language, gender — scrollable 240px) + Audio Generation (character/duration/cost metrics, "Generate Audio" button, player, "Confirm Audio" button).
- Right (7/12): Variation tabs (current/history) + Audio History section below (players with "Use" button).

**Voice (Manual mode):**
- Left (5/12): Textarea (8 rows) + word count + duration + analyze button + Voice Library + Audio Generation.
- Right (7/12): Audio history.

**Email (AI mode):**
- Left (5/12): Subject input at top + Briefing card below. "Generate Email Variations" button.
- Right (7/12): Variation cards showing subject + body preview + badges ("Accepted", "Use Full Draft"). Live Preview at bottom: full email rendering with header/footer.

**Email (Manual mode):**
- Left (5/12): Subject input + HTML content textarea.
- Right (7/12): Live Preview rendered.

**WhatsApp:**
- Left (5/12): Template selector dropdown + Variable mapping (when template has {{n}} params).
- Right (7/12): Template preview rendered with badges.

**canAdvance validation per channel:**
- SMS: `!!content`
- Voice: `!!audio_url`
- Email: `!!subject && !!content`
- WhatsApp: `!!template_name && allVariablesMapped`

#### Step 2: Audience + Timing

**Left area (8/12):**

**Audience Selection:**
- Tab bar: "Select List" / "Upload CSV" / "Manual Input"
- List mode: Cards per list (name, count, badges, checkmark if selected). Warning card for invalid contacts.
- CSV mode: Upload zone + preview.
- Manual mode: Textarea + counter.

**Campaign Timing:**
- Two cards: "Send Now" (icon + description) / "Schedule" (icon + date/time pickers)
- Schedule shows: date picker + time picker + timezone note

**Right sidebar (4/12):**
- "Campaign Summary" glass card: Total Audience (big number), Credits Required, Available Credits, Estimated Cost
- Phone preview mock below

#### Step 3: Review + Launch

**Left area:**
- "Campaign Configuration" card: 4 checklist items with green checks (Channel, Name, Content, Contacts)
- "Cost & Resource Allocation" card: Total Contacts (big), Cost Per Channel, Total Cost, Available Credits (green if sufficient)

**Right area:**
- Phone preview LIVE with real message content
- "Smartphone Preview Mode" toggle label
- Single CTA button: "Launch Campaign Now" (full width, gradient, large)
- "Save as Draft" secondary link

### Channel Selection (Step 0)

- Title: "Select Campaign Channel" (Manrope h1)
- Subtitle: "NEW CAMPAIGN WORKFLOW" breadcrumb badge
- 2x2 grid of channel cards (larger than current)
- Each card: colored icon square (centered), channel name (bold), description, status badge (Active Channel / Setup Required / Pending)
- Footer: info note + Cancel + "Next Step" button

### Voice Campaign (configure page)

Follows the Step 1 layout for voice but as a standalone page at `/campaigns/create?channel=voice`:
- Left: Selected script preview + Voice Library cards (Rachel, Antoni, Marcus, Bella) with play buttons + character/duration/cost metrics + "Generate Audio" button + audio player with waveform + "Regenerate" / "Use this one" buttons
- Right: Generated Script Variations with badges (Professional Formal, Casual & Friendly, Direct Action) + "Use Variation" / "Selected" buttons + Generation History (collapsible sessions)

### Email Campaign (content page)

Follows the Step 1 layout for email:
- Top: "Email Content Generation" header + "Generated" / "Manual Editor" toggle
- Left (compact briefing): Subject line + product/audience/benefit/CTA/tone/variations + "Generate Email Variations" button + "Generation Tip" card
- Right: Variation cards with subject + body preview + "Accepted" / "Use Full Draft" badges + View Full Draft links
- Bottom: "Live Preview" with full email rendered in card (header, body with personalization, CTA button, footer, signature)

---

## Layer 3: Non-Wireframed Pages

All other pages inherit the foundation. Specific adjustments:

### Conversations
- List sidebar: `#161a33` bg, no borders, items separated by spacing
- Chat area: `#0d112a` bg, message bubbles as glass cards (outbound) / `#2f334e` (inbound)
- Info panel: `#1a1e37` bg, glass cards for contact data

### Funnels
- Table: no borders, hover bg shift
- Editor canvas: `#080c25` (deepest), nodes as glass cards, edges as blue lines
- Toolbar/Properties: `#161a33`

### Chatbot Settings
- Form cards: `#242842` bg, inputs `#2f334e`
- Status badges updated to pill shape

### Reports
- Filter bar: dark inputs `#2f334e`
- Tables: no borders, hover bg shift
- KPI summaries: glass cards, numbers in Manrope

### Profile
- Two cards side by side, `#242842` bg
- Inputs `#2f334e` with glow focus

### Plans
- Cards grid with glass effect, recommended plan with gradient border
- Price in Manrope display

### Admin (all settings pages)
- Same input/card patterns
- Test/Save buttons with gradient primary

### Auth (Login/Register/Reset)
- Left panel: gradient navy background with branding
- Right panel: form on dark `#1a1e37` background
- Inputs `#2f334e`, button gradient primary

---

## Structural Changes

### Wizard Restructure

Current 5-step → New 3-step:

| Old | New |
|-----|-----|
| Step 1 (Name) + Step 2 (Content) | **Step 1: Identity + Content** |
| Step 3 (Contacts) + Step 4 (Schedule) | **Step 2: Audience + Timing** |
| Step 5 (Review) | **Step 3: Review + Launch** |

The `Create.vue` component changes from `currentStep 1-5` to `currentStep 1-3`, with each step containing more content.

### Icon System

Wireframes use Material Symbols Outlined. Two options:
- **Option A:** Add Material Symbols alongside Tabler Icons (use for sidebar/navigation)
- **Option B (recommended):** Keep Tabler Icons everywhere for consistency, just update icon choices

### Default Theme

Dark mode becomes the default. Light mode remains available as toggle but is the "alternative" experience.

---

## Implementation Order

1. **Foundation:** theme.css + fonts + AppLayout + AppSidebar + AppTopbar
2. **Dashboard:** full redesign with new KPIs, chart, table
3. **Campaign wizard:** restructure to 3 steps + per-channel content
4. **Channel selection:** redesign cards
5. **Contacts:** segments sidebar + new table + footer KPIs
6. **Remaining pages:** apply foundation patterns (conversations, funnels, chatbot, reports, profile, plans, admin, auth)
