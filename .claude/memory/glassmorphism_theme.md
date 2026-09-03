---
name: Glassmorphism + Dark Mode Theme
description: Global frosted glass UI with beam background and film grain overlay
type: project
---

## Design Standard (March 30, 2026)

### Color Theme
- **Default mode**: Dark (`.dark` class on `<html>`)
- **Light mode**: Still supported, auto-detected via appearance preference
- All pages inherit dark mode by default via `app.blade.php`

### Glassmorphism Effect (Global)
Applied to all containers with `.rounded-lg.border` or `.rounded.border` via CSS cascade in `app.css`.

**Light Mode:**
- `backdrop-filter: blur(4px)` + `-webkit-backdrop-filter`
- Background: `rgba(255, 255, 255, 0.4)` (40% opaque white)
- Border: `rgba(228, 228, 231, 0.6)` (60% opaque zinc-200)

**Dark Mode:**
- `backdrop-filter: blur(4px)` + `-webkit-backdrop-filter`
- Background: `rgba(255, 255, 255, 0.05)` (5% opaque white)
- Border: `rgba(113, 113, 122, 0.6)` (60% opaque zinc-700)

### Background Effects (Theme Level)

**1. Animated Beam Grid** — on `[data-slot="sidebar-inset"]`
- Two diagonal hairline families: 35° and −55°
- Opacity: `0.018` / `0.010` (barely visible, accent only)
- Animation: 72s linear infinite, drifts in opposite directions
- Fixed to viewport via `background-attachment: fixed`

**2. Ambient Glow** — on `[data-slot="sidebar-inset"]`
- Radial ellipse at viewport top, 140% × 40%, centered
- Color: `rgba(80, 130, 200, 0.05)` (cool blue tint)
- Static (no animation)

**3. Film Grain Overlay** — on `[data-slot="sidebar-inset"]::after`
- SVG `feTurbulence` fractal noise texture
- `position: fixed`, `z-index: 9999`, `pointer-events: none`
- Opacity: `0.12` (visible but subtle)
- No `mix-blend-mode` (plain opacity is universally visible)

### Implementation Files

| File | Change | Purpose |
|---|---|---|
| `resources/views/app.blade.php` | Dark mode default | `$appearance ?? "dark"` instead of `"system"` |
| `resources/css/app.css` | Global glassmorphism | CSS rules for `.rounded-lg.border`, `thead`, etc. |
| `resources/js/components/ui/sidebar/SidebarInset.vue` | Removed `bg-background` | Let body background show through |
| `resources/js/pages/LogAnalysis/Index.vue` | Optional per-page overrides | Can add `.glass-white`/`.glass-dark` classes if needed |

### Key CSS Selectors

```css
/* Auto-apply glass to all rounded bordered boxes */
html:not(.dark) .rounded-lg.border { backdrop-filter: blur(4px); ... }
html.dark .rounded-lg.border { backdrop-filter: blur(4px); ... }

/* Target both .rounded-lg and .rounded variants */
html.dark .rounded.border { ... }

/* Table headers also get glass treatment */
html.dark thead { backdrop-filter: blur(4px); ... }
```

### Why This Works

1. **Cascade**: CSS rules in `app.css` apply globally to any element matching `.rounded-lg.border` — no per-page markup needed
2. **Beam through glass**: Glassmorphism is semi-transparent, so the animated beam + grain underneath show through all containers
3. **Dark by default**: `app.blade.php` sets `html.dark` at page load, so all dark mode CSS activates immediately
4. **GPU optimized**: `backdrop-filter`, `background-position` animation, and `position: fixed` overlays all use hardware acceleration

### Browser Support
- `backdrop-filter`: Chrome 76+, Firefox 103+, Safari 9+, Edge 17+
- `-webkit-backdrop-filter`: Fallback for older Safari versions
- Degrades gracefully in unsupported browsers (shows solid background instead of blur)

### Future Customization
- Adjust blur amount: change `blur(4px)` to `blur(6px)` or `blur(2px)` globally
- Tweak opacity: modify `rgba(255, 255, 255, 0.05)` to `0.08` or `0.02`
- Beam speed: change `72s` animation to `48s` or `96s` in `@keyframes global-beam-drift`
- Grain intensity: modify `opacity: 0.12` on `[data-slot="sidebar-inset"]::after`
