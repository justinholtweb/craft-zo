---
title: FAQ
slug: faq
order: 50
summary: Common questions about syncing Craft Commerce into Zoho Books.
---

## What does Zo cost?

**$99**, once, with a **$79/year renewal** for continued updates. One edition — there is no free
tier, no trial, and nothing held back for an upgrade. Every store gets payments, refunds, item
sync, sales orders, mapped tax rates, custom field mapping, bulk backfill and the full log.

If you let the renewal lapse the plugin keeps working; you stop getting updates until you renew.

## Can it create a duplicate invoice?

No — and that is the thing the plugin is actually built around. Every Craft ↔ Zoho pair is a row
in a table with a unique index, and Zo *claims* the row before it sends anything, treating a
duplicate-key failure as the answer instead of checking first and inserting second. A retry, a
redelivered webhook or an impatient second click all find the first attempt's row and stop.

The one way to get two invoices is to **unlink** an order and then sync it again, which is why
unlink is behind its own permission.

## Will Zoho and Commerce agree on tax?

Not necessarily, which is why Zo checks rather than assumes.

In the default mode Zo sends no tax rates at all and puts the difference between Commerce's total
and the line arithmetic in as one adjustment — so the invoice total equals what the customer paid,
exactly, and any custom adjuster is included automatically. In mapped mode Zoho computes the tax
itself, which gives you properly itemised Zoho tax reports at the cost of Zoho possibly
disagreeing. Either way both totals are stored side by side on the link and the difference is
surfaced.

If the Sync screen's **Not reconciled** count is zero, your books agree with your store.

## Will it slow down or break checkout?

No. Syncing runs through the queue by default, and everything Zo does during checkout fails
**open** — Zoho being down must never stop a customer paying.

## What happens to orders that completed before I installed it?

Nothing, until you ask. Backfill pushes them in batches, with a five-order rehearsal first
and pacing that stays inside Zoho's rate limits.

## I already have my customers in Zoho. Will it duplicate them?

Not if you leave **Reuse an existing Zoho contact with the same email** on, which is the default.
Zoho also enforces unique contact names, and Zo adopts the existing contact when Zoho says one
already exists rather than failing the order or creating a near-duplicate.

## Which Zoho data centre does it support?

All eight — `.com`, `.eu`, `.in`, `.com.au`, `.jp`, `.ca`, `.sa` and `.com.cn`. You do not have to
choose correctly up front: Zoho's redirect names the region you authorized against, and Zo writes
it back to the setting.

## Who numbers the invoices?

Zoho, by default, so its own sequence stays unbroken — in several jurisdictions that is a legal
requirement rather than a preference. Craft's order reference goes into Zoho's reference field so
you can find either record from the other. If you would rather the two systems share one number,
one setting switches it.

## How are refunds handled?

As **credit notes**, not payment refunds. Zoho will only refund a customer payment whose mode is
`autotransaction`, and only the part of it that is unapplied, so a refund against a paid invoice
cannot be represented that way. Zo writes a credit note to reverse the revenue and — when a deposit
account is configured — records the cash movement against it.

## Are my credentials safe in the log?

Yes. Client secrets, refresh tokens, authorization codes and bearer tokens are redacted **before
the row is written**, not hidden at display time, so the log is safe to export and hand to whoever
is debugging.

## Does it work with a custom tax adjuster?

Yes, in the default tax mode, and without Zo knowing anything about it. The adjustment is computed
as the difference between Commerce's total and the line arithmetic, so whatever your adjuster added
is inside it.

## Can I use it behind an outbound proxy?

Yes. Both the API base URL and the accounts URL can be overridden, and both parse environment
variables.

## Can a template create an invoice?

No. `craft.zo` is read-only by design — it reports what has been synced and whether the connection
is live, and that is all it can do.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Is Zo made by Zoho?

No. Zo is an independent plugin built by Justin Holt, and it is not affiliated with, endorsed by,
or sponsored by Zoho Corporation. It talks to the Zoho Books public API the same way any
third-party integration does. “Zoho” and “Zoho Books” are trademarks of Zoho Corporation.

## Where do I get support?

justin@justinholt.com
