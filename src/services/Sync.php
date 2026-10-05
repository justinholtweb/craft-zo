<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Queue;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\errors\NotConnectedException;
use justinholtweb\zo\errors\RateLimitException;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\helpers\Money;
use justinholtweb\zo\jobs\SyncOrderJob;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\models\SyncResult;
use justinholtweb\zo\Plugin;

/**
 * Pushing an order into Zoho Books, start to finish.
 *
 * The order of the steps is not arbitrary and not rearrangeable: a Zoho invoice needs a contact
 * id, a payment needs an invoice id, and a credit note needs both. Each step claims its own link
 * before doing anything, so a run that dies halfway resumes from where it stopped rather than
 * starting over — which for an accounting integration is the difference between a retry and a
 * duplicate.
 */
class Sync extends Component
{
    /**
     * Whether this order is one Zo should be sending at all.
     *
     * Deliberately separate from {@see syncOrder()}, which does not consult it: a merchant who
     * clicks "Sync now" on an order Zo would have skipped is making a decision, and the plugin
     * should carry it out rather than second-guess it.
     */
    public function shouldSync(Order $order, ?string &$reason = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$order->isCompleted) {
            $reason = Craft::t('zo', 'The order is not complete.');

            return false;
        }

        if ($settings->minimumOrderTotal > 0 && $order->getTotalPrice() < $settings->minimumOrderTotal) {
            $reason = Craft::t('zo', 'The order total is below the minimum.');

            return false;
        }

        if ($settings->eligibleStatusHandles !== []) {
            $handle = $order->getOrderStatus()?->handle;

            if ($handle === null || !in_array($handle, $settings->eligibleStatusHandles, true)) {
                $reason = Craft::t('zo', 'The order’s status is not one Zo syncs.');

                return false;
            }
        }

