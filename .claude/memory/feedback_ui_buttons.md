---
name: UI button styles
description: Destructive/danger buttons use outline style not solid red (variant=destructive)
type: feedback
originSessionId: 04a1b05e-c96b-408e-bc64-68842f56d523
---
Danger buttons use `variant="outline"` with red text/border classes, NOT `variant="destructive"`.

```vue
<!-- CORRECT -->
<Button variant="outline" class="text-red-600 border-red-600 hover:text-red-700 hover:border-red-700 dark:text-red-400 dark:border-red-400 dark:hover:text-red-300">
    Delete
</Button>
```

**Why:** User pointed out "Delete All button should be outlined as bot controller page" — BotControl page uses this pattern consistently.

**How to apply:** Any delete/destructive action button across the portal should follow this outline-red pattern.
