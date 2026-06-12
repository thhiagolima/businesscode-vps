# Plan A: Design System Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the "Kinetic Data Aesthetic" dark-first visual identity to the entire platform by updating the design system foundation (theme, fonts, layout, sidebar, topbar). All 30+ pages inherit the new look automatically.

**Architecture:** Override Tabler CSS variables and custom `--bc-*` tokens in theme.css. Update font imports, sidebar/topbar templates, and layout structure. Keep Tabler/Bootstrap as the framework — zero dependency changes.

**Tech Stack:** Vue 3, Tabler CSS (Bootstrap 5), Manrope + Inter fonts, CSS custom properties

---

## File Map

### Files to Modify
- `frontend/src/assets/theme.css` — Color palette, fonts, card/input/button/table overrides
- `frontend/src/main.ts` — Add Manrope + Inter font imports
- `frontend/src/components/layout/AppSidebar.vue` — New sidebar design matching wireframe
- `frontend/src/components/layout/AppTopbar.vue` — New topbar with search bar
- `frontend/src/components/layout/AppLayout.vue` — Minor layout adjustments
- `frontend/src/composables/useTheme.ts` — Default to dark theme

### No files to create — all changes are in existing files.

---

## Task 1: Update Font Imports and Theme Variables

**Files:**
- Modify: `frontend/src/assets/theme.css`

- [ ] **Step 1: Replace font import**

Replace line 7 (the `@import url(...)` for DM Sans + JetBrains Mono) with:

```css
@import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap');
```

- [ ] **Step 2: Update :root CSS variables**

Replace the entire `:root { ... }` block (lines 9-41) with:

```css
:root {
  /* ── Kinetic Data Aesthetic — Dark-first palette ── */
  --bc-primary: #0064ff;
  --bc-primary-hover: #0054d8;
  --bc-primary-subtle: rgba(0, 100, 255, 0.08);
  --bc-primary-glow: rgba(0, 100, 255, 0.15);

  /* Surface layers (dark, from deepest to highest) */
  --bc-dark: #080c25;
  --bc-dark-lighter: #0d112a;
  --bc-dark-surface: #161a33;
  --bc-surface-container: #1a1e37;
  --bc-gray: #242842;
  --bc-gray-soft: #2f334e;

  /* Text */
  --bc-text: #dee0ff;
  --bc-text-muted: #8c90a2;
  --bc-white: #ffffff;

  /* Status */
  --bc-danger: #ef4444;
  --bc-success: #10b981;
  --bc-warning: #f59e0b;

  /* Layout */
  --bc-radius: 10px;
  --bc-radius-lg: 14px;
  --bc-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.2);
  --bc-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
  --bc-shadow-lg: 0 12px 48px rgba(0, 0, 0, 0.4);
  --bc-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);

  /* Glass card */
  --bc-glass-bg: rgba(22, 27, 69, 0.6);
  --bc-glass-blur: blur(12px);

  /* Ghost border (accessibility fallback only) */
  --bc-outline: rgba(66, 70, 86, 0.15);

  /* Tabler overrides — dark first */
  --tblr-primary: var(--bc-primary);
  --tblr-primary-rgb: 0, 100, 255;
  --tblr-font-sans-serif: 'Inter', -apple-system, sans-serif;
  --tblr-body-bg: var(--bc-dark-lighter);
  --tblr-body-color: var(--bc-text);
  --tblr-border-color: transparent;
  --tblr-card-border-color: transparent;
  --tblr-card-bg: var(--bc-gray);
  --tblr-secondary-color: var(--bc-text-muted);
}
```

- [ ] **Step 3: Update body styles**

Replace the `body { ... }` block with:

```css
body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  background: var(--bc-dark-lighter);
  color: var(--bc-text);
}
```

- [ ] **Step 4: Update typography section**

Replace the typography rules with:

