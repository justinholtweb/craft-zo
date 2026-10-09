# Zo — Craft CMS 5 Plugin

## Project Overview

Zo pushes Craft Commerce into Zoho Books: customers → contacts, completed orders → invoices,
payments → customer payments, refunds → credit notes. Distributed as `justinholtweb/craft-zo`.
**One paid edition: $99, with a $79/year renewal.** Not affiliated with Zoho Corporation;
the trademark disclaimer is required on the README, the docs and every marketing page.

## Why it exists

Accounting connectors fail in two expensive ways, and Zo is built around not doing either.

1. **Duplicates.** A queue retry, a redelivered webhook or an impatient merchant produces a second
   invoice for one order. Zo *claims* a link row before it sends anything, and the unique index on
   `(type, craftKey)` is what makes the second attempt find the first one's row instead of
   creating a second document.
2. **Silent drift.** Commerce calculates tax; so does Zoho; they will not always agree. Zo stores
   Commerce's total and Zoho's total side by side on the link and surfaces the difference. If the
   "Not reconciled" count is zero, the books agree with the store.

## One edition

Zo has **no editions**. `editions()` is not declared, there is no `isPro()`, and nothing in the
codebase is gated: every install gets payments, refunds, item sync, sales orders, mapped tax,
custom fields, backfill and the full log.

This was a Lite/Pro split until the pricing was settled. If you are reading an old branch: Craft
falls back to the first entry of `editions()` when an install's stored edition is unknown
(`Plugins::createPlugin`), so a project config still carrying `edition: pro` loads as `standard`
rather than erroring — which is why collapsing the split needed no migration.

Reintroducing a second edition means reintroducing a gate in ten files. `check('the plugin
declares a single edition')` exists to make that a deliberate act rather than a drift.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks

## Architecture

### Namespace & package

- Namespace: `justinholtweb\zo`
- Package: `justinholtweb/craft-zo`
- Handle: `zo`

### The three invariants

1. **`services\Documents` is the only place a Commerce record becomes Zoho JSON.** The CP
   "Preview payload" button, the console `--dry-run` and the sync itself all call the same
   builders, so a preview is byte-identical to what Zoho receives. `Documents` performs no I/O —
   item ids and tax ids arrive as arguments — which is what makes the mapping testable without a
   Zoho account.
2. **`services\Links` is the only place a link row is created.** Everything else claims through
   it. `claim()` attempts the insert *first* and treats the duplicate-key failure as the answer,
   rather than checking then inserting: the window between a check and an insert is exactly where
   a duplicate invoice comes from.
3. **`services\Auth::getAccessToken()` is the only place a token is minted**, mutex-guarded with a
   re-read of the cache inside the lock. Without that, a backfill running twenty queue jobs
   performs twenty refreshes. It needs `getHasCredentials()`, **not** `getIsConnected()`: the
   organization lookup runs before there is an organization, and until 5.0.1 it never could.

### Data model

- `{{%zo_links}}` — unique on `(type, craftKey)`; that index *is* the idempotency guarantee.
  `craftKey` is `order:1042`, `user:19`, `transaction:88`, `purchasable:7` or `email:…`, built
  only by `Link::key()`. `elementId` points at the **order** even for payment and refund links, so
  deleting an order takes its whole document set with it — a transaction is not an element and
  cannot carry the foreign key itself.
- `{{%zo_log}}` — every call, with credentials redacted before the row is written. Clearing it is
  admin-only.
- `{{%zo_connection}}` — the connection made on *this environment*: the refresh token
  (`helpers\Secret`-encrypted, as in Freshh and Bird), the data centre Zoho reported, and the
  auto-picked organization. **Never settings**: settings are project config, and each environment —
  the live site included — connects for itself (`SettingsController` is `requireAdmin(false)`).
  `services\Connection` owns it; `Settings::getParsedRefreshToken()`, `getSafeDataCenter()` and
  `getParsedOrganizationId()` read it (an explicit organization setting still wins). The
  `refreshToken` setting is only an `$ENV` override, and a literal is refused unless it is the
  5.0.0 stored value — never rendered; the form posts `refreshTokenKept`.

- `{{%zo_alerts}}` — failure-alert latches, one row per incident, unique on `incident` (Erpy's
  table keyed on `(connectionId, incident)`; Zo has one connection per environment, so no column).

Both totals live on the link (`craftTotal`, `zohoTotal`, `variance`) because the difference cannot
be recomputed later from two systems that have both moved on.

### Failure alerts (ported from Erpy, 2026-10-09)

