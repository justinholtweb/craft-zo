---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Connections that die after an hour, 401s that are really the wrong region, and totals that do not reconcile.
---

## Start here

Two commands and one screen answer most of it:

```sh
php craft zo/auth/test      # do the credentials reach the right organization?
php craft zo/auth/refresh   # does the refresh token still mint access tokens?
php craft zo/sync/status    # how much of the store is in the books?
```

Then read **Zo → Log**. Remember that Zoho answers some failures with **HTTP 200** — the accounts
server does it for a bad grant, and the Books API does it for some rejected writes. The `code`
column is the verdict, not the status column.


## Connected on one environment, “not connected” on another

Since 5.0.1 the connection is not a setting, so it does not deploy: the refresh token is stored,
encrypted, in the database of the environment that connected. **Connect on each environment that
syncs** — the live site included, where Connect works even though the rest of the settings screen is
read-only. `php craft zo/auth/test` says where the token it is using came from.

If the site's security key has changed since connecting, the stored token can no longer be
decrypted; the settings screen says so. Connect again.
## The connection worked and then died about an hour later

You got an access token but no refresh token, so Zo had exactly one hour of access.

This happens on a **repeat** authorization: Zoho only returns a refresh token for an app you have
already approved if the authorization request says `prompt=consent`. Zo sends it, but a
half-finished reconnection, or an authorization started somewhere other than Zo's button, will not
have.

**Fix:** press **Connect to Zoho Books** again from Zo's settings screen and complete the flow.

## Everything returns 401, and the credentials are definitely right

You are almost certainly pointed at the wrong data centre. Zoho's eight regions **do not share
data**, and a token minted at one is a bare `401` at another — no message, no hint.

**Fix:** reconnect. Zoho's redirect names the region you actually authorized against, and Zo writes
that back to the data centre setting for you. This is a two-minute problem if you reconnect and an
afternoon if you try to reason about it.

## The redirect URI is rejected

Zoho matches the redirect **character for character**, including the scheme and any trailing
element. Copy it from Zo's settings screen rather than typing it — the screen prints the exact
string, and on a multi-site install it has already removed the `?site=` parameter Craft would
otherwise append.

## Invoices are going to the wrong company

Your organization ID is missing or wrong. Every Books call carries one, and **omitting it is not an
error** — Zoho answers for whichever organization it likes, which on a multi-entity account means
your invoices are filed against the wrong entity.

**Fix:** set **Organization ID** (or `ZOHO_ORG_ID`) explicitly and run `php craft zo/auth/test`,
which reports which organization it reached.

## Some calls fail with a permission error after I changed a setting

Zo asks Zoho only for the scopes your configuration needs — never `fullaccess`. Switching on
payment sync, refunds, item sync or sales orders **widens** the scope set, and the refresh token
you already hold was minted under the narrower one.

**Fix:** reconnect after changing what Zo does.

## "Not reconciled" is not zero

Commerce's total and Zoho's total disagree on those documents. Both numbers are on the link row,
side by side, so open one and look at the difference before changing anything.

- **In `mapped` tax mode**, this is Zoho computing tax differently from Commerce. Either fix the
  tax map, or move to `adjustment`, where Zo sends no rates and the total is correct by
  construction.
- **In `adjustment` mode**, a variance usually means something reached the order after the payload
  was built, or a rounding setting differs.
- **Tiny variances** — raise **Variance tolerance** past a cent if your store genuinely rounds
  differently from Zoho.

Documents already written are not corrected retroactively. Fix the setting, then decide per
document.

## A refund made a credit note but no money moved

You have no **deposit account** configured. Recording the cash side of a refund requires an account
id and Zoho offers no default, so Zo writes the credit note — which reverses the revenue, the part
that matters for your books — and stops.

**Fix:** set the deposit account in the payments section, then re-run the refund's sync.

## Payments were recorded without a bank charge

**Record processor fees as bank charges** is on, but the fee was not in what the gateway stored.
Commerce's Stripe gateway usually saves the payment intent with its charge as a bare id rather than
the charge's balance transaction, and that is where Stripe keeps the fee. Zo reads stored responses
only and never calls the processor, so it records the payment without one rather than guess. Supply
the fee from `Documents::EVENT_DEFINE_PROCESSOR_FEE` — see
[Configuration](../configuration#where-the-fee-comes-from). A fee in a different currency from the
payment is also skipped on purpose.

## No alert arrived

Press **Send a test alert** under **Settings → Zo → Alerts** (or run `php craft zo/alerts/test`).
It reports email and webhook separately.

- **Email failed** — Zo sends through Craft's own mailer; check **Settings → Email**.
- **Webhook refused** — the URL must be `http`/`https`, carry no username or password, and resolve
  only to public addresses. A Mattermost on your own network needs `allowPrivateAlertWebhookHosts`
  in `config/zo.php`.
- **Nothing to send** — an `$ENV` reference that is not set on this environment means nobody.

An incident sends one message when it starts and one when it clears, not one per failure — if one
is already open, the next failure is part of it. The **Zoho Books health** widget shows what is
open. See [Alerts](../alerts).

## A payment was rejected outright

Zoho's `payment_mode` is a **closed vocabulary of seven values**, and anything else is a 400. If a
Commerce gateway handle is not in the map, Zo sends the default payment mode; if the default has
been edited to something Zoho does not recognise, every payment fails.

**Fix:** map your gateway handles onto Zoho's vocabulary in the settings screen.

## A customer's address came through with no country

Zoho matches countries **by name, not by ISO code**, and drops an unrecognised one silently. A
Commerce address storing `US` rather than `United States` loses its country on the Zoho contact.

## Duplicate contacts appeared for customers I already had

**Reuse an existing Zoho contact with the same email** was off when you backfilled. Zoho contact
names are unique, so Zo adopts a contact when Zoho says one already exists — but only matching by
email finds the ones whose names differ slightly.

Turn the setting on before backfilling. It is on by default for exactly this reason.

## Two invoices for one order

This should not be possible: the unique index on the link table is what prevents it. The way it
happens in practice is that a link was **unlinked** — deliberately, from the order panel — and the
order was then synced again, so the second sync had nothing to find.

Unlink is permission-gated for this reason. Void the extra document in Zoho.

## Syncs stopped and the log is full of 429s

Zoho reports both of its rate limits as a `429`:

- **code 44 or 45, per minute** — 100 calls a minute per organization. Zo defaults to 90 and paces
  itself; this appears when something *else* is also talking to the same organization. Lower
  **Requests per minute**.
- **the daily cap** — plan-dependent, and it will **not** clear by waiting a few seconds. A large
  backfill can reach it. Leave it until tomorrow and let the queue resume.

## An order never synced at all

Work down the eligibility rules: is the order **completed**; does it clear the **minimum order
total**; is its status in the **eligible statuses** list; is the trigger you expect
(complete / paid / status) actually the one switched on?

Then `php craft zo/sync/order <id> --dry-run` to see what it would send, and `--force` to bypass
the eligibility rules entirely and find out whether the rules were the problem.

## Checkout broke when Zoho went down

It should not have, and this is worth reporting. Anything Zo runs during checkout fails **open** —
Zoho being unreachable must never stop a customer paying — which is why **Sync via the queue** is
the default. If it is switched off, turn it back on.

## Something changed and I want to see the payload

**Preview payload**, on the settings screen or on any order's panel, and `--dry-run` on the console
all call the same builders the sync calls. Whatever they print is byte-for-byte what Zoho would
receive.
