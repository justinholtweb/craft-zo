<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;

/**
 * Craft customers as Zoho Books contacts.
 *
 * The hard part is not creating a contact — it is *not* creating one that already exists. A store
 * that has been trading for a year and connects Zo has customers in both systems already, and an
 * integration that starts fresh gives the merchant two of everybody and receivables split across
 * the pair.
 */
class Contacts extends Component
{
    /**
     * Zoho's "that name is taken" code. It arrives as a 400, which is indistinguishable from a
     * malformed payload unless you read the code.
     */
    public const CODE_DUPLICATE_NAME = 1001;

    /**
     * The Zoho contact id for this order's customer, creating or adopting one as needed.
     *
     * @throws ZohoApiException
     */
    public function syncForOrder(Order $order): string
    {
        $links = Plugin::getInstance()->getLinks();
        $key = $this->keyForOrder($order);
        $link = $links->claim(Link::TYPE_CONTACT, $key, $order->getCustomer()?->id);

        if ($link->getIsSynced()) {
            if (Plugin::getInstance()->getSettings()->updateContactOnSync) {
                $this->update($link->zohoId, $order);
            }

            return (string)$link->zohoId;
        }

        $api = Plugin::getInstance()->getApi();
        $documents = Plugin::getInstance()->getDocuments();
        $payload = $documents->buildContactPayload($order);
        $context = ['action' => 'contact', 'elementId' => $order->id];

        // Look before leaping, when asked to. A merchant who has been invoicing by hand wants the
        // existing contact used, not shadowed.
        if (Plugin::getInstance()->getSettings()->reuseContactByEmail) {
            $existing = $this->findByEmail($order->getEmail());

            if ($existing !== null) {
                $links->markSynced($link, $existing['id'], $existing['name']);

                return $existing['id'];
            }
        }

        try {
            $response = $api->post('contacts', $payload, [], $context);
        } catch (ZohoApiException $e) {
            // Zoho enforces unique contact names. Hitting this means the customer is already in
            // the books under this exact name — adopting that contact is the only answer that
            // does not leave the merchant with a duplicate or a permanently failing order.
            if ($e->zohoCode === self::CODE_DUPLICATE_NAME) {
                $existing = $this->findByName((string)($payload['contact_name'] ?? ''));

                if ($existing !== null) {
                    $links->markSynced($link, $existing['id'], $existing['name']);

                    return $existing['id'];
                }
            }

            $links->markFailed($link, $e->getMessage());

            throw $e;
        }

        $contact = $response['contact'] ?? [];
        $contactId = (string)($contact['contact_id'] ?? '');

        if ($contactId === '') {
            $message = Craft::t('zo', 'Zoho created a contact but returned no ID for it.');
            $links->markFailed($link, $message);

            throw new ZohoApiException($message);
        }

        $links->markSynced($link, $contactId, (string)($contact['contact_name'] ?? ''));

        return $contactId;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findByEmail(?string $email): ?array
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return $this->search(['email' => trim($email)]);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findByName(string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }

        return $this->search(['contact_name' => trim($name)]);
    }

    /**
     * The primary contact person on a Zoho contact, which is who an invoice gets emailed to.
     *
     * @return string[] contact person ids
     */
    public function getContactPersonIds(string $contactId): array
    {
        try {
            $response = Plugin::getInstance()->getApi()
                ->get("contacts/{$contactId}", [], ['action' => 'contact-persons']);
        } catch (\Throwable) {
            return [];
        }

        $ids = [];

        foreach ($response['contact']['contact_persons'] ?? [] as $person) {
            $id = (string)($person['contact_person_id'] ?? '');

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // Private
    // =========================================================================

    /**
     * @param array<string, string> $query
     * @return array{id: string, name: string}|null
     */
    private function search(array $query): ?array
    {
        try {
            $response = Plugin::getInstance()->getApi()->get('contacts', $query + [
                'contact_type' => 'customer',
                'per_page' => 1,
            ], ['action' => 'contact-lookup']);
        } catch (\Throwable $e) {
            // A failed lookup must not fail the sync: the worst case is a duplicate contact,
            // which a human can merge. A failed order is one that never reaches the books.
            Craft::warning('Zo could not search Zoho contacts: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        foreach ($response['contacts'] ?? [] as $contact) {
            $id = (string)($contact['contact_id'] ?? '');

            if ($id !== '') {
                return ['id' => $id, 'name' => (string)($contact['contact_name'] ?? '')];
            }
        }

        return null;
    }

    private function update(?string $contactId, Order $order): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        try {
            Plugin::getInstance()->getApi()->put(
                "contacts/{$contactId}",
                Plugin::getInstance()->getDocuments()->buildContactPayload($order),
                [],
                ['action' => 'contact-update', 'elementId' => $order->id]
            );
        } catch (\Throwable $e) {
            // Refreshing an address is a nicety. It is never worth failing an invoice over.
            Craft::warning('Zo could not update a Zoho contact: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * The identity a contact is filed under.
     *
     * A Craft user id when there is one, because that survives the customer changing their email.
     * Guest orders fall back to the lower-cased email, which is the only stable thing about them —
     * and lower-casing matters, since `Sam@example.com` and `sam@example.com` are one customer to
     * everyone except a case-sensitive index.
     */
    public function keyForOrder(Order $order): string
    {
        $customer = $order->getCustomer();

        if ($customer !== null && $customer->id !== null) {
            return Link::key('user', $customer->id);
        }

        $email = strtolower(trim((string)$order->getEmail()));

        if ($email !== '') {
            return Link::key('email', $email);
        }

        return Link::key('order', (int)$order->id);
    }
}
