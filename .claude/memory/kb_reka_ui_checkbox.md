---
name: kb_reka_ui_checkbox
description: Reka UI Checkbox uses modelValue/update:modelValue NOT checked/update:checked — critical for controlled checkboxes in shadcn-vue
metadata:
  type: feedback
---

Reka UI (used by shadcn-vue) `CheckboxRoot` prop is `modelValue` and emits `update:modelValue`. The HTML `checked` attribute and `update:checked` event are silently ignored — the checkbox becomes uncontrolled and handlers never fire.

**Why:** Reka UI diverged from the Radix Vue API. `CheckboxRootProps.modelValue?: boolean | 'indeterminate' | null` and `CheckboxRootEmits: { 'update:modelValue': [value: boolean | 'indeterminate'] }`.

**How to apply:** Always write:
```vue
<Checkbox :model-value="someBoolean" @update:model-value="handler" />
```
Never write `:checked` or `@update:checked` — those do nothing and the bug is invisible (checkbox visually toggles its own uncontrolled state but Vue reactive state never updates).
