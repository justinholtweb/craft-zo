# Zo

Zoho Books for Craft Commerce. An independent plugin — not affiliated with Zoho Corporation.

Zo pushes your store into your books: customers become Zoho contacts, completed orders become
invoices, payments become receipts and refunds become credit notes — automatically, idempotently,
and with every document checked against what the customer actually paid.

**Requires** Craft CMS 5.3+, Craft Commerce 5.0+ and PHP 8.2+.
**Price:** a one-off $99, with a $79/year renewal for updates.

---

## Why another integration

Most accounting connectors are a one-way pipe with a hopeful attitude. Two things go wrong with
that, and both cost real money:

**Duplicates.** A queue retries, a webhook redelivers, a merchant clicks "sync" twice — and the
books grow a second invoice for one order. Zo stores every Craft ↔ Zoho pair in a table with a
unique index, and *claims* the pair before it sends anything. A second attempt finds the first
one's row and stops. There is exactly one code path that can create a link, and it cannot create
two.

**Silent drift.** Commerce calculates tax. Zoho Books also calculates tax. They will not always
agree, and a connector that just posts the order and moves on leaves a penny here and a penny
there for someone to find at year end. Zo compares the total Zoho returns against the total
Commerce charged, stores both numbers on the link, and shows you every document that does not
reconcile.

---

## What it does

Everything below is in the one edition. There is no free tier and no feature to unlock later.

| | |
|---|---|
| **OAuth connection** | All eight Zoho data centres, with the region taken from Zoho's own redirect rather than guessed |
| **Customers → contacts** | Matched against the contacts you already have, by email or by name, before one is created |
| **Completed orders → invoices** | Promoted out of draft, so they count toward receivables |
| **Reconciliation** | Every document's Zoho total checked against what Commerce charged, both numbers stored |
| **Payments → customer payments** | Commerce payment transactions, applied against the invoice |
| **Refunds → credit notes** | Plus the cash movement, when a deposit account is configured |
| **Products → Zoho items** | Matched by SKU, so sales-by-item reporting works |
| **Sales orders** | Alongside invoices, or instead of them |
| **Tax** | Commerce as the source of truth, or mapped rates with Zoho computing |
| **Custom field mapping** | Zoho custom fields filled from object templates rendered against the order |
| **Bulk backfill** | For a store that was already trading when Zo arrived |
| **Queue-driven syncing** | With exponential backoff, and retry from the CP or the console |
| **Connection log** | Every call, with credentials redacted before the row is written |
| **Sync screen and order panel** | What is in the books, what failed, and what does not reconcile |

---

## Setting it up

### 1. Create a Zoho application

