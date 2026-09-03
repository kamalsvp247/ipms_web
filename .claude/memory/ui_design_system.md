---
name: UI Design System
description: Dense compact design pattern used across all IPMS pages — emerald/blue/red/orange accents, minimal chrome, zinc neutrals
type: feedback
---

Apply this design system to every page in `resources/js/pages/`. Validated by user on LogAnalysis page.

**Why:** User requested consistent dense compact layout with emerald/blue/red/orange accents and minimal chrome across all pages.

**How to apply:** Use the rules below whenever creating or modifying any Vue page or component.

---

## Color Palette

- **Emerald** — success, active/selected state, positive indicators, 2xx status, POST method, response bodies
- **Blue** — info, GET method, request bodies, expanded row backgrounds
- **Orange** — warnings, 429 rate limits, timeouts, medium-speed durations
- **Red** — errors, 4xx/5xx status, 403 blocks, DELETE method, fast-fail indicators
- **Zinc** — all neutrals: borders (`zinc-200/zinc-800`), backgrounds (`zinc-50/zinc-900/zinc-950`), muted text (`zinc-400/zinc-500`)

## Typography Scale

- Labels/headings: `text-[10px] uppercase tracking-widest text-zinc-400`
- Table headers: `text-[10px] uppercase tracking-widest font-semibold text-zinc-400`
- Table data: `text-[11px]` (font-mono for timestamps, codes, IDs)
- Body text: `text-xs` or `text-sm`
- Large stat numbers: `text-xl font-bold leading-none`
- Tabular data: always `tabular-nums`

## Containers & Chrome

- **No Card components** for tables — use raw `div` with `rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950`
- **No shadows** — `shadow-none` everywhere, rely on borders
- **Tight padding**: containers `px-4 py-3`, table cells `py-1.5`, stats `px-3 py-2.5`
- **Dividers**: `divide-y divide-zinc-100 dark:divide-zinc-800/60` for table rows
- **Gaps**: `gap-2` or `gap-3` between sections, `gap-1.5` for button groups

## Tables

- Always include `#` S/N column as first column
- First column: `pl-3 pr-2` — last column: `pl-2 pr-3` — inner columns: `px-2`
- Row height: `py-1.5`
- Header: `bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800`
- Hover: `hover:bg-zinc-50 dark:hover:bg-zinc-900/60`
- S/N cell: `text-[10px] text-zinc-300 dark:text-zinc-600 tabular-nums select-none`
- Footer strip: `pl-3 pr-3 py-1.5 border-t border-zinc-100 dark:border-zinc-800 text-[10px] text-zinc-400 tabular-nums`

## Badges / Pills

- Shape: `rounded px-1.5 py-0.5 text-[10px] font-bold` (not rounded-full)
- 2xx/success: `bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300`
- 429/timeout: `bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300`
- 4xx/403/error: `bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300`
- Info/neutral: `bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300`
- Muted/unknown: `bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400`

## Buttons & Filter Pills

- Active/selected: `bg-emerald-500 text-white border-emerald-500 dark:bg-emerald-600`
- Default: `bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800`
- Shape: `rounded px-2.5 py-1 text-xs font-medium`
- Selected file chips: `border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300`

## Inputs / Search

- `rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500`

## Summary Stat Cards

- Container: `rounded-lg border px-3 py-2.5`
- Label: `text-[10px] uppercase tracking-widest mb-0.5`
- Value: `text-xl font-bold leading-none`
- Neutral card: `border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950`, zinc text
- Orange card (warnings): `border-orange-200 dark:border-orange-900/40 bg-orange-50 dark:bg-orange-950/10`, `text-orange-400` label, `text-orange-600 dark:text-orange-400` value
- Red card (errors): same pattern with red
- Emerald card (success): same pattern with emerald

## Method Colors (HTTP verbs)

- GET: `text-blue-600 dark:text-blue-400`
- POST: `text-emerald-600 dark:text-emerald-400`
- PUT/PATCH: `text-orange-500 dark:text-orange-400`
- DELETE: `text-red-500 dark:text-red-400`

## Expanded / Detail Rows

- Background: `bg-blue-50/60 dark:bg-blue-950/10`
- Request body panel: `bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/40 rounded p-2.5`
- Response body panel: `bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900/40 rounded p-2.5`
- Sub-label: `text-[10px] font-semibold uppercase tracking-widest mb-0.5`

## Dash/Placeholder

- Use `—` (em dash) instead of `-` for empty/null cells

## Duration Bar

- Bar track: `bg-zinc-100 dark:bg-zinc-800 h-1 rounded-full w-8`
- Fast fill: `bg-emerald-400 dark:bg-emerald-500`
- Medium fill: `bg-orange-400 dark:bg-orange-500`
- Slow fill: `bg-red-400 dark:bg-red-500`
