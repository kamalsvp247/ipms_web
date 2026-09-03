---
name: Header displays Dhaka time and booking window
description: Persistent header displays current Dhaka time and booking window times in the middle section, visible on all pages
type: project
originSessionId: fc7e7495-dbf1-438d-a002-ade47031d05e
---
The application header (persisted across all pages) displays:
- **Center section**: Current Dhaka time (BDT) + booking window times (e.g., "1:30 PM — 2:30 PM BDT")
- **Visibility**: All pages can see this real-time information

Window times configurable via `window_start_time` and `window_end_time` in the `settings` table.
