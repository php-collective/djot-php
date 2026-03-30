# Non-Mutating `beforeRender()` Plan

This document outlines the preferred long-term direction for extensions that
currently mutate the AST during `DjotConverter::render()`.

## Problem

Some extensions use a `beforeRender(Document $document)` hook to rewrite the AST
just before rendering. This is convenient, but it has two downsides:

1. It can mutate caller-owned ASTs when the same `Document` is rendered more
   than once.
2. Avoiding that mutation by cloning the entire tree adds measurable render
   overhead, especially for `parse()` + repeated `render()` workflows.

The current mitigation is `MutatesDocumentBeforeRenderInterface`, which tells
`DjotConverter` to clone the document only when a mutating extension is present.
That keeps the common path fast, but cloning is still a cost for those
extensions.

## Goal

Move extensions away from AST mutation during `render()` so that:

- `render(Document $document)` is idempotent
- caller-owned ASTs are not modified by rendering
- the converter does not need to clone the tree for correctness

## Preferred Direction

Use one of these two patterns instead of mutating `beforeRender()` hooks:

### 1. Render-Time Behavior

If the change is presentation-only, implement it in renderer events or renderer
customization APIs.

Examples:

- `HeadingLevelShiftExtension`
  Render headings at a shifted level without calling `Heading::setLevel()`.
- Non-structural wrappers, labels, or presentation attributes
  Apply them while rendering the affected node.

This is the preferred path for changes that only affect output.

### 2. Explicit AST Transforms

If the feature genuinely needs an altered tree, expose that as an explicit,
caller-visible transform step instead of hiding it inside `render()`.

Examples:

```php
$document = $converter->parse($djot);
$document = $someTransformer->transform($document);
$html = $converter->render($document);
```

or

```php
$transformed = $converter->transform($document, [
    new HeadingLevelShiftTransform(1),
]);
```

This keeps ownership and lifecycle clear: transforms produce a new tree, and
rendering consumes a tree.

## Migration Strategy

### Phase 1

Keep `MutatesDocumentBeforeRenderInterface` as a compatibility layer.

- Existing mutating extensions remain correct.
- `DjotConverter` clones only when such an extension is registered.

### Phase 2

Convert built-in mutating extensions one by one.

Current candidates:

- `HeadingLevelShiftExtension`
  Best fit for render-time behavior.
- `InlineFootnotesExtension` on non-HTML renderers
  Could move to renderer-specific handling or an explicit transform.

After each conversion:

- remove `MutatesDocumentBeforeRenderInterface` from that extension
- add regression coverage proving repeated `render()` calls are stable

### Phase 3

Once no built-in extensions rely on mutating `beforeRender()` hooks:

- de-emphasize `beforeRender()` in extension authoring docs
- recommend renderer events or explicit transforms as the default extension model
- consider deprecating mutating `beforeRender()` usage over time

## Guidance For Extension Authors

Use `beforeRender()` only when you truly need document preprocessing.

If your extension mutates the `Document` in `beforeRender()` today:

- implement `MutatesDocumentBeforeRenderInterface`
- document that the hook rewrites the tree
- prefer a refactor toward render-time behavior or an explicit transform when practical

## Success Criteria

This plan is complete when:

- repeated `render()` calls on the same parsed `Document` are stable by design
- built-in extensions no longer need cloning for correctness
- cloning becomes an opt-in compatibility path rather than the main safety mechanism
