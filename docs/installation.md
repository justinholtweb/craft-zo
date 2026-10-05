---
title: Installation
slug: installation
order: 10
summary: Requirements, install, price, and connecting Zo to your Zoho Books organization.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A Zoho Books organization, on any of Zoho's eight data centres

## Install

```sh
composer require justinholtweb/craft-zo
php craft plugin/install zo
```

Or find **Zo** in the Craft Plugin Store and install it from there.

## Nothing is sent until you connect

Installing Zo does not touch your store. It creates its two tables, adds a **Zo** section to the
control panel and a panel on Commerce's order edit screen, and then waits. No order is synced, and
no request leaves your server, until you have connected an organization and turned syncing on.

## 1. Create a Zoho application

In the [Zoho API console](https://api-console.zoho.com), create a **Server-based Application**.

Zo's settings screen prints the exact **Authorized Redirect URI** to paste into Zoho. Copy it from
there rather than typing it — Zoho matches the redirect character for character, including the
scheme and any trailing slash, and a mismatch fails at the very end of the authorization with a
message that does not say which part was wrong.

Zoho hands you a **Client ID** and **Client Secret**.

## 2. Connect

Paste the client ID and secret into **Settings → Zo**, save, and press **Connect to Zoho Books**.
Zoho asks you to approve the scopes and sends you back with a refresh token, which Zo stores and
uses from then on — **encrypted, in its own database table, never in project config**, because
project config is committed with your site and that token reads and writes your books.

**Each environment connects for itself**, including the live site: Connect works there even with
admin changes off, when the rest of the settings screen is read-only. The client ID and secret are
settings, so they deploy as usual; the connection does not. To disconnect where the screen is
read-only, run `php craft zo/auth/disconnect`.

Two things happen automatically here that are worth knowing about:

- **Zo asks only for the scopes your configuration needs** — never `fullaccess`. Switching on
  payment sync, item sync or sales orders later widens the scope set, so reconnect after changing
  what Zo does or those calls will come back as permission errors.
- **The data centre corrects itself.** If your Zoho account lives outside the US, you can set the
  data centre first — but you do not have to. Zoho's redirect names the region you actually
  authorized against, and Zo keeps that alongside the connection, where it beats the setting. The
eight data centres do not share
  data, so a token minted at one is a bare `401` at another; this is the misconfiguration Zo is
  built to make a non-event.

## 3. Keep the credentials out of project config

Plugin settings live in project config, and project config gets committed. Put the client ID and
secret in your `.env` and enter `$ZOHO_CLIENT_ID` and `$ZOHO_CLIENT_SECRET`; every credential field
parses environment variables.

```
ZOHO_CLIENT_ID=1000.XXXXXXXX
ZOHO_CLIENT_SECRET=xxxxxxxx
ZOHO_ORG_ID=10234695
```

Leave **Refresh token** empty — connecting stores it for you. It is only an override, for a site
that would rather manage the token itself: enter `$ZOHO_REFRESH_TOKEN`, never the token, which Zo
refuses there.

**Upgrading from 5.0.0?** That version saved the token into the setting, so it is in your
repository. It keeps working, but connect once on each environment that syncs, then tick **Remove
it from project config** on the settings screen and save. If the repository has been shared,
disconnect first: that revokes the old token at Zoho.

The **organization ID** matters as much as the credentials. Every Books API call carries one, and
omitting it is not an error — Zoho answers for whichever organization it feels like, which on a
multi-entity account files your invoices against the wrong company. Zo always sends it explicitly.
When the connected account has exactly one organization, Zo picks it on connecting; set it here to
choose one yourself.

## 4. Check it before you trust it

The settings screen has a **Preview payload** button. It builds the JSON your most recent order
would be sent as — using the same builders the sync itself uses, so the preview is byte-identical
to what Zoho would receive — and tells you whether the totals line up. Nothing is sent.

Then run the connection tests:

```sh
php craft zo/auth/test      # the credentials reach the right organization
php craft zo/auth/refresh   # the refresh token still mints access tokens
```

## Price

Zo is **$99**, once, with a **$79/year renewal** for continued updates. There is no free edition
and no feature held back for an upgrade — every store gets payments, refunds, item sync, sales
orders, mapped tax, custom fields, backfill and the full log.

Renewing is optional in the sense that the plugin keeps working if you don't; it is what buys you
the next year of updates.

## Existing orders

Installing Zo does not push anything that completed before it arrived. To bring an existing store
into the books, use [Backfill](usage#backfill) — and read the order of operations there first,
because the contact-matching setting is what stops a backfill shadowing every customer you already
have in Zoho.

## Trademarks

Zo is an independent plugin built by Justin Holt. It is **not affiliated with, endorsed by, or
sponsored by Zoho Corporation**. “Zoho” and “Zoho Books” are trademarks of Zoho Corporation, used
here only to identify the service Zo connects to.