```css
/* ─── Typography: Manrope (headlines) + Inter (body) ─── */
h1, h2, h3, .h1, .h2, .h3 {
  font-family: 'Manrope', sans-serif;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--bc-text);
}
h4, h5, .h4, .h5 {
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--bc-text);
}
.page-title {
  font-family: 'Manrope', sans-serif;
  font-size: 1.5rem !important;
  font-weight: 800 !important;
  letter-spacing: -0.03em;
}
/* KPI display numbers */
.kpi-value {
  font-family: 'Manrope', sans-serif;
  font-weight: 800;
  font-size: 2.5rem;
  letter-spacing: -0.03em;
  line-height: 1;
}
.kpi-value-lg {
  font-family: 'Manrope', sans-serif;
  font-weight: 800;
  font-size: 3.5rem;
  letter-spacing: -0.04em;
  line-height: 1;
}
code, .badge-mono, .text-mono {
  font-family: 'JetBrains Mono', monospace;
  font-variant-numeric: tabular-nums;
}
```

- [ ] **Step 5: Update card styles — no borders**

Replace the card CSS section with:

```css
/* ─── Cards: No borders, surface layering ─── */
.card {
  border: none !important;
  border-radius: var(--bc-radius-lg) !important;
  background: var(--bc-gray);
  box-shadow: none;
  transition: var(--bc-transition);
  overflow: hidden;
}
.card:hover {
  box-shadow: var(--bc-shadow-sm);
}
.card-header {
  background: transparent;
  border-bottom: none;
  font-weight: 600;
}
.card-footer {
  background: rgba(0, 0, 0, 0.1);
  border-top: none;
}

/* Glass card variant */
.glass-card {
  background: var(--bc-glass-bg) !important;
  backdrop-filter: var(--bc-glass-blur);
  -webkit-backdrop-filter: var(--bc-glass-blur);
}
```

- [ ] **Step 6: Update form styles — dark inputs, glow focus**

Replace the forms CSS section with:

```css
/* ─── Forms: Dark inputs, glow focus ─── */
.form-control, .form-select {
  border-radius: var(--bc-radius);
  border: none;
  background: var(--bc-gray-soft);
  color: var(--bc-text);
  transition: var(--bc-transition);
  font-size: 0.875rem;
}
.form-control:focus, .form-select:focus {
  border: none;
  background: var(--bc-gray-soft);
  color: var(--bc-text);
  box-shadow: 0 0 0 2px rgba(0, 100, 255, 0.2);
  outline: none;
}
.form-label {
  font-weight: 500;
  font-size: 0.82rem;
  color: var(--bc-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.35rem;
}
::placeholder { color: var(--bc-text-muted); opacity: 0.5; }
```

- [ ] **Step 7: Update button styles — gradient primary**

Replace the button CSS with:

```css
/* ─── Buttons: Gradient primary ─── */
.btn {
  border-radius: var(--bc-radius);
  font-weight: 500;
  font-size: 0.85rem;
  letter-spacing: 0.01em;
  transition: var(--bc-transition);
  border: none;
}
.btn-primary {
  background: linear-gradient(135deg, #0064ff, #0054d8) !important;
  border: none !important;
  color: #fff !important;
  box-shadow: 0 2px 8px rgba(0, 100, 255, 0.25);
}
.btn-primary:hover {
  background: linear-gradient(135deg, #0054d8, #003ea6) !important;
  box-shadow: 0 4px 16px rgba(0, 100, 255, 0.35);
  transform: translateY(-1px);
}
.btn-primary:active { transform: translateY(0); }
.btn-secondary, .btn-ghost-secondary {
  background: var(--bc-gray-soft);
  color: var(--bc-text);
  border: 1px solid var(--bc-outline);
}
.btn-ghost-secondary:hover {
  background: var(--bc-primary-subtle);
  color: var(--bc-primary);
}
.btn-danger {
  box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
}
.btn:focus-visible {
  outline: 2px solid var(--bc-primary);
  outline-offset: 2px;
}
```

- [ ] **Step 8: Update table styles — no borders, hover shift**

Replace the table CSS with:

