# Open Mira 1.5.1 Skills Admin UX Verification

Target: `wp-now` local WordPress on `http://localhost:9401`, PHP 8.2, WordPress 6.9.4.

## Checks

- `01-skills-list.png` — Skills list renders as `wp-list-table`; console errors: 0.
- `02-add-skill-form.png` — Add form renders; CodeMirror initializes; body textarea is not `required`; console errors: 0.
- `03a-codemirror-filled-before-submit.png` — Body entered through CodeMirror while hidden textarea remained empty before submit.
- `03-valid-submit-success.png` — Submit syncs CodeMirror content and creates a Skill; console errors: 0.
- `04-uppercase-id-browser-validation.png` — Uppercase Skill ID matches `:invalid` with `pattern="^[a-z0-9][\-a-z0-9._]{0,79}$"`; console errors: 0.
- `05-empty-body-server-validation.png` — Empty body reaches server-side validation and returns `Skill body is required.`; console errors: 0.
- `06-customize-direct-edit.png` — Built-in `feedback` skill customization redirects directly to the edit form; console errors: 0.
- `07-cancel-returns-list.png` — Cancel returns from edit form to the list; console errors: 0.
- `08-view-skill-preview.png` — View-only skill preview renders with `.openmira-admin-skill-preview`; console errors: 0.

## Regex note

The initially proposed browser pattern `^[a-z0-9][-a-z0-9._]{0,79}$` still throws in Chrome `/v` regex mode. The verified browser-safe form is `^[a-z0-9][\-a-z0-9._]{0,79}$`.
