<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\Transaction;
use craft\elements\Address;
use justinholtweb\zo\helpers\Money;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\Plugin;

/**
 * Turns Commerce records into Zoho Books payloads.
 *
 * **Every payload Zo sends is built here and nowhere else.** The CP's "Preview payload" button
 * calls the same methods the sync does, so what a merchant inspects before connecting a live
 * organization is byte-identical to what Zoho will receive — the alternative is a preview that
 * reassures and a sync that does something else.
 *
 * Nothing in this class performs I/O. That is what makes the mapping testable without a Zoho
 * account, and it is why the item and tax lookups arrive as arguments rather than being fetched.
 */
class Documents extends Component
{
    /** Zoho rejects an invoice or reference number longer than this. */
    public const MAX_NUMBER_LENGTH = 100;

    /** Zoho truncates a line item name at 100 characters; doing it here keeps the books tidy. */
    public const MAX_NAME_LENGTH = 100;

    public const MAX_DESCRIPTION_LENGTH = 2000;

    /**
     * A Zoho contact built from the order's billing details.
     *
     * @return array<string, mixed>
     */
    public function buildContactPayload(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $name = $this->contactName($order);

        $payload = [
            'contact_name' => $this->truncate($name, 200),
            'contact_type' => 'customer',
        ];

        $organization = $billing?->organization ?: $shipping?->organization ?: null;

        if ($organization) {
            $payload['company_name'] = $this->truncate($organization, 200);
        }

        if ($settings->inferBusinessCustomers) {
            $payload['customer_sub_type'] = $organization ? 'business' : 'individual';
        }

        if ($billing !== null) {
            $payload['billing_address'] = $this->addressPayload($billing);
        }

        if ($shipping !== null) {
            $payload['shipping_address'] = $this->addressPayload($shipping);
        }

        $email = $order->getEmail();

        // Zoho puts the email on a contact *person*, not on the contact. A contact created without
        // one cannot be emailed an invoice and shows as blank in every Zoho list.
        $person = array_filter([
            'first_name' => $this->truncate($billing?->firstName ?? $this->firstWord($name), 100),
            'last_name' => $this->truncate($billing?->lastName ?? $this->restOfWords($name), 100),
            'email' => $email ?: null,
            'phone' => $this->phone($billing) ?: $this->phone($shipping) ?: null,
            'is_primary_contact' => true,
        ], static fn($value) => $value !== null && $value !== '');

        if ($person !== []) {
            $person['is_primary_contact'] = true;
            $payload['contact_persons'] = [$person];
        }

        return $payload;
    }

    /**
     * An order as a Zoho Books invoice.
     *
     * @param array<int, string> $itemIds purchasable id => Zoho item id, for stores syncing items
     * @return array<string, mixed>
     */
    public function buildInvoicePayload(Order $order, string $customerId, array $itemIds = []): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $payload = [
            'customer_id' => $customerId,
            'date' => $this->orderDate($order),
            'line_items' => $this->buildLineItems($order, $itemIds),
        ];

        $reference = $this->referenceNumber($order);

        if ($reference !== '') {
            $payload['reference_number'] = $reference;
        }

        $number = $this->documentNumber($order);

        if ($number !== null) {
            $payload['invoice_number'] = $number;
        }

        if ($settings->paymentTermsDays > 0) {
            $payload['payment_terms'] = $settings->paymentTermsDays;
            $payload['due_date'] = $this->orderDate($order, $settings->paymentTermsDays);
        }

        if ($settings->includeShipping) {
            $shipping = Money::round($order->getTotalShippingCost());

            if ($shipping != 0.0) {
                $payload['shipping_charge'] = $shipping;
            }
        }

        if ($settings->includeDiscount) {
            // Commerce keeps discounts as negative adjustments; Zoho wants a positive number and
            // a type telling it what the number means.
            $discount = Money::round(abs($order->getTotalDiscount()));

            if ($discount > 0) {
                $payload['discount'] = $discount;
                $payload['discount_type'] = 'entity_level';
                $payload['is_discount_before_tax'] = true;
            }
        }