```css
/* ─── Tables: No lines, hover shift ─── */
.table {
  font-size: 0.85rem;
  --tblr-table-color: var(--bc-text);
  --tblr-table-bg: transparent;
  --tblr-table-border-color: transparent;
}
.table th {
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bc-text-muted);
  border-bottom: 1px solid var(--bc-outline) !important;
  border-top: none !important;
}
.table td {
  border-bottom: none !important;
  padding: 0.85rem 0.75rem;
}
.table-clickable tbody tr {
  cursor: pointer;
  transition: background-color 150ms ease;
}
.table-clickable tbody tr:hover {
  background-color: rgba(0, 100, 255, 0.04);
}
```

- [ ] **Step 9: Update badge styles — pill shape**

Replace the badge CSS with:

```css
/* ─── Badges: Pill shape ─── */
.badge {
  font-weight: 500;
  font-size: 0.72rem;
  letter-spacing: 0.02em;
  border-radius: 9999px;
  padding: 0.3em 0.75em;
}
```

- [ ] **Step 10: Update dark mode override and sidebar**

Replace the entire `[data-bs-theme="dark"]` section with:

```css
/* ─── Dark mode is now the default. Light mode is the alternate. ─── */
[data-bs-theme="dark"] {
  --tblr-body-bg: var(--bc-dark-lighter);
  --tblr-body-color: var(--bc-text);
  --tblr-muted: var(--bc-text-muted);
  --tblr-border-color: transparent;
  --tblr-card-border-color: transparent;
  --tblr-card-bg: var(--bc-gray);
}

/* ─── Light mode (alternate) ─── */
[data-bs-theme="light"] {
  --bc-dark-lighter: #f5f6fa;
  --bc-dark-surface: #ecedf3;
  --bc-surface-container: #e8e9f0;
  --bc-gray: #ffffff;
  --bc-gray-soft: #f0f1f7;
  --bc-text: #1a1d2e;
  --bc-text-muted: #525974;
  --tblr-body-bg: #f5f6fa;
  --tblr-body-color: #1a1d2e;
  --tblr-card-bg: #ffffff;
  --tblr-card-border-color: rgba(0,0,0,0.06);
  --tblr-border-color: rgba(0,0,0,0.06);
}
[data-bs-theme="light"] .form-control,
[data-bs-theme="light"] .form-select {
  background: #fff;
  border: 1px solid rgba(0,0,0,0.1);
  color: #1a1d2e;
}
[data-bs-theme="light"] .card {
  border: 1px solid rgba(0,0,0,0.06) !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
```

- [ ] **Step 11: Update page layout, sidebar, scrollbar, modals, selection**

Replace the remaining sections (page layout, sidebar vertical, modals, scrollbar, selection) with:

