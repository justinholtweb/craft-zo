---
title: Usage
slug: usage
order: 30
summary: The sync screen, the order panel, backfilling an existing store, Twig and the console.
---

## What a sync actually does

When an order becomes eligible, Zo works through it in one order, and every step claims its link
row *before* it sends anything:

1. **The contact.** The order's customer is matched to a Zoho contact by email, or by name, or
   created. An existing one is adopted rather than duplicated.
2. **The items.** Each purchasable is matched or created as a Zoho item so Zoho's
   sales-by-item reports work.
3. **The invoice.** Lines, shipping, discount and tax, then a total check against what Commerce
   charged, then the invoice is promoted out of draft so it counts toward receivables.
4. **The payment.** Commerce's payment transactions become Zoho customer payments applied
   against that invoice.
5. **Refunds.** A Commerce refund becomes a credit note, and — if a deposit account is
   configured — the cash movement is recorded against it.

Steps 2, the contact update, and marking the invoice sent are **best-effort**: none of them fails
the sync. An invoice that exists but is slightly poorer beats an invoice that does not exist.

## Why it cannot make a duplicate

Every Craft ↔ Zoho pair lives in one table with a unique index on the pair's key. Zo *claims* the
row first and treats the duplicate-key failure as its answer, rather than checking whether a row
exists and then inserting one — the gap between a check and an insert is exactly where a second
invoice comes from.

So a queue retry, a redelivered webhook or a merchant pressing **Sync now** twice all find the
first attempt's row and stop. There is one code path that can create a link, and it cannot create
two.

## The Sync screen

**Zo → Sync** is the whole picture of what is in the books:

- how much of the store has been synced, and how much has not
- what **failed**, with Zoho's own error, and how many attempts are left
- what is **not reconciled** — every document whose Zoho total does not match what Commerce
  charged, with both numbers side by side

That last count is the one to watch. Zero means your books agree with your store.

## The order panel

Every order gets a panel on Commerce's own order edit screen showing which Zoho documents exist
for it, with two buttons:

- **Sync now** — runs it immediately rather than waiting for the queue
- **Preview payload** — builds the exact JSON that would be sent and shows it to you, sending
  nothing

The preview is built by the same code the sync uses, so what you are reading is byte-for-byte what
Zoho would receive. That is also true of `--dry-run` on the console.

## The Orders index

Commerce's own **Orders** index gets three things, for anyone with *View what has been synced*:

- A **Zoho Books** column (add it from the index's column picker): *Synced*, *Not reconciled*,
  *Failed*, *Pending*, *Skipped* or *Not synced*. *Failed* means any of the order's documents
  failed — the customer, the invoice, a payment or a refund — so an order whose invoice is in Zoho
  but whose payment was refused shows as failed, not synced.
- A **Zoho Books status** filter, under the index's filters and in custom sources. "Is one of
  *Failed*, *Not reconciled*" is the to-do list; save it as a custom source and it is one click
  away. The filter and the column are built from the same rules, so they cannot disagree.
- A **Sync to Zoho Books** bulk action, for people with *Sync orders to Zoho Books*. Each selected
  completed order is queued, with the eligibility rules overridden (as with **Sync now**); carts
  are skipped. Documents that already exist in Zoho are reused, never resent, so selecting an
  already-synced order only picks up a payment or refund that is missing.

## Backfilling an existing store {#backfill}

If you are connecting Zo to a store that has already been trading, do this in
order — the sequence matters more than it looks:

1. **Leave "Reuse an existing Zoho contact with the same email" on.** Your customers are already in
   Zoho. This is what stops the backfill creating a second contact for every one of them.
2. **Run five orders and go look.**

   ```sh
   php craft zo/sync/backfill --limit=5
   ```

   Open Zoho Books and check that the invoices look like your invoices.
3. **Check the Not reconciled count.** If it is not zero, your tax mode is wrong for this store.
   Fix that before going further — every invoice you write now with the wrong mode is one you will
   want to fix later.
4. **Run the rest.** Use `--queue` for anything over a few hundred orders:

   ```sh
   php craft zo/sync/backfill --queue
   ```

   Zoho allows 100 calls a minute and a few thousand a day depending on your plan. Zo paces itself
   to stay inside both, so a large backfill takes as long as it takes.

## Console

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

php craft zo/log/prune                  # honour the retention setting
php craft zo/log/clear                  # empty it

php craft zo/alerts/check               # evaluate failure alerts now
php craft zo/alerts/test                # send a sample alert through every configured channel
```

Craft lists plugin commands under a bare `php craft help`, not under `php craft help zo`.

## Twig

`craft.zo` is read-only by design. A template must never be able to create an invoice.

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

## The log

**Zo → Log** holds every call Zo has made: the endpoint, the HTTP status, Zoho's own error code,
how long it took, and the full request and response bodies.

Two things about reading it. Zoho's accounts server answers a bad grant with **HTTP 200** and an
error in the body, and the Books API answers some rejected writes with **HTTP 200** and a non-zero
`code`. Neither status line is a verdict — check the code column, not the status column. And
credentials are redacted before the row is written, so the log is safe to send to someone else.