        $this->applyTax($order, $payload);
        $this->applyNotes($order, $payload);
        $this->applyCustomFields($order, $payload);

        if ($order->currency) {
            $payload['currency_code'] = $order->currency;
        }

        return $payload;
    }

    /**
     * The same order as a sales order. Zoho's two documents share a body, and the differences
     * (`salesorder_number`, no payment terms) are small enough that keeping one builder is safer
     * than keeping two that drift.
     *
     * @param array<int, string> $itemIds
     * @return array<string, mixed>
     */
    public function buildSalesOrderPayload(Order $order, string $customerId, array $itemIds = []): array
    {
        $payload = $this->buildInvoicePayload($order, $customerId, $itemIds);

        if (isset($payload['invoice_number'])) {
            $payload['salesorder_number'] = $payload['invoice_number'];
            unset($payload['invoice_number']);
        }

        unset($payload['payment_terms'], $payload['due_date']);

        return $payload;
    }

    /**
     * A Commerce payment transaction as a Zoho customer payment applied to the invoice.
     *
     * @return array<string, mixed>
     */
    public function buildPaymentPayload(Transaction $transaction, string $customerId, string $invoiceId, float $amountApplied): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $payload = [
            'customer_id' => $customerId,
            'payment_mode' => $this->paymentMode($transaction),
            'amount' => Money::round((float)$transaction->amount),
            'date' => $this->transactionDate($transaction),
            'invoices' => [
                [
                    'invoice_id' => $invoiceId,
                    'amount_applied' => Money::round($amountApplied),
                ],
            ],
        ];

        // The gateway's own reference is the thread back to the payment processor. Without it,
        // reconciling a Zoho receipt against a Stripe payout is manual work.
        if ($transaction->reference) {
            $payload['reference_number'] = $this->truncate((string)$transaction->reference, self::MAX_NUMBER_LENGTH);
        }

        $gateway = $this->gatewayName($transaction);
        $payload['description'] = $this->truncate(Craft::t('zo', 'Craft Commerce order {number}{gateway}', [
            'number' => $transaction->getOrder()?->reference ?? $transaction->getOrder()?->getShortNumber() ?? '',
            'gateway' => $gateway !== '' ? " · {$gateway}" : '',
        ]), self::MAX_DESCRIPTION_LENGTH);

        if ($settings->depositAccountId !== '') {
            $payload['account_id'] = $settings->depositAccountId;
        }

        return $payload;
    }

    /**
     * Money leaving the business against an open credit note.
     *
     * Zoho keeps the two halves of a refund apart, and it is right to: the credit note reverses
     * the revenue, this records the cash going back. `from_account_id` is required by Zoho — there
     * is no default — so a store with no deposit account configured gets the credit note and not
     * this, which is a correct set of books missing one entry rather than an incorrect one.
     *
     * @return array<string, mixed>
     */
    public function buildCreditNoteRefundPayload(Transaction $transaction, string $fromAccountId): array
    {
        $payload = [
            'date' => $this->transactionDate($transaction),
            'refund_mode' => $this->paymentMode($transaction),
            'amount' => Money::round(abs((float)$transaction->amount)),
            'from_account_id' => $fromAccountId,
            'description' => $this->truncate(Craft::t('zo', 'Refund on Craft Commerce order {number}', [
                'number' => $transaction->getOrder()?->reference ?? '',
            ]), self::MAX_DESCRIPTION_LENGTH),
        ];

        if ($transaction->reference) {
            $payload['reference_number'] = $this->truncate((string)$transaction->reference, self::MAX_NUMBER_LENGTH);
        }

        return $payload;
    }

    /**
     * A Commerce refund as a Zoho credit note.
     *
     * One line at the refunded amount rather than a mirror of the original invoice: Commerce
     * records a refund as a sum of money, not as a set of returned line items, so itemising it
     * would mean inventing which products came back. The total is exact, which is what the books
     * need; the order reference is on the note for anyone who needs the detail.
     *
     * @return array<string, mixed>
     */
    public function buildCreditNotePayload(Transaction $transaction, Order $order, string $customerId): array
    {
        $amount = Money::round(abs((float)$transaction->amount));

        return [
            'customer_id' => $customerId,
            'date' => $this->transactionDate($transaction),
            'reference_number' => $this->truncate($this->referenceNumber($order), self::MAX_NUMBER_LENGTH),
            'line_items' => [
                [
                    'name' => $this->truncate(Craft::t('zo', 'Refund'), self::MAX_NAME_LENGTH),
                    'description' => $this->truncate(Craft::t('zo', 'Refund against order {number}', [
                        'number' => $this->referenceNumber($order),
                    ]), self::MAX_DESCRIPTION_LENGTH),
                    'rate' => $amount,
                    'quantity' => 1,
                    'item_order' => 0,
                ],
            ],
        ];
    }

    /**
     * A Zoho item for a purchasable, so the books can report per product.
     *
     * @return array<string, mixed>
     */
    public function buildItemPayload(LineItem $lineItem): array
    {
        $sku = $lineItem->getSku();

        $payload = [
            'name' => $this->truncate($lineItem->getDescription() ?: $sku, self::MAX_NAME_LENGTH),
            'rate' => Money::round($lineItem->getSalePrice()),
            'product_type' => 'goods',
        ];

        if ($sku !== '') {
            $payload['sku'] = $this->truncate($sku, 100);
        }

        return $payload;
    }

    // Pieces
    // =========================================================================

    /**
     * @param array<int, string> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function buildLineItems(Order $order, array $itemIds = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $taxMode = $settings->taxMode;
        $lines = [];
        $index = 0;

        foreach ($order->getLineItems() as $lineItem) {
            $line = [
                'name' => $this->truncate($lineItem->getDescription() ?: $lineItem->getSku(), self::MAX_NAME_LENGTH),
                'rate' => Money::round($lineItem->getSalePrice()),
                'quantity' => $lineItem->qty,
                'item_order' => $index,
            ];

            $description = $this->renderTemplate($settings->lineDescriptionTemplate, $lineItem);

            if ($description !== '') {
                $line['description'] = $this->truncate($description, self::MAX_DESCRIPTION_LENGTH);
            }

            $itemId = $lineItem->purchasableId !== null ? ($itemIds[$lineItem->purchasableId] ?? null) : null;

            if ($itemId !== null) {
                $line['item_id'] = $itemId;
            }

            if ($taxMode === Settings::TAX_MODE_MAPPED) {
                $taxId = $this->taxIdFor($lineItem);

                if ($taxId !== null) {
                    $line['tax_id'] = $taxId;
                }
            }

            $lines[] = $line;
            $index++;
        }

        return $lines;
    }

    /**
     * What Zoho will total the document to, given the payload as built.
     *
     * Only meaningful when Zoho is not computing tax itself — in `mapped` mode Zoho applies rates
     * this side cannot see, and guessing at them would produce a confident wrong number.
     *
     * @param array<string, mixed> $payload
     */
    public function projectedTotal(array $payload): float
    {
        $total = 0.0;

        foreach ($payload['line_items'] ?? [] as $line) {
            $total += (float)($line['rate'] ?? 0) * (float)($line['quantity'] ?? 0);
        }

        $total -= (float)($payload['discount'] ?? 0);
        $total += (float)($payload['shipping_charge'] ?? 0);
        $total += (float)($payload['adjustment'] ?? 0);

        return Money::round($total);
    }

    /**
     * The number Zo asks Zoho to file the document under, or null to let Zoho auto-number.
     */
    public function documentNumber(Order $order): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->invoiceNumberSource === 'zoho') {
            return null;
        }

        $value = $this->orderIdentifier($order, $settings->invoiceNumberSource);

        return $value !== '' ? $this->truncate($value, self::MAX_NUMBER_LENGTH) : null;
    }

    public function referenceNumber(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return $this->truncate(
            $this->orderIdentifier($order, $settings->referenceSource),
            self::MAX_NUMBER_LENGTH
        );
    }

    /**
     * The Zoho payment mode for a Commerce transaction.
     *
     * Zoho's vocabulary is closed — seven values, and anything else is a 400 — so an unmapped
     * gateway falls back rather than passing its own handle through.
     */
    public function paymentMode(Transaction $transaction): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = $transaction->getGateway()?->handle;

        if ($handle !== null && isset($settings->paymentModeMap[$handle])) {
            $mode = $settings->paymentModeMap[$handle];

            if (in_array($mode, Settings::paymentModes(), true)) {
                return $mode;
            }
        }

        return in_array($settings->defaultPaymentMode, Settings::paymentModes(), true)
            ? $settings->defaultPaymentMode
            : 'creditcard';
    }

    // Private
    // =========================================================================

    /**
     * Tax, three ways. See {@see Settings::$taxMode} for why the default is the boring one.
     *
     * @param array<string, mixed> $payload
     */
    private function applyTax(Order $order, array &$payload): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $mode = $settings->taxMode;

        if ($mode !== Settings::TAX_MODE_ADJUSTMENT) {
            // `mapped` already put tax_ids on the lines; `none` wants nothing at all.
            return;
        }

        // Everything Commerce charged that the line items, shipping and discount do not already
        // account for — tax, handling, and any custom adjuster a merchant has written. Sending it
        // as one adjustment is what makes the Zoho total equal the amount the customer paid,
        // exactly, without Zo having to model adjusters it has never seen.
        $projected = $this->projectedTotal($payload);
        $adjustment = Money::round($order->getTotalPrice() - $projected);

        if ($adjustment == 0.0) {
            return;
        }

        $payload['adjustment'] = $adjustment;
        $payload['adjustment_description'] = $this->truncate(
            $settings->adjustmentDescription !== '' ? $settings->adjustmentDescription : Craft::t('zo', 'Adjustment'),
            self::MAX_NAME_LENGTH
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyNotes(Order $order, array &$payload): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $notes = $this->renderTemplate($settings->invoiceNotes, $order);

        if ($notes !== '') {
            $payload['notes'] = $this->truncate($notes, self::MAX_DESCRIPTION_LENGTH);
        }

        $terms = $this->renderTemplate($settings->invoiceTerms, $order);

        if ($terms !== '') {
            $payload['terms'] = $this->truncate($terms, self::MAX_DESCRIPTION_LENGTH);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCustomFields(Order $order, array &$payload): void
    {
        $fields = [];

        foreach (Plugin::getInstance()->getSettings()->customFields as $key => $template) {
            $key = trim((string)$key);
            $value = $this->renderTemplate((string)$template, $order);

            if ($key === '' || $value === '') {
                continue;
            }

            // Zoho accepts a custom field by API name (`cf_purchase_order`) or by its visible
            // label. Which one a merchant has to hand depends on where in Zoho they looked, so
            // both are supported and told apart by the prefix Zoho itself uses.
            $fields[] = str_starts_with($key, 'cf_')
                ? ['api_name' => $key, 'value' => $value]
                : ['label' => $key, 'value' => $value];
        }

        if ($fields !== []) {
            $payload['custom_fields'] = $fields;
        }
    }

    /**
     * @return array<string, string>
     */
    private function addressPayload(Address $address): array
    {
        $street2 = trim(implode(' ', array_filter([$address->addressLine2, $address->addressLine3])));

        return array_filter([
            'attention' => $this->truncate((string)($address->fullName ?: $address->organization ?: ''), 200),
            'address' => $this->truncate((string)$address->addressLine1, 500),
            'street2' => $this->truncate($street2, 500),
            'city' => $this->truncate((string)$address->locality, 100),
            'state' => $this->truncate((string)$address->administrativeArea, 100),
            'zip' => $this->truncate((string)$address->postalCode, 50),
            'country' => $this->countryName($address),
            'phone' => $this->phone($address),
        ], static fn(string $value) => $value !== '');
    }

    /**
     * Zoho matches countries by name, not by ISO code, and silently drops one it does not
     * recognise — leaving an address that looks complete and cannot be posted to.
     */
    private function countryName(Address $address): string
    {
        $code = $address->countryCode ?? '';

        if ($code === '') {
            return '';
        }

        try {
            $country = Craft::$app->getAddresses()->getCountryRepository()->get($code, 'en');

            return $country->getName();
        } catch (\Throwable) {
            return $code;
        }
    }

    private function phone(?Address $address): string
    {
        if ($address === null) {
            return '';
        }

        $handle = Plugin::getInstance()->getSettings()->phoneFieldHandle;

        if ($handle === '') {
            return '';
        }

        try {
            $value = $address->getFieldValue($handle);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? $this->truncate((string)$value, 50) : '';
    }

    private function contactName(Order $order): string
    {
        $billing = $order->getBillingAddress();

        foreach ([$billing?->fullName, $billing?->organization, $order->getEmail()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return Craft::t('zo', 'Order {number}', ['number' => $order->getShortNumber()]);
    }

    private function orderIdentifier(Order $order, string $source): string
    {
        return match ($source) {
            'number' => (string)$order->number,
            'shortNumber' => $order->getShortNumber(),
            'id' => (string)$order->id,
            default => (string)($order->reference ?: $order->getShortNumber()),
        };
    }

    /**
     * Zoho takes dates as `yyyy-mm-dd` with no time and no zone, and interprets them in the
     * *organization's* time zone. Sending the site's local date is right: an order placed at
     * 11pm on the 31st belongs in that month's books, and converting to UTC first would move it.
     */
    private function orderDate(Order $order, int $plusDays = 0): string
    {
        $date = $order->dateOrdered ?? $order->dateCreated ?? new \DateTime();
        $date = (clone $date)->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));

        if ($plusDays > 0) {
            $date = $date->modify("+{$plusDays} days");
        }

        return $date->format('Y-m-d');
    }

    private function transactionDate(Transaction $transaction): string
    {
        $date = $transaction->dateCreated ?? new \DateTime();
        $date = (clone $date)->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));

        return $date->format('Y-m-d');
    }

    private function gatewayName(Transaction $transaction): string
    {
        try {
            return (string)($transaction->getGateway()?->name ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function taxIdFor(LineItem $lineItem): ?string
    {
        $map = Plugin::getInstance()->getSettings()->taxMap;

        try {
            $handle = $lineItem->getTaxCategory()->handle;
        } catch (\Throwable) {
            return null;
        }

        if ($handle === null || !isset($map[$handle])) {
            return null;
        }

        $taxId = trim((string)$map[$handle]);

        return $taxId !== '' ? $taxId : null;
    }

    /**
     * Render an object template, never letting a bad one break a sync.
     *
     * These are merchant-written, edited in a settings screen with no preview, and evaluated
     * hours later inside a queue job. A typo in the notes template must cost a blank note, not an
     * uninvoiced order.
     */
    private function renderTemplate(string $template, mixed $object): string
    {
        $template = trim($template);

        if ($template === '') {
            return '';
        }

        try {
            return trim((string)Craft::$app->getView()->renderObjectTemplate($template, $object));
        } catch (\Throwable $e) {
            Craft::warning('Zo could not render an object template: ' . $e->getMessage(), __METHOD__);

            return '';
        }
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim($value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function firstWord(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return (string)($parts[0] ?? '');
    }

    private function restOfWords(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        array_shift($parts);

        return implode(' ', $parts);
    }
}