```css
/* ─── Sidebar (vertical navbar) ─── */
.navbar-vertical,
.navbar-vertical.navbar-dark {
  background: var(--bc-dark-surface) !important;
  border-right: none;
  box-shadow: none;
}
.navbar-vertical .nav-link {
  border-radius: var(--bc-radius) !important;
  margin: 2px 8px;
  padding: 0.5rem 0.75rem !important;
  transition: var(--bc-transition);
  border-left: none;
}
.navbar-vertical .nav-link-title {
  color: var(--bc-text-muted) !important;
  font-size: 0.84rem;
  font-weight: 500;
}
.navbar-vertical .nav-link-icon {
  color: rgba(255, 255, 255, 0.25) !important;
  transition: var(--bc-transition);
}
.navbar-vertical .nav-link:hover {
  background: var(--bc-primary-subtle) !important;
}
.navbar-vertical .nav-link:hover .nav-link-icon { color: var(--bc-primary) !important; }
.navbar-vertical .nav-link:hover .nav-link-title { color: var(--bc-white) !important; }
.navbar-vertical .nav-link.active {
  background: linear-gradient(135deg, rgba(0,100,255,0.15), rgba(0,100,255,0.08)) !important;
  border-left: none;
}
.navbar-vertical .nav-link.active .nav-link-icon { color: var(--bc-primary) !important; }
.navbar-vertical .nav-link.active .nav-link-title { color: var(--bc-white) !important; font-weight: 600; }
.navbar-vertical .dropdown-menu {
  background: var(--bc-gray);
  border: none;
  border-radius: var(--bc-radius);
  box-shadow: var(--bc-shadow-lg);
}
.navbar-vertical .dropdown-item { color: var(--bc-text-muted); border-radius: 6px; margin: 2px 4px; }
.navbar-vertical .dropdown-item:hover { background: var(--bc-primary-subtle); color: var(--bc-white); }
.navbar-vertical .dropdown-item.active { background: var(--bc-primary); color: #fff; }

/* ─── Page Layout ─── */
.page-body { background: var(--bc-dark-lighter); padding-top: 1.5rem; }
.page-header { background: transparent; border-bottom: none; box-shadow: none; }

/* ─── Modals ─── */
.modal-content { border-radius: var(--bc-radius-lg) !important; border: none; background: var(--bc-gray); box-shadow: var(--bc-shadow-lg); color: var(--bc-text); }
.modal-header { border-bottom: none; }
.modal-footer { border-top: none; }

/* ─── Scrollbar ─── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--bc-gray-soft); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--bc-text-muted); }

/* ─── Selection ─── */
::selection { background: rgba(0, 100, 255, 0.2); color: var(--bc-text); }

/* ─── Alerts ─── */
.alert { border-radius: var(--bc-radius); font-size: 0.85rem; border: none; }

/* ─── Nav tabs ─── */
.nav-tabs { border-bottom-color: var(--bc-outline); }
.nav-tabs .nav-link { color: var(--bc-text-muted); border: none; }
.nav-tabs .nav-link.active { font-weight: 600; color: var(--bc-primary); background: transparent; border-bottom: 2px solid var(--bc-primary); }
```

- [ ] **Step 12: Keep animation tokens and utility classes unchanged**

The animation tokens, gap utilities, icon containers, .card-interactive, and reduced motion sections should remain as-is. No changes needed.

- [ ] **Step 13: Build and verify**

Run: `cd frontend && npx vite build`
Expected: Build succeeds. Open in browser — entire app should now have dark navy background, cards without borders, new fonts.

- [ ] **Step 14: Commit**

```bash
git add frontend/src/assets/theme.css
git commit -m "feat: Kinetic Data Aesthetic — new design system foundation (palette, fonts, cards, inputs, tables)"
```

---

## Task 2: Update Topbar

**Files:**
- Modify: `frontend/src/components/layout/AppTopbar.vue`

- [ ] **Step 1: Rewrite topbar template**

Replace the entire template with:

```html
<template>
  <header class="bc-topbar">
    <div class="container-xl d-flex align-items-center gap-3">
      <!-- Mobile hamburger -->
      <button class="navbar-toggler d-lg-none border-0 p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobile-drawer" aria-label="Menu">
        <i class="ti ti-menu-2" style="font-size:1.2rem;color:var(--bc-text)"></i>
      </button>

      <!-- Search bar -->
      <div class="flex-fill" style="max-width:480px">
        <div class="position-relative">
          <i class="ti ti-search position-absolute" style="left:12px;top:50%;transform:translateY(-50%);color:var(--bc-text-muted);font-size:0.9rem"></i>
          <input type="text" class="form-control" placeholder="Search campaigns, data, or settings..." style="padding-left:36px;background:var(--bc-gray-soft);border:none;border-radius:9999px;height:38px;font-size:0.82rem">
        </div>
      </div>

      <!-- Right side -->
      <div class="d-flex align-items-center gap-3 ms-auto">
        <!-- Dark mode toggle -->
        <button class="bc-topbar-btn" @click="toggleTheme" :title="theme === 'dark' ? 'Modo claro' : 'Modo escuro'">
          <i :class="theme === 'dark' ? 'ti ti-sun' : 'ti ti-moon'" style="font-size:1rem"></i>
        </button>

        <!-- Notifications placeholder -->
        <button class="bc-topbar-btn">
          <i class="ti ti-bell" style="font-size:1rem"></i>
        </button>

        <!-- User -->
        <div class="nav-item dropdown">
          <a href="#" class="d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown">
            <span class="avatar avatar-sm" style="background:linear-gradient(135deg,#0064ff,#0054d8);color:#fff;font-size:0.7rem;font-weight:600;width:34px;height:34px;border-radius:10px">{{ userInitials }}</span>
            <div class="d-none d-xl-block">
              <div style="font-weight:600;font-size:0.82rem;color:var(--bc-text)">{{ userName }}</div>
              <div style="font-size:0.68rem;color:var(--bc-text-muted)">{{ userRole }}</div>
            </div>
          </a>
          <div class="dropdown-menu dropdown-menu-end" style="min-width:200px;background:var(--bc-gray);border:none;border-radius:var(--bc-radius-lg)">
            <a class="dropdown-item" @click.prevent="$router.push('/profile')"><i class="ti ti-user me-2"></i> Meu perfil</a>
            <a class="dropdown-item" @click.prevent="goSettings"><i class="ti ti-settings me-2"></i> Configurações</a>
            <div class="dropdown-divider" style="border-color:var(--bc-outline)"></div>
            <a class="dropdown-item text-danger" @click.prevent="logout"><i class="ti ti-logout me-2"></i> Sair</a>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
```

