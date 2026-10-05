---
title: Configuration
slug: configuration
order: 20
summary: Tax modes, numbering, contact matching, payments, rate limits and permissions.
---

## Tax: the decision that matters

Commerce calculates tax. Zoho Books also calculates tax. They will not always agree, and you have
to decide who wins. This is the one setting worth reading before you change anything else.

### Commerce is the source of truth — `adjustment`, the default

Zo sends **no tax rates at all**. It sends the lines, the shipping and the discounts, works out the
difference between that arithmetic and the total Commerce actually charged, and sends the
difference as a single adjustment line.

The invoice total therefore equals the amount the customer paid — exactly, every time. Anything a
custom tax adjuster added is included automatically, because the adjustment is *computed as the
difference* rather than modelled. Zo does not need to know how your tax works to get the total
right.

The adjustment line's label comes from **Adjustment description** (`Sales tax` by default).

### Let Zoho calculate — `mapped`

Line items carry Zoho `tax_id`s from your tax map and Zoho computes the tax itself. Your Zoho tax
reports are then properly itemised, which is the reason to want this.

The risk is that Zoho's answer differs from Commerce's, and the invoice then disagrees with the
payment. Zo measures that difference on every single invoice and flags it, which is what makes the
mode safe to offer at all — see [Reconciliation](#reconciliation) below.

Map Commerce **tax category handles** to Zoho `tax_id`s in the settings screen's tax table.

### No tax — `none`

No tax anywhere. Only sensible for a genuinely tax-exempt store.

## Reconciliation

Every document Zo creates records two totals on its link row: what Commerce charged, and what Zoho
returned. The difference is stored as the variance.

- **Variance tolerance** — how many cents of disagreement are tolerated before a document is
  flagged. `0.01` by default.
- **Block on variance** — off by default. When a total does not match, the invoice *does* exist in
  Zoho; marking the sync failed invites a retry, and a retry that does not find its link row is
  how a duplicate gets made. Zo would rather show you the discrepancy than hide it by pretending
  the document is not there.

The Sync screen's **Not reconciled** counter is the number to watch. If it is zero, your books
agree with your store.

## Numbering

**Invoice number** decides whether Zoho numbers the document or Craft does.

- `zoho` (default) — Zoho Books auto-numbers, keeping its own sequence unbroken. In several
  jurisdictions an unbroken invoice sequence is a legal requirement rather than a preference,
  which is why this is the default.
- anything else — Craft's number is sent and Zoho is asked to accept it. (Zoho silently discards a
  supplied number unless the request also says so explicitly; Zo sends the flag that makes it
  stick.)

**Reference source** is what lands in Zoho's `reference_number` field, so you can find either
system's record from the other. One of `reference`, `number`, `shortNumber` or `id`.

## Documents

- **Document type** — `invoice`, `salesorder`, or `both`.
- **Payment terms** — days after the invoice date that payment is due.
- **Mark invoice sent** — on by default, and you want it on. A new Zoho invoice is a **draft**, and
  drafts are invisible to Zoho's ageing and receivables reports. Zo promotes the invoice out of
  draft so the money shows up where an accountant looks for it.
- **Email invoice** — off by default. Commerce already emailed the customer; turning this on emails
  them again from Zoho.
- **Invoice notes / terms** — object templates rendered against the order.

## Line items

- **Include shipping** and **Include discount** — on by default, each as its own line.
- **Line description** — an object template rendered against each line item. `{{ object.sku }}` by
  default.
- **Sync items** — create or match a Zoho Books item per purchasable so Zoho's
  sales-by-item reporting works. Matched by **SKU** or by **name**.

Item sync is best-effort: if an item cannot be created, the invoice is still written. A slightly
poorer invoice beats no invoice.

## Contacts

- **Reuse an existing Zoho contact with the same email** — on by default. Leave it on if your
  customers are already in Zoho; it is the single setting that stops Zo shadowing every one of
  them with a duplicate.
- **Update the contact on every sync** — off by default. Pushes name and address changes back onto
  an already-linked contact.
- **Infer business customers** — marks a contact as a business when the billing address carries an
  organization name.
- **Phone field handle** — Craft 5 moved addresses out of Commerce and dropped the native phone
  attribute, so Zo cannot find a phone number unless you tell it which custom field holds one.

Two things about Zoho contacts that shape this: **contact names are unique**, so a create can come
back saying the customer already exists — Zo adopts that contact rather than failing the order
forever or making a near-duplicate. And **addresses match countries by name, not ISO code**; an
unrecognised country is dropped silently by Zoho.

## Payments and refunds

- **Sync payments** — Commerce payment transactions become Zoho customer payments.
- **Sync refunds** — a Commerce refund becomes a **credit note**, not a payment refund. Zoho only
  refunds a customer payment whose mode is `autotransaction`, and only its unapplied part, so a
  refund against a paid invoice cannot be modelled that way. The credit note reverses the revenue;
  the cash movement is recorded separately.
- **Deposit account** — the Zoho chart-of-accounts id the money lands in. Zoho offers no default
  for the refund call, so **without this set the credit note is written but the cash movement is
  not**.
- **Default payment mode** and the **gateway map** — Zoho's `payment_mode` is a closed vocabulary
  of seven values and anything else is rejected outright, so map your Commerce gateway handles onto
  it rather than hoping.

## Custom fields

Map a Zoho custom field placeholder (`cf_purchase_order`) to an object template rendered against
the order. Missing placeholders are ignored by Zoho, so a typo is quiet — check the log if a field
never arrives.

## When Zo runs

- **Sync on complete** (default) — the moment Commerce completes the order.
- **Sync on paid** — when the order is fully paid instead.
- **Sync on status** — when the order reaches particular order statuses.
- **Eligible statuses** and **minimum order total** — filters applied before any of the above.
- **Sync via the queue** (default, and leave it on) — anything that runs during checkout fails
  **open**. Zoho being down must never stop a customer paying.
- **Max attempts** — how many times a failed order is retried, with exponential backoff.

## Transport

- **Requests per minute** — `90` by default. Zoho allows 100 per minute per organization and
  enforces it by locking the account out; the headroom is for anything else talking to the same
  organization. The counter is shared across web requests, queue workers and console commands,
  because Zoho counts them all the same.
- **Request timeout** — seconds.
- **API base URL / accounts URL** — overrides for stores whose outbound traffic has to leave through
  an internal proxy. Both parse environment variables.

## Logging

- **Logging enabled** — every call, with endpoint, HTTP status, Zoho's own error code and duration.
- **Log payloads** — full request and response bodies.
- **Retention** — days.

Client secrets, refresh tokens, authorization codes and bearer tokens are redacted **before the row
is written**, not on display, so the log is safe to hand to whoever is debugging.

## Permissions

- **View what has been synced** — the Sync screen and the order panel. Previewing an order's
  payload also needs permission to view that order — it carries the customer's details.
  - **Sync orders to Zoho Books** — the buttons, retries and backfill
  - **Unlink orders from Zoho Books** — forgetting a link. This is the one that can create a
    duplicate if used carelessly: a forgotten link means the next sync has nothing to find.
- **View the connection log** — reading it. Clearing it is admin-only: it is the audit trail.

Connecting, disconnecting and the settings screen are admin-only.
