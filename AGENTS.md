# AGENTS

This file defines how AI agents should work in this repository for WordPress plugin development.

## Mission

Build and maintain a WordPress plugin with predictable behavior, strong security, and backward compatibility.

## Repository Layout

- Plugin runtime files must be created and updated only in `mksddn-reddy-auth/trunk`.
- AI documentation and agent configuration must stay in repository root (for example: `AGENTS.md`, `.cursor/rules`, `.cursor/skills`, `docs/ai`).
- Do not move documentation into the plugin trunk directory.

## Core Principles

- Keep solutions simple and explicit (KISS).
- Avoid duplication when the same logic can be reused (DRY).
- Prefer small focused classes/functions with one responsibility (SOLID mindset).
- Do not over-engineer: implement only what is needed for the current task.
- Preserve existing behavior unless a change is explicitly requested.

## Scope Discipline

- Change only files required for the task.
- Do not refactor unrelated code "for cleanup".
- Do not introduce new dependencies unless required and justified.
- Do not update package/library versions unless explicitly requested.

## WordPress Requirements

- Use hooks (`do_action`, `apply_filters`) as extension points instead of hard-coded branching.
- Verify permissions with `current_user_can()` for admin or privileged actions.
- Protect state-changing requests with nonces (`wp_nonce_field`, `check_admin_referer`, `check_ajax_referer`).
- Sanitize input on write and escape output on render.
- For SQL, use `$wpdb->prepare()` for dynamic values.
- Keep plugin localization-ready: use `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, and a consistent text domain.
- If schema changes are needed, use `dbDelta()` with versioned migration logic.
- For uninstall cleanup, use `uninstall.php` with `WP_UNINSTALL_PLUGIN` guard.

## Security Baseline

- Never trust request data from `$_GET`, `$_POST`, `$_REQUEST`, REST payloads, or AJAX payloads.
- Validate expected data shape before processing.
- Sanitize before persistence; escape as late as possible during output.
- Do not leak sensitive internals in error messages.

## Quality Gates

- Follow WordPress Coding Standards (WPCS).
- Prefer running `phpcs` on changed PHP files before finalizing.
- Apply `phpcbf` only when it is safe and does not alter behavior.
- Keep names clear and consistent with WordPress conventions.

## Testing and Verification

- For every change, define at least one concrete verification path.
- Validate both happy path and one failure/permission path.
- Re-check backward compatibility for public hooks, option keys, DB schema, and user flows.

## Definition of Done

A task is done only when all items are true:

- Requested functionality works as expected.
- Security checks are present (capabilities, nonce, sanitize/escape where applicable).
- Code follows WordPress standards and project rules.
- No unrelated files were changed.
- Verification steps are documented in the final response.

## Source of Truth

- WordPress Plugin Handbook (Context7: `/kasparsd/wp-docs-md`)
- WordPress Coding Standards docs (Context7: `/wordpress/wpcs-docs`)

## Plugin Documentation

- Keep architecture and flow notes in `docs/PLUGIN_ARCHITECTURE.md`.
- Update this file in the same task only when plugin contracts change:
  - REST routes or request/response contracts,
  - option/meta keys or DB schema,
  - public hooks,
  - integrator-visible auth flow behavior.