- [ ] **Step 2: Update script to include theme toggle**

Add theme import and role computed:

```typescript
import { useTheme } from '@/composables/useTheme'

const { theme, toggle: toggleTheme } = useTheme()

const userRole = computed(() => {
  const role = auth.user?.role
  return role === 'superadmin' ? 'Administrator' : 'Admin Access'
})
```

Remove the old `creditsLabel` computed (credits now only in sidebar).

- [ ] **Step 3: Update scoped styles**

Replace the style block with:

```css
.bc-topbar {
  background: transparent;
  border-bottom: none;
  padding: 0.75rem 0;
  position: sticky;
  top: 0;
  z-index: 1030;
}
.bc-topbar-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: none;
  background: var(--bc-gray-soft);
  color: var(--bc-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}
.bc-topbar-btn:hover {
  background: var(--bc-primary-subtle);
  color: var(--bc-primary);
}
@media (max-width: 991px) {
  .bc-topbar { padding: 0.5rem 0; }
}
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/layout/AppTopbar.vue
git commit -m "feat: redesign topbar — search bar, dark mode toggle, gradient avatar"
```

---

## Task 3: Update Sidebar

**Files:**
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Update sidebar branding**

Replace the navbar-brand section (lines 3-13) with:

```html
<div class="px-3 mb-3" style="padding-top:0.75rem">
  <a class="d-flex align-items-center gap-2 text-decoration-none" href="/" @click.prevent="go('/dashboard')">
    <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#0064ff,#0054d8);display:flex;align-items:center;justify-content:center">
      <i class="ti ti-terminal" style="color:#fff;font-size:0.9rem"></i>
    </div>
    <div>
      <div class="text-white" style="font-family:'Manrope',sans-serif;font-weight:800;font-size:1.05rem;letter-spacing:-0.02em;line-height:1.1">BusinessCode</div>
      <div style="font-size:0.6rem;text-transform:uppercase;letter-spacing:0.2em;color:var(--bc-text-muted);font-weight:600;line-height:1">Marketing Suite</div>
    </div>
  </a>
  <button class="btn-close btn-close-white d-lg-none position-absolute" type="button" data-bs-dismiss="offcanvas" aria-label="Fechar" style="top:1rem;right:1rem"></button>
</div>
```

- [ ] **Step 2: Remove the "Sistema" divider border**

Replace the system divider (lines 71-75) with:

```html
<li class="nav-item" style="padding:1rem 1rem 0.5rem">
  <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.15em;color:var(--bc-text-muted);opacity:0.5">Sistema</span>
</li>
```

No `border-top`, just spacing and typography.

- [ ] **Step 3: Update bottom section (credits + user)**

Replace the `mt-auto` bottom section with:

