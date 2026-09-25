# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (single-file, no build system, no tests) that generates a MailChimp email campaign summarizing upcoming events on https://moylgrove.wales. All logic lives in `gigio-mailshot.php`; there is no composer.json, package.json, or test suite. Changes are verified by loading the plugin in a live WordPress install (this repo lives at `wp-content/plugins/gigio-mailshot` under a UniServer/XAMPP-style stack) and exercising the shortcode/admin page in a browser.

## Required local file (not in git)

`mailchimp-keys.php` is gitignored and must exist locally for the plugin to load (it's `include`d unconditionally at the top of `gigio-mailshot.php`). It defines `class MailChimpKeys` with `MailChimpKey`, `MailChimpAuthorization`, `MailChimpUrl`, `FolderID` constants — see README.md for the exact shape and how to derive `MailChimpAuthorization` via curl. `MailChimpEmail extends MailChimpKeys` to get these as `self::` constants.

## Plugin dependency

This plugin sources its event data from the sibling `gigiau-events-posters` plugin (`../gigiau-events-posters/gigio.php`), declared via the `Requires Plugins: gigiau-events-posters` header. It does **not** query WordPress directly for events — `gigio_mailshot_get_upcoming_events()` calls that plugin's own `gigio_get_gigs_with_recurs()` (approval-gated post IDs, category `gig`/`GIGIO_CATEGORY`) and `gigio_get_gigs()` (full details, recurrence-adjusted `dtstart`) global functions, since both plugins run in the same WordPress process. Field mapping: `venue` → `subtitle`, `dtinfo` → `price`, presence of a non-empty `bookinglink` meta → the "Booking essential" badge; text fields are decoded with `gigio_decode_text()` (that plugin stores `title`/`venue`/`dtinfo` as numeric HTML entities). Events are capped to a 3-month lookahead window, same as before. If `gigiau-events-posters` isn't active, `gigio_mailshot_get_upcoming_events()` logs and returns an empty list rather than fataling.

## Architecture

Everything is in `gigio-mailshot.php`, organized into clearly marked sections (search for the `/**** SECTION ****/` comments):

- **`MailChimpEmail` class** — thin wrapper over the MailChimp v3 API (`mailChimpApi` is the single low-level request method, using `wp_remote_get`/`wp_remote_request`). The constructor creates a new draft campaign every time it's instantiated; `setContent`, `test`, `send` act on that campaign's ID. `clearCampaigns` deletes all unsent drafts in the plugin's MailChimp folder.
- **Event query** — `gigio_mailshot_get_upcoming_events()` delegates to the `gigiau-events-posters` plugin (see "Plugin dependency" below) for events starting within the next 3 months, sorted by start date. `shortContent()` strips shortcodes/tags from post content and truncates it for the email body (per commit `2f73bfa`, shortcodes are deliberately stripped rather than rendered — e.g. booking forms should not appear inline).
- **HTML rendering** — `eventsToHtml()` builds the actual email HTML (inline `<style>`, per-event blocks). A `booking` flag (non-empty `bookinglink` meta) shows a "Booking essential" badge.
- **Shortcode `[gigio-mailshot]`** (`gigio_mailshot()`) — renders a preview of the email plus action buttons when viewed by a logged-in user who can `edit_posts`. URL query params drive one-shot actions on page load: `?ping=1`, `?test=1`, `?send=<dayOfYear>` (the day-of-year code in the URL guards against accidental resend via browser back/refresh — see `history.replaceState` call and `a49185f`).
- **Cron** — `gigio_mailshot_cron()` (hooked to `gigio_mailshot_cron_hook`) sends the full broadcast on schedule. `gigio_mailshot_set_cron($reset, $soon, $in_a_month)` computes/clears the next scheduled run (first Monday of next month at 2:14am, by default); sending a broadcast manually reschedules cron to `+1 month` (see `gigio_mailshot_ajax`, case `"send"`). Per `a49185f`, cron defaults to a real broadcast, not a test send.
- **Admin page** (`Settings > Gigio Mailshot`, `manage_options` capability) — `gigio_mailshot_admin_page()` renders buttons (Clear drafts / Ping / Test / Broadcast / Toggle automatic) that POST to `wp_ajax_gigio_mailshot_ajax` → `gigio_mailshot_ajax()`, which dispatches on `$_POST['go']` (`clear`, `ping`, `test`, `send`, `setcron`) and is guarded by a per-page-load nonce stored in the `gigio_mailshot_nonce` option.
- **Lifecycle hooks** — `register_activation_hook` schedules cron to run "tomorrow"; `register_deactivation_hook` clears the scheduled cron and the nonce option.

## Conventions to preserve

- All MailChimp API calls funnel through `MailChimpEmail::mailChimpApi()`; add new API interactions there rather than calling `wp_remote_*` directly elsewhere.
- Error/debug output goes through `error_log()` (commented-out `error_log` calls throughout are intentional — left as ready-to-enable debug traces, not dead code to clean up).
- Every AJAX/shortcode action returns/echoes `["status" => ..., "body" => ...]`-shaped arrays for consistent client-side handling in the admin page's `sendAjax()` JS.
