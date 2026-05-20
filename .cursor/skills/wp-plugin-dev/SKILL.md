# WordPress Plugin Development Skill

Use this skill for any implementation or refactor task in this plugin repository.

## Goal

Deliver safe, minimal, and maintainable WordPress plugin changes with predictable quality checks.

## Path Rules

- Write plugin runtime code only in `mksddn-reddy-auth/trunk`.
- Keep AI documentation in root-level documentation/config locations.

## Execution Flow

1. Understand scope:
   - identify exact user requirement,
   - inspect only relevant files,
   - list affected hooks/options/routes/data.
2. Design minimal change:
   - prefer existing patterns in project,
   - avoid unnecessary abstractions,
   - keep backward compatibility.
3. Implement:
   - add capability and nonce checks for privileged actions,
   - sanitize input on write, escape on render,
   - use WordPress APIs first.
4. Self-review:
   - verify security, i18n, and compatibility constraints,
   - ensure no unrelated changes were made.
5. Verify:
   - at least one success path,
   - at least one failure path (permission/nonce/input).
6. Report:
   - what changed,
   - how verified,
   - residual risks (if any).

## Pattern: Settings Page

- Register setting with `register_setting`.
- Add section/field with `add_settings_section` and `add_settings_field`.
- Validate and sanitize input in setting callback.
- Escape stored value in form output.
- Restrict page access by capability.

## Pattern: AJAX Handler

- Register both `wp_ajax_*` and `wp_ajax_nopriv_*` only if public access is required.
- Validate nonce and capability for protected actions.
- Validate/sanitize request payload.
- Return consistent JSON via `wp_send_json_success` / `wp_send_json_error`.

## Pattern: Custom Table Update

- Version DB schema with an option key.
- Apply schema changes via `dbDelta`.
- Run migration only when version mismatch is detected.
- Keep migration idempotent.

## Pattern: Uninstall Cleanup

- Use `uninstall.php`.
- Guard file with `WP_UNINSTALL_PLUGIN`.
- Remove only plugin-owned options/meta/tables.
- Consider multisite cleanup via `delete_site_option` where applicable.

## References

- Context7 `/kasparsd/wp-docs-md` (Plugin Handbook)
- Context7 `/wordpress/wpcs-docs` (Coding Standards)