In the [Zoho API console](https://api-console.zoho.com), create a **Server-based Application**.
Zo's settings screen shows the exact **Authorized Redirect URI** to paste in — Zoho matches it
character for character, including the scheme.

### 2. Connect

Paste the client ID and secret into Zo's settings, save, and press **Connect to Zoho Books**.
Zoho sends you back with a refresh token, which Zo stores — encrypted, in its own table, never in
project config — and uses from then on. Each environment connects for itself, the live site
included: Connect works there even with admin changes off.

Zo asks only for the scopes your configuration needs — never `fullaccess`. Turning on payment
sync later widens the scopes, so reconnect after changing what Zo does.

If your Zoho account lives outside the US, either set the data centre first or just connect: Zoho
tells Zo which region you authorised against, and Zo uses that for you.

### 3. Keep the credentials out of project config

Plugin settings live in project config, and project config gets committed. Put the client ID and
secret in your `.env` and enter their names; Zo parses environment variables in every credential
field. Leave **Refresh token** empty — connecting stores it — unless you want to manage it yourself
as `$ZOHO_REFRESH_TOKEN`.

```
ZOHO_CLIENT_ID=1000.XXXXXXXX
ZOHO_CLIENT_SECRET=xxxxxxxx
ZOHO_ORG_ID=10234695
```

### 4. Check it before you trust it

The settings screen has a **Preview payload** button. It builds the JSON your most recent order
would be sent as, using the same code the sync uses, and tells you whether the totals line up.
Nothing is sent.

---

## Tax: the decision that matters

Commerce and Zoho Books will not always agree on tax, and you have to choose who wins.

**Commerce is the source of truth** (the default). Zo sends no tax rates. The tax Commerce
actually charged goes on the invoice as an adjustment, so the Zoho total equals the amount the
customer paid — exactly, every time. Anything a custom adjuster added is included automatically,
because the adjustment is computed as the difference rather than modelled.

**Let Zoho calculate.** Line items carry Zoho `tax_id`s from your mapping and Zoho works out the
tax itself. Your Zoho tax reports are then properly itemised — but Zoho's answer can differ from
Commerce's. Zo measures the difference on every invoice and flags it.

**No tax.** Only sensible for a tax-exempt store.

Either way, the Sync screen's **Not reconciled** counter is the number to watch. If it is zero,
your books agree with your store.

---

## Numbering

By default Zoho Books numbers its own invoices, which keeps its sequence unbroken — in several
jurisdictions that is a legal requirement, not a preference. Craft's order reference goes into
Zoho's reference field so you can find either from the other.

If you would rather the two systems share a number, set **Invoice number** to the order reference.
Zo then asks Zoho to accept it instead of generating one.

---

## Running it

Zo syncs an order when Commerce completes it, by default, through the queue. You can also sync on
payment, or when an order reaches particular statuses.

Every order gets a panel on Commerce's own order edit screen showing what exists in Zoho, with
**Sync now** and **Preview payload** buttons.

### Console

```sh
php craft zo/sync/order 1042            # one order
php craft zo/sync/order 1042 --dry-run  # show the payload, send nothing
php craft zo/sync/order 1042 --force    # ignore the eligibility rules
php craft zo/sync/backfill --limit=200  # everything that never made it
php craft zo/sync/backfill --queue      # …in the background, batch by batch
php craft zo/sync/retry                 # failed orders that have attempts left
php craft zo/sync/status                # how much of the store is in the books

php craft zo/auth/test                  # check the credentials
php craft zo/auth/refresh               # prove the refresh token still works
php craft zo/auth/disconnect            # forget this environment's connection and revoke its token

php craft zo/log/prune                  # honour the retention setting
```

Plugin commands are listed under a bare `php craft help`, not under `php craft help zo`.

---

## Backfilling an existing store

If you are connecting Zo to a store that has been trading, do this in order:

1. Leave **Reuse an existing Zoho contact with the same email** on. Your customers are already in
   Zoho, and this is what stops Zo shadowing every one of them.
2. Run `php craft zo/sync/backfill --limit=5` and look at the result in Zoho.
3. Check the Sync screen's **Not reconciled** count. If it is not zero, fix your tax setting
   before going further.
4. Run the full backfill — `--queue` for anything over a few hundred orders. Zoho allows a few
   thousand API calls a day depending on your plan, and Zo paces itself to stay inside both that
   and the 100-per-minute ceiling.

---

## Twig

`craft.zo` is read-only by design — a template must never be able to create an invoice.

```twig
{% set invoice = craft.zo.invoiceNumberFor(order) %}
{% if invoice %}
    <p>Invoice {{ invoice }}</p>
{% endif %}

{% if craft.zo.isConnected() %}…{% endif %}

{% for link in craft.zo.linksFor(order) %}
    {{ link.type }}: {{ link.zohoNumber ?? link.status }}
{% endfor %}
```

---

## Permissions

- **View what has been synced** — the Sync screen and the order panel. Previewing an order's
  payload also needs permission to view that order — it carries the customer's details.
  - **Sync orders to Zoho Books** — the buttons, retries and backfill
  - **Unlink orders from Zoho Books** — forgetting a link, which is how a duplicate gets made if
    you are careless
- **View the connection log** — reading it. Clearing it is admin-only: it is the audit trail.

Connecting, disconnecting and the settings screen are admin-only.

---

## The log

Every call Zo makes lands in the log with its endpoint, HTTP status, Zoho's own error code and
how long it took. Client secrets, refresh tokens, authorization codes and bearer tokens are
redacted before anything is written, so the log is safe to hand to whoever is debugging.

Full request and response bodies are kept for as long as you configure.

---

## Advanced

**Egress proxies.** If outbound traffic has to leave through an internal proxy, set the API base
URL and accounts URL overrides. Both are environment-variable parseable.

**Rate limiting.** Zoho allows 100 requests a minute per organization and enforces it by locking
the account out. Zo defaults to 90, leaving headroom for anything else talking to the same
organization. The counter is shared across web requests, queue workers and console commands,
because Zoho counts them all the same.

---

## Trademarks

Zo is an independent plugin built by Justin Holt. It is **not affiliated with, endorsed by, or
sponsored by Zoho Corporation**. “Zoho” and “Zoho Books” are trademarks of Zoho Corporation, used
here only to identify the service Zo connects to.

---

## Support

justin@justinholt.com
