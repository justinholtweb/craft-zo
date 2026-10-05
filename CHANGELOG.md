# Release Notes for Zo

## 5.0.1 - 2026-10-04

> {warning} Zo now stores the Zoho refresh token in its own database table, encrypted, instead of in its settings — which are project config, committed with your site — and each environment connects for itself. Upgrading copies an existing token across so syncing keeps working; then connect once on each environment that syncs, open **Settings → Zo**, tick **Remove it from project config** and save. If the repository has been shared, disconnect first, which revokes the old token. Previewing an order's payload now also needs permission to view that order, and clearing the log is admin-only.

### Security

- **The refresh token was stored in project config.** Connecting saved it as a plugin setting, so it went into `config/project/project.yaml` and from there into git — read and write access to the merchant's books for anyone with the repository. It now lives in a `zo_connection` table, encrypted with the site's security key, along with the data centre Zoho reported and the organization picked automatically. The **Refresh token** setting remains as an `$ENV` override (`$ZOHO_REFRESH_TOKEN`); a literal token is refused there. A token stored by 5.0.0 keeps working, is never shown, and the settings screen asks for it to be removed. **Disconnect** revokes both.
- **"View what has been synced" could read any order's payload** — the customer's name, address, email and every line — through the preview endpoint, without permission to view orders. It now also needs permission to view that order.
- **"View the log" could clear it.** Clearing the log, the audit trail of every accounting sync, is now admin-only, and the button is only shown to admins.

### Fixed

- **Connecting was refused wherever admin changes were off** — normally the live site — so the token had to travel to production through git. Connect, the callback and Disconnect now need an admin but not admin changes, and write nothing to project config. `php craft zo/auth/disconnect` does what the Disconnect button does where the settings screen is read-only.
- **The organization was never picked automatically, and "Look up organizations" always failed until one was set.** An access token required a configured organization, and those are the two calls that run before there is one.
- **Every button on the settings screen did nothing** — Test connection, Look up organizations, the tax-rate and account lookups, Preview payload, Disconnect. Craft namespaces a plugin's settings HTML, so the ids became `settings-zo-…` while the script looked for `zo-…`.

### Changed

- PHPStan and ECS configuration, and the findings they raised tidied.

## 5.0.0 - 2026-08-23

Initial release. One edition, $99 with a $79/year renewal — nothing is held back for an upgrade.

### Added
- OAuth 2.0 connection to Zoho Books, across all eight Zoho data centres, with the region taken
  from Zoho's own redirect rather than guessed.
- Commerce customers synced as Zoho contacts, reusing an existing contact where one already
  matches by email or name instead of duplicating it.
- Completed orders synced as Zoho invoices, optionally as sales orders, with the invoice
  promoted out of draft so it counts toward receivables.
- Three ways to handle tax, and a reconciliation check on every document: the total Zoho returns
  is compared against the total the customer actually paid, and any difference is recorded and
  surfaced rather than absorbed.
- Payments and refunds — Commerce payment transactions as Zoho customer payments, refunds
  as credit notes with the cash movement recorded when a deposit account is configured.
- Zoho items created or matched per purchasable, so Zoho's sales-by-item reporting works.
- Queue-driven syncing with exponential backoff, and a batched backfill for stores connecting
  after they have been trading.
- A connection log with credential redaction, and a Sync screen showing what is in the books,
  what failed, and what does not reconcile.
- Console commands: `zo/sync/order`, `zo/sync/backfill`, `zo/sync/retry`, `zo/sync/status`,
  `zo/auth/test`, `zo/auth/refresh`, `zo/log/prune`, `zo/log/clear`.