```html
<div class="mt-auto px-3 pb-3">
  <!-- Settings link -->
  <a class="nav-link mb-2" :class="{ active: isActive('/settings/plans') }" @click.prevent="go('/settings/plans')" role="link" style="margin:2px 0;border-radius:var(--bc-radius)">
    <span class="nav-link-icon"><i class="ti ti-settings"></i></span>
    <span class="nav-link-title">Settings</span>
  </a>

  <!-- Credits -->
  <div class="d-flex align-items-center justify-content-between mb-3" style="padding:0.5rem 0.75rem;background:var(--bc-surface-container);border-radius:var(--bc-radius)">
    <span class="d-flex align-items-center gap-2" style="font-size:0.75rem;color:var(--bc-text-muted)">
      <i class="ti ti-bolt" style="color:var(--bc-primary)"></i>
      <span style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--bc-text)">{{ creditsLabel }}</span>
      <span>créditos</span>
    </span>
  </div>

  <!-- User -->
  <a href="#" @click.prevent="go('/profile')" class="d-flex align-items-center gap-2 text-decoration-none bc-user-link">
    <span class="avatar avatar-sm" style="background:linear-gradient(135deg,#0064ff,#0054d8);color:#fff;font-weight:700;font-size:0.65rem;width:32px;height:32px;border-radius:10px">{{ userInitials }}</span>
    <div class="flex-fill" style="min-width:0">
      <div class="text-white text-truncate" style="font-size:0.82rem;font-weight:600;line-height:1.2">{{ userLabel }}</div>
      <div class="text-truncate" style="font-size:0.68rem;color:var(--bc-text-muted);line-height:1.2">{{ auth.user?.email }}</div>
    </div>
  </a>
</div>
```

- [ ] **Step 4: Remove theme toggle from sidebar**

The theme toggle was moved to the topbar. Remove the `toggleTheme` button and the `useTheme` import from the sidebar script. Keep the `useTheme` import only if needed for something else — if not, remove it entirely.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: redesign sidebar — gradient logo, no borders, settings link, clean credits"
```

---

## Task 4: Update Layout and Theme Default

**Files:**
- Modify: `frontend/src/components/layout/AppLayout.vue`
- Modify: `frontend/src/composables/useTheme.ts`

- [ ] **Step 1: Update AppLayout**

In AppLayout.vue, update the footer to match the new palette:

```html
<footer class="footer footer-transparent d-print-none">
  <div class="container-xl">
    <div class="text-center py-2" style="display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap">
      <span style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.5">© {{ new Date().getFullYear() }} BusinessCode®</span>
      <a href="/terms" style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.5;text-decoration:none">Termos</a>
      <a href="/privacy" style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.5;text-decoration:none">Privacidade</a>
    </div>
  </div>
</footer>
```

Also update the offcanvas drawer background:

```html
<div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="mobile-drawer" data-bs-theme="dark"
     style="width:280px;background:var(--bc-dark-surface);overflow-y:auto;padding-bottom:env(safe-area-inset-bottom,0)">
```

- [ ] **Step 2: Set dark as default theme**

In `useTheme.ts`, change the default from fallback to always-dark for new users:

```typescript
const theme = ref<'light' | 'dark'>(
  (localStorage.getItem('theme') as 'light' | 'dark') || 'dark'
)
```

This is already `'dark'` as default — verify it is. No change needed if already correct.

- [ ] **Step 3: Build and full verification**

Run: `cd frontend && npx vite build`
Expected: Builds. Open browser — dark navy aesthetic across entire app.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/layout/AppLayout.vue frontend/src/composables/useTheme.ts
git commit -m "feat: update layout + set dark as default theme"
```

---

## Summary

After these 4 tasks, **every page in the system** will have:
- Dark navy background (`#0d112a`)
- Cards without borders (`#242842` bg)
- Manrope headlines + Inter body text
- Dark inputs (`#2f334e`) with blue glow focus
- Gradient primary buttons
- Pill-shaped badges
- Tables without horizontal lines
- Redesigned sidebar (gradient logo, no borders, clean)
- Redesigned topbar (search bar, dark mode toggle, gradient avatar)

The subsequent plans (B through E) will handle page-specific layouts (dashboard KPIs, wizard restructure, contacts segments, etc.).
