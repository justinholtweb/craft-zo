---
title: Alerts
slug: alerts
order: 35
summary: One email (and optionally a Slack or Teams message) when orders fail, a document does not reconcile or Zoho refuses the connection — and one when it clears.
---

# Alerts

The Sync screen knows everything that has gone wrong, but nobody opens an integration's admin
screen on a day it seems to be working. Zo tells you instead, when one of three things happens:

| Incident | Opens when | Clears when |
|---|---|---|
| **Orders failing to sync** | `alertFailureThreshold` failures (default 1) inside `alertWindowMinutes` (default 60): an order's customer, invoice, sales order, payment or refund that Zoho refused | a whole window passes with no new failure |
| **Documents not reconciling** | a document synced inside the window has a Zoho total that differs from Commerce's by more than **Variance tolerance** | a whole window passes with no new one |
| **Zoho refused the connection** | Zoho refuses the refresh token, or answers 401 to a token Zo has just refreshed | the next authenticated request succeeds |

You get **one message when an incident starts and one when it clears**, never one per failure.
Each incident has a single latch row in the database: checking it a hundred times while it is
still open sends nothing new. If it reopens within `alertCooldownMinutes` (default 60) of its
recovery message, you hear about it when that quiet period ends, and only if it is still happening.

A recovery means nothing *new* has gone wrong for a whole window — not that everything is fixed.
It says how many documents still show as failed, or still do not reconcile, on the Sync screen.

Items never alert: item sync is best-effort, and the invoice goes out with text lines when an item
cannot be created. A network failure or a 500 is not a refused connection either: those retry, and
if they keep failing they become sync failures, which is the first incident.

## Setting it up

**Settings → Zo → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each webhook carries `X-Zo-Timestamp` and `X-Zo-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnFailures` | on | |
| `alertFailureThreshold` | 1 | |
| `alertWindowMinutes` | 60 | Used by both failures and reconciliation |
| `alertOnVariance` | on | |
| `alertOnAuthFailure` | on | |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

Mail goes through Craft's own mailer, so it uses whatever **Settings → Email** is set to. Press
**Send a test alert** (admins only) or run `php craft zo/alerts/test` after saving to check that
both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents and
shows them on the Dashboard widget. If you add a recipient later, any incident that is still open
is sent at the next check. An environment that has never connected to Zoho checks nothing.

## When alerts are checked

- **At the end of every sync** — queue, console, **Sync now** or the bulk action: failures and
  reconciliation. No cron needed.
- **The moment Zoho refuses the connection**: authentication.
- **`php craft zo/alerts/check`** and **`php craft zo/sync/retry`**: everything.

An incident can only be seen to *clear* when something checks. On a store that syncs every day
that happens by itself; on a quiet one, put the check on cron:

```sh
*/15 * * * * cd /path/to/site && php craft zo/alerts/check >> /dev/null 2>&1
```

## What an alert says

A plain-text email: the site, the incident, what was seen, and links straight to the right screen —
the Sync screen filtered to failures or to unreconciled documents, or Zo's settings for a refused
connection. The Slack message, Teams card and JSON event carry the same.

What was seen is redacted before it leaves the site: the client secret, the refresh token and the
current access token are removed by value, anything shaped like a credential (`Zoho-oauthtoken …`,
`Bearer …`, `refresh_token=…`, `"client_secret": …`) by pattern, markup is stripped and the line is
capped at 500 characters. Alerts quote Zoho's error and a document number; never a customer's
details.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "zo.alert.opened",
  "incident": "failures",
  "site": "My Store",
  "title": "Orders failing to sync in Zoho Books",
  "detail": "1 sync failures in the last 60 minutes; 1 documents show as failed. Latest: invoice order:1042: Zoho Books: Invalid value passed for customer_id",
  "url": "https://example.com/admin/zo/sync?status=failed",
  "syncUrl": "https://example.com/admin/zo/sync",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `zo.alert.recovered` when it clears; `incident` is `failures`, `variance` or `auth`.

The URL is checked every time it is used, not only when it is saved. It must be `http` or `https`
with no username or password in it, every address the host resolves to must be public — not
private, loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the request
is pinned to those addresses so DNS cannot be switched between the check and the send. Redirects
are never followed. For a self-hosted Mattermost on your own network, set
`allowPrivateAlertWebhookHosts` in `config/zo.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The Dashboard widget

**Dashboard → New widget → Zoho Books health** shows whether this environment is connected, how
many documents are synced, failed and not reconciled (each linking to the Sync screen filtered to
them), how many completed orders are still waiting, when the last invoice went over, and any open
incident — hover it to see what was seen. It reads the same latch rows the alerts come from, so the
widget and your inbox cannot disagree. Only people with *View what has been synced* can add it.

## Changing or suppressing an alert

```php
use justinholtweb\zo\events\AlertEvent;
use justinholtweb\zo\services\Alerts;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail
    $e->subject = '[Books] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->incident === Alerts::INCIDENT_VARIANCE && !$e->recovered) {
        $e->isValid = false;
    }
});
```
