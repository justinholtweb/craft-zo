# Release Notes for Zo

## 5.0.0

Initial release.

### Added
- OAuth 2.0 connection to Zoho Books, across all eight Zoho data centres, with the region taken
  from Zoho's own redirect rather than guessed.
- Commerce customers synced as Zoho contacts, reusing an existing contact where one already
  matches by email or name instead of duplicating it.
- Completed orders synced as Zoho invoices, optionally as sales orders (Pro), with the invoice
  promoted out of draft so it counts toward receivables.
- Three ways to handle tax, and a reconciliation check on every document: the total Zoho returns
  is compared against the total the customer actually paid, and any difference is recorded and
  surfaced rather than absorbed.
- Payments and refunds (Pro) — Commerce payment transactions as Zoho customer payments, refunds
  as credit notes with the cash movement recorded when a deposit account is configured.
- Zoho items created or matched per purchasable (Pro), so Zoho's sales-by-item reporting works.
- Queue-driven syncing with exponential backoff, and a batched backfill (Pro) for stores
  connecting after they have been trading.
- A connection log with credential redaction, and a Sync screen showing what is in the books,
  what failed, and what does not reconcile.
- Console commands: `zo/sync/order`, `zo/sync/backfill`, `zo/sync/retry`, `zo/sync/status`,
  `zo/auth/test`, `zo/auth/refresh`, `zo/log/prune`, `zo/log/clear`.
