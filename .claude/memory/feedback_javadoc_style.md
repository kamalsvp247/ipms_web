---
name: JavaDoc style
description: How user writes JavaDoc comments in the Java bot — plain text, no HTML tags
type: feedback
originSessionId: 04a1b05e-c96b-408e-bc64-68842f56d523
---
No HTML tags in JavaDoc. Use plain text with bullet dashes for lists.

```java
// WRONG
/**
 * <p>Some description.
 * <ul>
 *   <li>Item one</li>
 * </ul>
 */

// CORRECT
/**
 * Some description.
 * - Item one
 * - Item two
 */
```

**Why:** User explicitly corrected when HTML `<ul>/<li>` tags were used, then again when `<p>/<ol>/<li>` were used.

**How to apply:** All new or edited JavaDoc in `ipms_java/` must use plain text only.