`services\Alerts` is a copy of craft-erpy's reference (its CLAUDE.md, "Failure alerts"), with the
connection dimension dropped. Three incidents: **failures** (failed contact/invoice/salesorder/
payment/refund links by `dateUpdated` inside `alertWindowMinutes`, threshold to open, a whole quiet
window to close — items never count), **variance** (a synced link with |variance| > tolerance by
`dateSynced` inside the window), **auth** (a pushed signal). Hooks: `Sync::syncOrder()` wraps the
real work (`runSync()`) and calls `afterSync()` in a `finally`; `Api::handle()` signals a 401/code 57
that survives the forced refresh and calls `noteAuthSuccess()` on every success;
`Auth::getAccessToken()` signals an `OAuthRefusedException` (an `{"error":…}` body or a 4xx from the
accounts server — never a network failure). `check()` does nothing until `getHasCredentials()`.
Recoveries quote `standingCount()` because "nothing new for an hour" is not "fixed". Do not change
when touching it: the conditional-UPDATE claim/release, redaction before anything leaves, the
webhook through `webhookTarget()` (`helpers\Ip` is the family copy — keep it identical), the HMAC
header, every path fail-open.

### Order status (Orders index column and condition rule)

`Links::orderStatuses()` (PHP, two queries per batch) and `Links::orderStatusCondition()` (SQL, for
`ZohoStatusConditionRule::modifyQuery()`) define the same six sets in the same precedence —
failed > notReconciled > synced > pending > skipped > none — and `tests/integration/orders.php`
holds them to partitioning the fixtures identically. Change one, change both. A contact link points
at the customer element, so a failed contact marks that customer's completed orders failed. The
column memoises per request and is prefetched from `OrderQuery::EVENT_AFTER_POPULATE_ELEMENTS` on
`element-indexes/*` requests only. The rule is registered unconditionally (a conditionally
registered rule is dropped from saved conditions and the source widens to every order).
`setValues()` keeps stale statuses as chosen; only the known ones reach SQL, and a rule whose
chosen statuses are all unknown is `0=1` for "is one of" and no filter for "is not one of" (same in
`matchElement()`). Stripping them on load — 5.0.x — turned such a source into "every order".

### Deposit accounts and processor fees

`Settings::$depositAccountMap` (gateway handle → account id, `$ENV`-able, a fourth private-backed
map in `attributes()`) is resolved by `Documents::depositAccount()` for both `account_id` on a
payment and `from_account_id` on a credit-note refund. `Documents::processorFee()` reads the fee
from `$transaction->response` only — Stripe `balance_transaction` (minor units, zero-decimal list),
PayPal v2 `seller_receivable_breakdown.paypal_fee`, NVP `FEEAMT` — ignores a fee in another
currency than `$transaction->currency` or one ≥ the amount, then `EVENT_DEFINE_PROCESSOR_FEE` may
override. Commerce Stripe stores the intent with an unexpanded charge, so most Stripe stores read
no fee — documented, not faked. `recordProcessorFees` is off by default (double counting).

### Tax modes

`adjustment` (default) sends no tax rates and puts the difference between Commerce's total and the
line/shipping/discount arithmetic in as one adjustment — so the invoice total equals the payment
exactly, and any custom adjuster is included automatically without Zo modelling it. `mapped` puts
`tax_id`s on lines and lets Zoho compute; the variance check is what makes that safe to offer.

## Protocol notes (verified, not guessed)

Read from zoho.com/books/api/v3 — introduction, oauth, contacts, invoices, customer-payments,
credit-notes, creditnotes/refunds.

- Base is `https://www.zohoapis.{dc}/books/v3/`, accounts is `https://accounts.zoho.{dc}`, and the
  eight data centres **do not share data** — a token from one is a bare 401 at another.
- **Every Books call needs `organization_id`.** Omitting it is not an error; Zoho answers for
  whichever organization it likes, which on a multi-entity account files invoices against the
  wrong company.
- Zoho's accounts server answers a bad grant with **HTTP 200** and `{"error":"invalid_code"}`, and
  the Books API answers some rejected writes with **HTTP 200** and a non-zero `code`. Neither
  status line is a verdict.
- **`prompt=consent` is what makes a *repeat* authorization return a refresh token.** Without it a
  merchant reconnecting an existing app gets an access token only, and the connection dies an hour
  later with no explanation.
- The redirect carries an **`accounts-server`** param naming the region actually authorised
  against. Honouring it turns the commonest misconfiguration into a non-event.
