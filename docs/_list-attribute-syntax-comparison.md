# List Item Attribute Syntax Comparison

This document compares two proposed syntaxes for adding attributes to list items in Djot.

---

## Issue #250 Inline Syntax (Proposed, NOT implemented)

```djot
-{.priority-high .assigned-john #task-001 data-sprint="2024-Q1" data-estimate="3d"} Implement user authentication
-{.priority-high .assigned-jane #task-002 data-sprint="2024-Q1" data-estimate="2d" data-blocked-by="task-001"} Add password reset flow
-{.priority-medium .assigned-john #task-003 data-sprint="2024-Q1" data-estimate="1d"} Write API documentation
-{.priority-low .assigned-jane #task-004 data-sprint="2024-Q2" data-estimate="4h"} Update footer copyright

- [x]{.done .verified data-completed="2024-01-15" data-reviewed-by="mike"} Setup project repository
- [x]{.done .verified data-completed="2024-01-16" data-reviewed-by="sarah"} Configure CI/CD pipeline
- [x]{.done data-completed="2024-01-17"} Create database schema
```

**Problems:**
- Content buried after long attribute strings
- List markers (`-`, `- [x]`) get lost in noise
- Hard to scan what tasks actually are
- No visual alignment of content

---

## PR #262 Next-Line Syntax (Our implementation)

```djot
- Implement user authentication
  {.priority-high .assigned-john #task-001 data-sprint="2024-Q1" data-estimate="3d"}
- Add password reset flow
  {.priority-high .assigned-jane #task-002 data-sprint="2024-Q1" data-estimate="2d" data-blocked-by="task-001"}
- Write API documentation
  {.priority-medium .assigned-john #task-003 data-sprint="2024-Q1" data-estimate="1d"}
- Update footer copyright
  {.priority-low .assigned-jane #task-004 data-sprint="2024-Q2" data-estimate="4h"}

- [x] Setup project repository
  {.done .verified data-completed="2024-01-15" data-reviewed-by="mike"}
- [x] Configure CI/CD pipeline
  {.done .verified data-completed="2024-01-16" data-reviewed-by="sarah"}
- [x] Create database schema
  {.done data-completed="2024-01-17"}
```

**Benefits:**
- Content is immediately visible
- List markers stand out clearly
- Attributes are optional "metadata" below
- Easy to scan the actual list items
- Task lists (`- [x]`) remain readable

---

## Side-by-Side: Which can you read faster?

### Inline (Issue #250)

```
-{.urgent .bug #issue-42 data-reported="2024-01-10"} Fix login crash on Safari
-{.feature .p2 #issue-43 data-milestone="v2.0"} Add dark mode support
-{.docs #issue-44} Update README with new API endpoints
```

### Next-Line (Our Implementation)

```
- Fix login crash on Safari
  {.urgent .bug #issue-42 data-reported="2024-01-10"}
- Add dark mode support
  {.feature .p2 #issue-43 data-milestone="v2.0"}
- Update README with new API endpoints
  {.docs #issue-44}
```

---

## References

- [jgm/djot#250](https://github.com/jgm/djot/issues/250) - Original inline syntax proposal
- [jgm/djot#262](https://github.com/jgm/djot/pull/262) - Next-line syntax PR (what we implemented)