        return true;
    }

    /**
     * Hand an order to the queue, or sync it inline if the queue is switched off.
     *
     * Queueing by default matters more here than in most integrations: this runs on
     * `afterCompleteOrder`, which is inside the request where the customer is being told their
     * payment worked. Zoho being slow must never become checkout being slow.
     */
    public function queue(Order $order, bool $force = false): bool
    {
        if ($order->id === null) {
            return false;
        }

        if (!$force && !$this->shouldSync($order)) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->syncViaQueue) {
            $this->syncOrder($order, $force);

            return true;
        }

        Queue::push(new SyncOrderJob([
            'orderId' => $order->id,
            'force' => $force,
        ]));

        return true;
    }

    /**
     * Sync one order, as far as it will go.
     *
     * Never throws for an ordinary API failure — the result carries what happened, step by step,
     * and whether it is worth trying again. Callers are a queue job, a console command and a CP
     * button, and all three want to report rather than explode.
     */
    public function syncOrder(Order $order, bool $force = false): SyncResult
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $links = $plugin->getLinks();

        $result = new SyncResult([
            'orderId' => $order->id,
            'orderNumber' => $order->reference ?: $order->getShortNumber(),
            'craftTotal' => Money::round($order->getTotalPrice()),
        ]);

        if (!$settings->getIsConnected()) {
            $result->addStep('connection', SyncResult::STEP_FAILED, Craft::t('zo', 'Zo is not connected to Zoho Books.'));

            return $result;
        }

        if (!$force) {
            $reason = null;

            if (!$this->shouldSync($order, $reason)) {
                $result->addStep('eligibility', SyncResult::STEP_SKIPPED, (string)$reason);

                return $result;
            }
        }

        // 1. The customer.
        try {
            $contactId = $plugin->getContacts()->syncForOrder($order);
            $result->contactId = $contactId;
            $result->addStep('contact', SyncResult::STEP_OK, '', $contactId);
        } catch (NotConnectedException $e) {
            $result->addStep('contact', SyncResult::STEP_FAILED, $e->getMessage());

            return $result;
        } catch (\Throwable $e) {
            $result->addStep('contact', SyncResult::STEP_FAILED, $e->getMessage());
            $result->retryable = $this->isRetryable($e);

            // Nothing downstream can happen without a contact id.
            return $result;
        }

        // 2. Items, if the store wants per-product reporting.
        $itemIds = [];

        try {
            $itemIds = $plugin->getItems()->syncForOrder($order);
        } catch (\Throwable $e) {
            // Items are best-effort by design; the invoice still goes out with text lines.
            $result->addStep('items', SyncResult::STEP_SKIPPED, $e->getMessage());
        }

        // 3. The document.
        $documentType = $settings->documentType;

        if ($documentType === Settings::DOCUMENT_SALESORDER || $documentType === Settings::DOCUMENT_BOTH) {
            $this->syncSalesOrder($order, $contactId, $itemIds, $result);
        }

        if ($documentType === Settings::DOCUMENT_SALESORDER) {
            // No invoice means nothing for a payment to be applied to. Saying so beats a silent
            // absence of receipts in the books.
            $result->addStep('payments', SyncResult::STEP_SKIPPED, Craft::t('zo', 'Sales orders cannot receive payments.'));

            return $result;
        }

        $invoiceLink = $this->syncInvoice($order, $contactId, $itemIds, $result);

        if (!$invoiceLink->getIsSynced()) {
            return $result;
        }

        // 4. Money.
        if ($settings->syncPayments) {
            $this->syncPayments($order, $invoiceLink, $contactId, $result);
        }

        if ($settings->syncRefunds) {
            $this->syncRefunds($order, $contactId, $result);
        }

        return $result;
    }

    /**
     * Everything Zo knows about this order's relationship with Zoho Books, for the CP panel.
     *
     * @return array{
     *     links: Link[],
     *     invoice: Link|null,
     *     eligible: bool,
     *     reason: string|null,
     *     connected: bool
     * }
     */
    public function getOrderStatus(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $links = $order->id !== null ? $plugin->getLinks()->getLinksForElement($order->id) : [];
        $invoice = null;

        foreach ($links as $link) {
            if ($link->type === Link::TYPE_INVOICE) {
                $invoice = $link;
                break;
            }
        }

        $reason = null;
        $eligible = $this->shouldSync($order, $reason);

        return [
            'links' => $links,
            'invoice' => $invoice,
            'eligible' => $eligible,
            'reason' => $reason,
            'connected' => $plugin->getSettings()->getIsConnected(),
        ];
    }

    /**
     * The exact payload this order would be sent as, without sending it.
     *
     * Built by the same code the sync uses, so it is a preview and not an approximation.
     *
     * @return array<string, mixed>
     */
    public function preview(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $documents = $plugin->getDocuments();
        $contactLink = $order->id !== null ? $this->findInvoiceContactLink($order) : null;

        $payload = $documents->buildInvoicePayload(
            $order,
            $contactLink->zohoId ?? '«contact_id»',
            []
        );

        return [
            'contact' => $documents->buildContactPayload($order),
            'invoice' => $payload,
            'projectedTotal' => $documents->projectedTotal($payload),
            'craftTotal' => Money::round($order->getTotalPrice()),
        ];
    }

    /**
     * Sync every order that has never made it into the books.
     *
     * @param callable(Order, SyncResult): void|null $progress
     * @return array{synced: int, skipped: int, failed: int}
     */
    public function backfill(?\DateTimeInterface $since = null, int $limit = 100, ?callable $progress = null): array
    {
        $counts = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->getUnsyncedOrders($since, $limit) as $order) {
            $result = $this->syncOrder($order);

            if ($result->getIsSuccessful()) {
                $counts['synced']++;
            } elseif ($result->getFailures() === []) {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }

            if ($progress !== null) {
                $progress($order, $result);
            }
        }

        return $counts;
    }

    /**
     * Completed orders with no invoice link, oldest first.
     *
     * Oldest first is the point: a backfill exists to close a gap, and closing it in the order the
     * gap opened keeps Zoho's own document numbering in step with the store's.
     *
     * @return Order[]
     */
    public function getUnsyncedOrders(?\DateTimeInterface $since = null, int $limit = 100): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // Excluded in SQL rather than in PHP so a store with 200,000 orders does not have to load
        // them all to find the twelve that are missing.
        $settled = $this->settledSubquery();

        $orders = [];
        $offset = 0;
        $pageSize = max($limit, 50);

        // Paged with an explicit offset rather than iterated with `each()`: the remaining
        // eligibility rules are PHP, so some of every page is discarded, and a streaming cursor
        // held open across the link-table reads this loop performs is asking for trouble.
        while (count($orders) < $limit) {
            $query = Order::find()
                ->isCompleted(true)
                ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC, 'commerce_orders.id' => SORT_ASC])
                ->andWhere(['not in', 'elements.id', $settled])
                ->offset($offset)
                ->limit($pageSize);

            if ($since !== null) {
                $query->dateOrdered('>= ' . $since->format('Y-m-d H:i:s'));
            }

            $page = $query->all();

            if ($page === []) {
                break;
            }

            foreach ($page as $order) {
                /** @var Order $order */
                if ($settings->minimumOrderTotal > 0 && $order->getTotalPrice() < $settings->minimumOrderTotal) {
                    continue;
                }

                $orders[] = $order;

                if (count($orders) >= $limit) {
                    break;
                }
            }

            $offset += $pageSize;
        }

        return $orders;
    }

    /**
     * How many completed orders have never reached the books.
     *
     * Counted in SQL when it can be — which is whenever no minimum order total is set, i.e. almost
     * always. The alternative, loading up to `$cap` order elements to count them, is what this
     * exists to avoid: it runs on every view of the Sync screen.
     *
     * @return array{count: int, exact: bool}
     */
    public function countUnsyncedOrders(int $cap = 500): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->minimumOrderTotal > 0) {
            $orders = $this->getUnsyncedOrders(null, $cap);

            return ['count' => count($orders), 'exact' => count($orders) < $cap];
        }

        $count = (int)Order::find()
            ->isCompleted(true)
            ->andWhere(['not in', 'elements.id', $this->settledSubquery()])
            ->count();

        return ['count' => $count, 'exact' => true];
    }

    // Private
    // =========================================================================

    /**
     * Orders the link table says are done with — synced, deliberately skipped, or out of retries.
     */
    private function settledSubquery(): Query
    {
        $settings = Plugin::getInstance()->getSettings();

        return (new Query())
            ->select(['elementId'])
            ->from([Table::LINKS])
            ->where(['type' => Link::TYPE_INVOICE])
            ->andWhere(['not', ['elementId' => null]])
            ->andWhere([
                'or',
                ['status' => [Link::STATUS_SYNCED, Link::STATUS_SKIPPED]],
                ['>=', 'attempts', $settings->maxAttempts],
            ]);
    }

    /**
     * @param array<int, string> $itemIds
     */
    private function syncInvoice(Order $order, string $contactId, array $itemIds, SyncResult $result): Link
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $links = $plugin->getLinks();
        $link = $links->claim(Link::TYPE_INVOICE, Link::key('order', (int)$order->id), $order->id);

        if ($link->getIsSynced()) {
            $result->invoiceId = $link->zohoId;
            $result->invoiceNumber = $link->zohoNumber;
            $result->zohoTotal = $link->zohoTotal;
            $result->variance = $link->variance;
            $result->addStep('invoice', SyncResult::STEP_REUSED, Craft::t('zo', 'Invoice {number} already exists.', [
                'number' => $link->zohoNumber ?: $link->zohoId,
            ]), $link->zohoId);

            return $link;
        }

        $documents = $plugin->getDocuments();
        $payload = $documents->buildInvoicePayload($order, $contactId, $itemIds);
        $query = [];

        // Zoho will not accept a number of your choosing unless you tell it to stop generating
        // its own. Without this the invoice is created with Zoho's next number and the reference
        // Zo asked for is discarded — silently.
        if (isset($payload['invoice_number'])) {
            $query['ignore_auto_number_generation'] = 'true';
        }

        try {
            $response = $plugin->getApi()->post('invoices', $payload, $query, [
                'action' => 'invoice',
                'elementId' => $order->id,
            ]);
        } catch (\Throwable $e) {
            $links->markFailed($link, $e->getMessage());
            $result->addStep('invoice', SyncResult::STEP_FAILED, $e->getMessage());
            $result->retryable = $this->isRetryable($e);

            return $link;
        }

        $invoice = $response['invoice'] ?? [];
        $invoiceId = (string)($invoice['invoice_id'] ?? '');

        if ($invoiceId === '') {
            $message = Craft::t('zo', 'Zoho created an invoice but returned no ID for it.');
            $links->markFailed($link, $message);
            $result->addStep('invoice', SyncResult::STEP_FAILED, $message);

            return $link;
        }

        $craftTotal = Money::round($order->getTotalPrice());
        $zohoTotal = Money::round((float)($invoice['total'] ?? 0));

        $links->markSynced($link, $invoiceId, (string)($invoice['invoice_number'] ?? ''), $craftTotal, $zohoTotal);

        $result->invoiceId = $invoiceId;
        $result->invoiceNumber = $link->zohoNumber;
        $result->zohoTotal = $zohoTotal;
        $result->variance = $link->variance;
        $result->addStep('invoice', SyncResult::STEP_OK, '', $invoiceId);

        $this->checkVariance($order, $link, $result);
        $this->finaliseInvoice($order, $invoiceId, $result);

        return $link;
    }

    /**
     * @param array<int, string> $itemIds
     */
    private function syncSalesOrder(Order $order, string $contactId, array $itemIds, SyncResult $result): void
    {
        $plugin = Plugin::getInstance();
        $links = $plugin->getLinks();
        $link = $links->claim(Link::TYPE_SALESORDER, Link::key('order', (int)$order->id), $order->id);

        if ($link->getIsSynced()) {
            $result->addStep('salesorder', SyncResult::STEP_REUSED, '', $link->zohoId);

            return;
        }

        $payload = $plugin->getDocuments()->buildSalesOrderPayload($order, $contactId, $itemIds);
        $query = isset($payload['salesorder_number']) ? ['ignore_auto_number_generation' => 'true'] : [];

        try {
            $response = $plugin->getApi()->post('salesorders', $payload, $query, [
                'action' => 'salesorder',
                'elementId' => $order->id,
            ]);
        } catch (\Throwable $e) {
            $links->markFailed($link, $e->getMessage());
            $result->addStep('salesorder', SyncResult::STEP_FAILED, $e->getMessage());
            $result->retryable = $this->isRetryable($e);

            return;
        }

        $salesOrder = $response['salesorder'] ?? [];
        $id = (string)($salesOrder['salesorder_id'] ?? '');

        if ($id === '') {
            $links->markFailed($link, Craft::t('zo', 'Zoho created a sales order but returned no ID for it.'));
            $result->addStep('salesorder', SyncResult::STEP_FAILED, Craft::t('zo', 'Zoho created a sales order but returned no ID for it.'));

            return;
        }

        $links->markSynced(
            $link,
            $id,
            (string)($salesOrder['salesorder_number'] ?? ''),
            Money::round($order->getTotalPrice()),
            Money::round((float)($salesOrder['total'] ?? 0))
        );

        $result->addStep('salesorder', SyncResult::STEP_OK, '', $id);
    }

    /**
     * Move the invoice out of draft, and optionally have Zoho email it.
     *
     * A draft invoice is invisible to Zoho's ageing reports and to the customer, so a store that
     * left this off would see its receivables stay at zero while its bank balance grew.
     */
    private function finaliseInvoice(Order $order, string $invoiceId, SyncResult $result): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($settings->markInvoiceSent) {
            try {
                $plugin->getApi()->post("invoices/{$invoiceId}/status/sent", [], [], [
                    'action' => 'invoice-sent',
                    'elementId' => $order->id,
                ]);
            } catch (\Throwable $e) {
                // The invoice exists. Failing to promote it out of draft is a warning, not a
                // reason to leave the order looking unsynced and invite a duplicate.
                $result->addStep('invoice-sent', SyncResult::STEP_SKIPPED, $e->getMessage());
            }
        }

        if ($settings->emailInvoice) {
            try {
                $plugin->getApi()->post("invoices/{$invoiceId}/email", [], [], [
                    'action' => 'invoice-email',
                    'elementId' => $order->id,
                ]);
                $result->addStep('invoice-email', SyncResult::STEP_OK);
            } catch (\Throwable $e) {
                $result->addStep('invoice-email', SyncResult::STEP_SKIPPED, $e->getMessage());
            }
        }
    }

    /**
     * Commerce's successful payments as Zoho receipts.
     */
    private function syncPayments(Order $order, Link $invoiceLink, string $contactId, SyncResult $result): void
    {
        $plugin = Plugin::getInstance();
        $links = $plugin->getLinks();
        $documents = $plugin->getDocuments();
        $remaining = Money::round($order->getTotalPrice());
        $synced = 0;

        foreach ($this->getPaymentTransactions($order) as $transaction) {
            $link = $links->claim(Link::TYPE_PAYMENT, Link::key('transaction', (int)$transaction->id), $order->id);

            if ($link->getIsSynced()) {
                $remaining = Money::round($remaining - (float)$transaction->amount);
                continue;
            }

            // Never hand Zoho more than the invoice is worth. An over-application is rejected, and
            // a store that captures in parts (auth then several captures) can otherwise total to
            // more than the invoice through rounding alone.
            $applied = Money::round(min((float)$transaction->amount, max(0, $remaining)));

            if ($applied <= 0) {
                $links->markSkipped($link, Craft::t('zo', 'The invoice is already fully paid in Zoho.'));
                continue;
            }

            $payload = $documents->buildPaymentPayload($transaction, $contactId, (string)$invoiceLink->zohoId, $applied);

            try {
                $response = $plugin->getApi()->post('customerpayments', $payload, [], [
                    'action' => 'payment',
                    'elementId' => $order->id,
                ]);
            } catch (\Throwable $e) {
                $links->markFailed($link, $e->getMessage());
                $result->addStep('payment', SyncResult::STEP_FAILED, $e->getMessage());
                $result->retryable = $result->retryable || $this->isRetryable($e);
                continue;
            }

            $payment = $response['payment'] ?? [];
            $paymentId = (string)($payment['payment_id'] ?? '');

            if ($paymentId === '') {
                $links->markFailed($link, Craft::t('zo', 'Zoho recorded a payment but returned no ID for it.'));
                continue;
            }

            $links->markSynced($link, $paymentId, (string)($payment['payment_number'] ?? ''), $applied, Money::round((float)($payment['amount'] ?? $applied)));
            $remaining = Money::round($remaining - $applied);
            $synced++;
        }

        if ($synced > 0) {
            $result->addStep('payments', SyncResult::STEP_OK, Craft::t('zo', '{count} recorded', ['count' => $synced]));
        }
    }

    /**
     * Commerce's refunds as Zoho credit notes.
     *
     * Not as refunds against the customer payment: Zoho only refunds a payment whose mode is
     * `autotransaction`, and only the unapplied part of it — which is never the case for a payment
     * that has been applied to an invoice. The credit note reverses the revenue, and a second call
     * records the cash leaving, when there is an account to take it from.
     */
    private function syncRefunds(Order $order, string $contactId, SyncResult $result): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $links = $plugin->getLinks();
        $documents = $plugin->getDocuments();
        $synced = 0;

        foreach ($this->getRefundTransactions($order) as $transaction) {
            $link = $links->claim(Link::TYPE_REFUND, Link::key('transaction', (int)$transaction->id), $order->id);

            if ($link->getIsSynced()) {
                continue;
            }

            $payload = $documents->buildCreditNotePayload($transaction, $order, $contactId);

            try {
                $response = $plugin->getApi()->post('creditnotes', $payload, [], [
                    'action' => 'creditnote',
                    'elementId' => $order->id,
                ]);
            } catch (\Throwable $e) {
                $links->markFailed($link, $e->getMessage());
                $result->addStep('refund', SyncResult::STEP_FAILED, $e->getMessage());
                $result->retryable = $result->retryable || $this->isRetryable($e);
                continue;
            }

            $creditNote = $response['creditnote'] ?? [];
            $creditNoteId = (string)($creditNote['creditnote_id'] ?? '');

            if ($creditNoteId === '') {
                $links->markFailed($link, Craft::t('zo', 'Zoho created a credit note but returned no ID for it.'));
                continue;
            }

            $links->markSynced(
                $link,
                $creditNoteId,
                (string)($creditNote['creditnote_number'] ?? ''),
                Money::round(abs((float)$transaction->amount)),
                Money::round((float)($creditNote['total'] ?? 0))
            );

            $this->openCreditNote($order, $creditNoteId);
            $this->refundCreditNote($order, $transaction, $creditNoteId, $result);
            $synced++;
        }

        if ($synced > 0) {
            $result->addStep('refunds', SyncResult::STEP_OK, Craft::t('zo', '{count} recorded', ['count' => $synced]));
        }
    }

    private function openCreditNote(Order $order, string $creditNoteId): void
    {
        try {
            Plugin::getInstance()->getApi()->post("creditnotes/{$creditNoteId}/status/open", [], [], [
                'action' => 'creditnote-open',
                'elementId' => $order->id,
            ]);
        } catch (\Throwable $e) {
            Craft::warning('Zo could not open a Zoho credit note: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function refundCreditNote(Order $order, Transaction $transaction, string $creditNoteId, SyncResult $result): void
    {
        $accountId = trim(Plugin::getInstance()->getSettings()->depositAccountId);

        // Zoho requires the account the money left from and offers no default. Without one, the
        // credit note stands on its own — the revenue is reversed and the customer's balance is
        // right; only the cash movement is missing.
        if ($accountId === '') {
            $result->addStep('refund-cash', SyncResult::STEP_SKIPPED, Craft::t(
                'zo',
                'The credit note was created, but no deposit account is configured, so the refund payment was not recorded.'
            ));

            return;
        }

        try {
            Plugin::getInstance()->getApi()->post(
                "creditnotes/{$creditNoteId}/refunds",
                Plugin::getInstance()->getDocuments()->buildCreditNoteRefundPayload($transaction, $accountId),
                [],
                ['action' => 'creditnote-refund', 'elementId' => $order->id]
            );
        } catch (\Throwable $e) {
            $result->addStep('refund-cash', SyncResult::STEP_SKIPPED, $e->getMessage());
        }
    }

    /**
     * Compare what Zoho totalled the invoice to against what the customer actually paid.
     *
     * This is the check the whole tax-mode design exists to make possible. A silent penny of drift
     * per order is a reconciliation the merchant discovers at year end; a flagged one is a
     * setting they fix on the first order.
     */
    private function checkVariance(Order $order, Link $link, SyncResult $result): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$link->getHasVariance($settings->varianceTolerance)) {
            return;
        }

        $message = Craft::t('zo', 'Zoho totalled this invoice at {zoho}, but Commerce charged {craft} — a difference of {variance}.', [
            'zoho' => Money::format((float)$link->zohoTotal),
            'craft' => Money::format((float)$link->craftTotal),
            'variance' => Money::format((float)$link->variance),
        ]);

        Plugin::getInstance()->getLog()->write('variance', [
            'level' => LogEntry::LEVEL_WARNING,
            'elementId' => $order->id,
            'summary' => $message,
        ]);

        $result->addStep(
            'variance',
            $settings->blockOnVariance ? SyncResult::STEP_FAILED : SyncResult::STEP_SKIPPED,
            $message
        );
    }

    /**
     * Successful money-in transactions, oldest first.
     *
     * An authorization is not money; only its capture is. Counting both would double every order
     * taken on a gateway that authorises and captures separately.
     *
     * @return Transaction[]
     */
    private function getPaymentTransactions(Order $order): array
    {
        $transactions = array_filter($order->getTransactions(), static fn(Transaction $transaction) =>
            in_array($transaction->type, [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_CAPTURE], true)
            && $transaction->status === TransactionRecord::STATUS_SUCCESS
            && (float)$transaction->amount > 0);

        usort($transactions, static fn(Transaction $a, Transaction $b) => ($a->id ?? 0) <=> ($b->id ?? 0));

        return $transactions;
    }

    /**
     * @return Transaction[]
     */
    private function getRefundTransactions(Order $order): array
    {
        $transactions = array_filter($order->getTransactions(), static fn(Transaction $transaction) =>
            $transaction->type === TransactionRecord::TYPE_REFUND
            && $transaction->status === TransactionRecord::STATUS_SUCCESS
            && abs((float)$transaction->amount) > 0);

        usort($transactions, static fn(Transaction $a, Transaction $b) => ($a->id ?? 0) <=> ($b->id ?? 0));

        return $transactions;
    }

    /**
     * The contact link for this order's customer.
     *
     * Looked up by the contact's own identity rather than by the order's element id: a contact
     * link belongs to the *customer*, and is shared by every order they have ever placed.
     */
    private function findInvoiceContactLink(Order $order): ?Link
    {
        return Plugin::getInstance()->getLinks()->find(
            Link::TYPE_CONTACT,
            Plugin::getInstance()->getContacts()->keyForOrder($order)
        );
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof RateLimitException) {
            return true;
        }

        if ($e instanceof ZohoApiException) {
            return $e->isRetryable();
        }

        return !$e instanceof NotConnectedException;
    }
}
