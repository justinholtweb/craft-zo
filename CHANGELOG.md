# Release Notes for Zo

## 5.1.0 - 2026-10-09
### Added

- Failure alerts. Zo now emails the addresses in **Settings → Zo → Alerts** — and can post to a
  Slack or Teams incoming webhook, or a signed JSON one — when orders fail to sync, when a synced
  document does not reconcile, or when Zoho refuses the connection (a revoked refresh token, or a
  401 that refreshing does not fix). One message when it starts, one when it clears, with a quiet
  period for a connection that flaps. Checked after every sync, so no cron is needed. Bodies are
  redacted and link to the Sync screen filtered to the problem. The webhook URL is held to the
  family SSRF rules: public hosts only, the connection pinned to the checked address, no redirects.
- A **Zoho Books health** Dashboard widget: connected or not, synced, failed and not-reconciled
  counts, orders awaiting sync, and any open incident.
- `php craft zo/alerts/check` and `php craft zo/alerts/test`, and an admin-only **Send a test
  alert** button. `zo/sync/retry` now runs the check too.
- A **Zoho Books** column on Commerce's Orders index — synced, not reconciled, failed, pending,
  skipped or not synced — and a matching **Zoho Books status** condition rule, so the Orders index
  can be filtered (or a custom source built) on it.
- A **Sync to Zoho Books** bulk action on the Orders index, for people with *Sync orders to Zoho
  Books*. It queues each selected completed order; documents already in Zoho are reused, never
  resent.
- **Gateway → deposit account**: each Commerce gateway can deposit into (and refund from) its own
  Zoho account — Stripe's clearing account, PayPal's balance — instead of the one deposit account.
- **Record processor fees as bank charges** (off by default). The fee Stripe or PayPal kept is sent
  as the customer payment's `bank_charges`, so the deposit in Zoho matches the payout. It is read
  from the gateway's stored response — Stripe's balance transaction, PayPal's
  `seller_receivable_breakdown`, PayPal Express's `FEEAMT` — and `Documents::EVENT_DEFINE_PROCESSOR_FEE`
  supplies or vetoes one for any other gateway.
- `Alerts::EVENT_BEFORE_NOTIFY`, to reword or suppress an alert.

### Changed

- A 4xx from Zoho's accounts server while refreshing the token is now treated as a refusal (not
  retried as a network failure), and recorded in the log with its status.

### Fixed

- **A saved "Zoho Books status" filter could widen to every order.** The rule dropped statuses it
  did not recognise as it loaded, so a custom source or condition whose chosen statuses had all
  since been renamed or removed became "no filter" — and re-saving it lost the choice for good. The
  choice is now kept as saved; "is one of" statuses Zo no longer knows matches no orders, and "is
  not one of" them excludes none. Only known statuses ever reach the query.

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