- **Zoho only refunds a customer payment whose `payment_mode` is `autotransaction`, and only the
  unapplied part of it** — so a Commerce refund on a paid invoice is *not* a payment refund. It is
  a credit note (reverses the revenue), then `POST /creditnotes/{id}/refunds` (records the cash).
  That second call requires `from_account_id` and Zoho offers no default.
- A new invoice is a **draft**, and drafts are invisible to Zoho's ageing reports. `POST
  /invoices/{id}/status/sent` is not optional for a store that wants receivables to be real.
- Zoho will not accept a chosen `invoice_number` unless the request also carries
  `ignore_auto_number_generation=true`; without it the number is silently discarded.
- Contact names are unique. Code **1001** on a create means the customer already exists — adopting
  that contact is the only branch that avoids either a duplicate or a permanently failing order.
- Addresses match countries **by name**, not ISO code, and an unrecognised one is dropped silently.
- `payment_mode` is a closed vocabulary of seven values; anything else is a 400.
- Rate limits: 100/minute per organization plus a plan-dependent daily cap, both reported as 429
  with code 44 or 45. The daily one will not clear by waiting a few seconds.

## Traps found while building this

- **A `Model` subclass cannot declare `getErrors(): array`.** `yii\base\Model` already declares it
  with an `$attribute` parameter, so redeclaring it is a **compile-time fatal** — the class cannot
  load at all. `SyncResult::getFailures()` exists for that reason.
- **A controller cannot narrow an inherited method's visibility.** A `private
  redirectToPostedUrl()` on a `craft\web\Controller` subclass is the same compile-time fatal, and
  it takes down every screen that controller serves. Craft's own version already does the job.
- **Private properties are not Yii attributes**, and Craft persists plugin settings by iterating
  attributes. The three editable-table maps (`customFields`, `taxMap`, `paymentModeMap`) are
  backed by private properties so their setters can normalise Craft's `[['field'=>…], …]` row
  format into a map — which means `attributes()` has to name them or every mapping saves as
  nothing while the screen reports success.
- **`UrlHelper::actionUrl()` is the wrong shape for an OAuth redirect URI** — it produces
  `?p=actions/…` without `omitScriptNameInUrls`. A CP route gives a bare path. `cpUrl()` then
  appends `?site=` on a multi-site install, so *that one param* has to be removed — not the whole
  query string, which on some installs contains the path.
- **Commerce's `Transactions::createTransaction()` reads `$order->getGateway()->id`
  unconditionally**, so an order with no gateway fatals rather than failing validation.
- **Project config writes from a long-running console script fail as stale.** Craft compares the
  DB's `configVersion` against the one cached on `Craft::$app->getInfo()`, and after the script's
  own second write those disagree. Re-read it before each write.
- **Craft appends `?site=` to CP URLs** — see above; it bit the redirect URI.
- `craft\commerce\events\OrderStatusEvent` carries `$order` directly.
- Craft plugin console commands are not reachable via `craft help <handle>` — they are listed
  under a bare `craft help` and run as `zo/sync/order`.
- **The settings script must look up `settings-` + id.** Craft renders plugin settings inside a
  `settings` namespace, which rewrites every id but not the `{% js %}` block — every button was dead
  until 5.0.1. `byId()` tries both; `security.php` checks every lookup resolves.
- **A front-end template answers a POST without a CSRF token**, which is what lets
  `security.php` play Zoho's token endpoint with a throwaway template behind `accountsUrl`.
- **Craft condition-rule operators are protected constants** — "is not one of" is the string `'ni'`
  from outside the class, not `OPERATOR_NOT_IN` and not `'notIn'` (an unknown operator silently
  behaves as "is one of").
- **A captured email's `toString()` is quoted-printable**, which splits long lines with `=` and
  breaks every `str_contains`. Read `$message->getSymfonyEmail()->getTextBody()` instead.
- **`$row['col'] ?? 'x'` is `'x'` when the column is NULL** — the wrong tool for asserting a latch
  column was released to null; use `array_key_exists`.
- `plugin/switch-edition` is not a console command; switching editions from a script means
  `Plugins::switchEdition()` plus `ProjectConfig::saveModifiedConfigData()`.

See `[[craft-plugin-gotchas]]` for family-wide traps, and `[[project_craft_freshh]]` — the
FreshBooks sibling — for the same architecture against a different API.

## Docs and the marketing site

`docs/*.md` is the **source of truth** for the marketing site at
[justinholt.com/plugins/craft-zo](https://justinholt.com/plugins/craft-zo). The site does not
author its own copy — `ddev exec php craft pluginsite/docs/sync craft-zo` (run from
`~/Sites/justinholt`) reads this directory and writes both the entries and a committed seed.

Every file needs YAML front matter with at least a `title`; a file without it is skipped, which is
how a design note stays off the site. A page deleted here is deleted from the site on the next
sync.

The page itself — hero, features, FAQ, CTA — lives in
`justinholt/scripts/seed/plugin-pages/craft-zo.json`, not here.

**A price change touches more places than it looks.** All of: this file, `README.md`'s editions
table, `docs/installation.md`'s editions table, `docs/faq.md`, the page seed
(`priceLabel`/`priceValue`/`renewalPrice`/`pricingModel` and any band copy quoting a figure), the promo cover
badge in `promos/slides.html` — and the **Craft Console listing**, where the actual prices live at
`id.craftcms.com` rather than in any repo. That last one is the one that silently disagrees with
everything else.

## Plugin Store promos

`promos/` renders the seven 1920×1080 marketing images for the Plugin Store listing:

```sh
./promos/build.sh          # all slides
./promos/build.sh "2 5"    # just those two
```

They live **in this repo**, not in a website repo. Plugin marketing sites are now pages inside the
justinholt.com install rather than standalone projects, so there is no `craft-zo-website/` for them
to sit in — and they are a Plugin Store asset anyway, so they belong with the plugin.

Two icon files, and they are not interchangeable:

- `src/icon.svg` is the **control panel** icon — monochrome `currentColor` line art, because Craft
  renders it as a mask.
- `promos/assets/icon.svg` is the **Plugin Store tile** — the accent square with the mark in white,
  the shape the rest of the plugin family uses. `justinholt/web/images/plugins/zo.svg` is a copy of
  this one, not of `src/icon.svg`.
- `promos/assets/watermark.svg` is ruled ledger lines and the Z at thin strokes. The app icon's own
  frame is a 6-unit stroke, which at watermark scale is ~70px thick and reads as a hard-edged grey
  box across every slide. See `promos/README.md`.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-zo/tests/integration/checks.php   # 106 checks (2 fail on the shared harness: see below)
ddev exec php /var/www/craft-zo/tests/integration/security.php # 23: connect round trip with admin changes off, token storage, migration, permissions
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-zo/tests/integration/alerts.php  # 58: latch, mail, SSRF, webhook, auth signals, widget, console, test action over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-zo/tests/integration/orders.php  # 46: status sets vs SQL, condition rule, column + action over HTTP, deposit accounts, fees
docker exec -w /sites/craft-zo ddev-phpstan-runner-web bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
ddev exec bash -c 'find /var/www/craft-zo/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Zoho is emulated through a **Guzzle mock transport** installed on the `clientConfig` seam that
`Api` and `Auth` expose for egress proxies. That is not a shortcut around testing the wire — it is
the only way to exercise the answers that matter (a 401 that clears on refresh, a 429, a 200
carrying a failure code) deterministically. One real round-trip against the local web server
covers what the mock cannot: a live endpoint answering with HTML.

The suite restores settings, fixtures, link rows, log rows and queued jobs in a `finally`. It also
**self-heals**: a run killed before its `finally` leaves `fixture-client` in project config, and
the next run recognises and clears it rather than snapshotting the pollution as the new original.

Two `checks.php` failures are the shared harness, not Zo (October 2026): another plugin redirects
unknown 404s to `/contact`, so "an endpoint answering with HTML" gets a 200 page; and the harness has
~400 completed orders while the backfill check reads the oldest 200.

`alerts.php` and `orders.php` share `tests/integration/_support.php` and keep every setting in
memory (no project config writes); fixtures and alert rows are removed in a shutdown function.
`security.php` picks an order *with an email* for its payload check: a sibling's test leaves
email-less completed orders in the harness, and `str_contains($body, '')` is always true.

Two things the suite has to do that are easy to get wrong:

- **Reset the rate limiter per scenario.** The counter is shared across processes on purpose, so
  two runs inside one minute would throttle each other.
- **Give each fixture order a unique email.** Zo reuses a contact across every order from one
  customer — correct behaviour that otherwise silently consumes the wrong queued response.

## Coding conventions

- `Craft::t('zo', '…')` for user-facing strings; `src/translations/en/zo.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Anything that runs during checkout fails **open**: Zoho being down must never stop a customer
  paying, which is why the default is to queue
- Best-effort steps (items, contact updates, marking an invoice sent) never fail the sync — the
  invoice existing and being unrecorded is worse than the invoice being slightly poorer
