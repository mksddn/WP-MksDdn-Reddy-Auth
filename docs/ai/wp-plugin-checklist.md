# WP Plugin AI Checklist

Operational checklist for AI agents working on this WordPress plugin.

## Pre-task

- Requirement is clear and scoped to concrete files/functions.
- Target path is correct:
  - plugin runtime files -> `mksddn-reddy-auth/trunk`,
  - AI docs/config -> repository root (`AGENTS.md`, `.cursor/*`, `docs/ai/*`).
- Impacted public contracts are identified:
  - hooks (`do_action` / `apply_filters`),
  - option keys,
  - DB schema/data format,
  - user-facing flows.
- Minimal implementation approach is selected (no speculative refactor).
- Existing project rules were reviewed (`AGENTS.md`, `.cursor/rules/*`).

## In-task

### Security

- Capability checks are present for privileged operations.
- Nonce verification exists for state-changing requests.
- External input is validated and sanitized before use.
- Dynamic output is escaped in the right context.
- SQL dynamic values use `$wpdb->prepare()`.

### Compatibility

- Existing hooks, option keys, and data contracts are preserved.
- If migration is needed, versioned migration path is implemented.
- Uninstall behavior is safe and removes only plugin-owned data.

### Code Quality

- Code stays focused and readable.
- Duplication is minimized without over-abstraction.
- New strings are internationalized with plugin text domain.
- File and symbol names are consistent and descriptive.

### Performance

- No unnecessary DB queries in loops.
- No heavy logic in frequently triggered hooks without guards/caching.
- Asset loading is limited to required screens/contexts.

## Pre-finish

- Task goal is fully implemented.
- Happy path is checked.
- One negative path is checked (permission/nonce/invalid input).
- Changed files were reviewed for unintended edits.
- WPCS verification is done (`phpcs`) when available.
- Final report includes:
  - changed files,
  - verification done,
  - residual risk or skipped checks.

## Reference Sources

- Context7: `/kasparsd/wp-docs-md`
- Context7: `/wordpress/wpcs-docs`
