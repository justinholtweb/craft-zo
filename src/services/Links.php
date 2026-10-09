<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;
use yii\db\IntegrityException;

/**
 * The Craft ↔ Zoho Books id map.
 *
 * **This is the only place a link row is created.** Every path that can produce a Zoho document —
 * the order-complete event, the queue job, the console backfill, the "Sync now" button — claims
 * its link here first, and the unique index on `(type, craftKey)` is what stops two of them
 * producing two invoices for one order. Bypass it and the plugin's core promise is gone: an
 * accounting integration that occasionally double-invoices is worse than none at all.
 */
class Links extends Component
{
    /**
     * The columns a link is read from. Listed rather than `SELECT *` so a future column cannot
     * silently start being passed into the model's constructor.
     */
    /** @var array<int, string> order id => status, for the life of the request */
    private array $_orderStatuses = [];

    private const COLUMNS = [
        'id', 'type', 'craftKey', 'elementId', 'zohoId', 'zohoNumber', 'status', 'attempts',
        'lastError', 'craftTotal', 'zohoTotal', 'variance', 'dateSynced', 'dateCreated',
        'dateUpdated', 'uid',
    ];

    public function find(string $type, string $craftKey): ?Link
    {
        $row = (new Query())
            ->select(self::COLUMNS)
            ->from([Table::LINKS])
            ->where(['type' => $type, 'craftKey' => $craftKey])
            ->one();

        return $row ? new Link($row) : null;
    }

    public function getLinkById(int $id): ?Link
    {
        $row = (new Query())
            ->select(self::COLUMNS)
            ->from([Table::LINKS])
            ->where(['id' => $id])
            ->one();

        return $row ? new Link($row) : null;
    }

    public function findByZohoId(string $type, string $zohoId): ?Link
    {
        $row = (new Query())
            ->select(self::COLUMNS)
            ->from([Table::LINKS])
            ->where(['type' => $type, 'zohoId' => $zohoId])
            ->one();

        return $row ? new Link($row) : null;
    }

    /**
     * Get the link for this thing, creating a pending one if it does not exist yet.
     *
     * The insert is attempted first and the duplicate-key failure is treated as the answer,
     * rather than checking for an existing row and then inserting. A check-then-insert leaves a
     * window between the two in which a second worker inserts, and that window is exactly where a
     * duplicate invoice comes from.
     */
    public function claim(string $type, string $craftKey, ?int $elementId = null): Link
    {
        $existing = $this->find($type, $craftKey);

        if ($existing !== null) {
            return $existing;
        }

        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LINKS, [
                'type' => $type,
                'craftKey' => $craftKey,
                'elementId' => $elementId,
                'status' => Link::STATUS_PENDING,
                'attempts' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (IntegrityException) {
            // Someone else got there first. Their row is the truth.
            $link = $this->find($type, $craftKey);

            if ($link !== null) {
                return $link;
            }

            // The unique index was not what rejected the insert — a bad elementId, most likely.
            // Retry without it rather than failing the sync over a foreign key.
            Craft::$app->getDb()->createCommand()->insert(Table::LINKS, [
                'type' => $type,
                'craftKey' => $craftKey,
                'elementId' => null,
                'status' => Link::STATUS_PENDING,
                'attempts' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $link = $this->find($type, $craftKey);

        if ($link === null) {
            throw new \RuntimeException("Zo could not claim a link for {$type} {$craftKey}.");
        }

        return $link;
    }

    /**
     * Record that the document now exists in Zoho Books.
     *
     * `$craftTotal` and `$zohoTotal` are stored side by side on purpose. The variance between
     * them is the one number that says whether the books actually agree with the store, and
     * computing it later from two systems that have both moved on is not possible.
     */
    public function markSynced(
        Link $link,
        string $zohoId,
        ?string $zohoNumber = null,
        ?float $craftTotal = null,
        ?float $zohoTotal = null,
    ): void {
        $variance = ($craftTotal !== null && $zohoTotal !== null)
            ? round($zohoTotal - $craftTotal, 4)
            : null;

        $this->update($link, [
            'zohoId' => $zohoId,
            'zohoNumber' => $zohoNumber,
            'status' => Link::STATUS_SYNCED,
            'lastError' => null,
            'craftTotal' => $craftTotal,
            'zohoTotal' => $zohoTotal,
            'variance' => $variance,
            'dateSynced' => Db::prepareDateForDb(new DateTime()),
        ]);

        $link->zohoId = $zohoId;
        $link->zohoNumber = $zohoNumber;
        $link->status = Link::STATUS_SYNCED;
        $link->lastError = null;
        $link->craftTotal = $craftTotal;
        $link->zohoTotal = $zohoTotal;
        $link->variance = $variance;
        $link->dateSynced = new DateTime();
    }

    public function markFailed(Link $link, string $error): void
    {
        $attempts = $link->attempts + 1;

        $this->update($link, [
            'status' => Link::STATUS_FAILED,
            'attempts' => $attempts,
            'lastError' => mb_substr($error, 0, 4000),
        ]);

        $link->status = Link::STATUS_FAILED;
        $link->attempts = $attempts;
        $link->lastError = $error;
    }

    public function markSkipped(Link $link, string $reason): void
    {
        $this->update($link, [
            'status' => Link::STATUS_SKIPPED,
            'lastError' => mb_substr($reason, 0, 4000),
        ]);

        $link->status = Link::STATUS_SKIPPED;
        $link->lastError = $reason;
    }

    /**
     * Put a failed link back in the queue's way, clearing the attempt count.
     *
     * A synced link is never reset: the document exists in Zoho Books, and "retrying" it would
     * create a second one. Un-linking has to be an explicit, separate act.
     */
    public function resetForRetry(Link $link): bool
    {
        if ($link->getIsSynced()) {
            return false;
        }

        $this->update($link, [
            'status' => Link::STATUS_PENDING,
            'attempts' => 0,
            'lastError' => null,
        ]);

        $link->status = Link::STATUS_PENDING;
        $link->attempts = 0;
        $link->lastError = null;

        return true;
    }

    /**
     * Forget a link entirely, so the next sync creates a fresh document.
     *
     * Destructive in a way the CP has to spell out: the Zoho-side document is left exactly where
     * it is, so re-syncing produces a duplicate in the books unless it was deleted there first.
     */
    public function delete(Link $link): bool
    {
        if ($link->id === null) {
            return false;
        }

        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::LINKS, ['id' => $link->id])
            ->execute();
    }

    /**
     * Every link belonging to an element, newest first.
     *
     * @return Link[]
     */
    public function getLinksForElement(int $elementId): array
    {
        $rows = (new Query())
            ->select(self::COLUMNS)
            ->from([Table::LINKS])
            ->where(['elementId' => $elementId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new Link($row), $rows);
    }

    /**
     * @param array{type?: string|string[], status?: string|string[], hasVariance?: bool, search?: string} $criteria
     * @return Link[]
     */
    public function getLinks(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $rows = $this->buildQuery($criteria)
            ->select(self::COLUMNS)
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(static fn(array $row) => new Link($row), $rows);
    }

    /**
     * @param array{type?: string|string[], status?: string|string[], hasVariance?: bool, search?: string} $criteria
     */
    public function countLinks(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count();
    }

    /**
     * The numbers on the CP overview: how much of the store is actually in the books.
     *
     * @return array{synced: int, pending: int, failed: int, skipped: int, variance: int}
     */
    public function getStats(): array
    {
        $rows = (new Query())
            ->select(['status', 'count' => 'COUNT(*)'])
            ->from([Table::LINKS])
            ->groupBy(['status'])
            ->all();

        $stats = [
            Link::STATUS_SYNCED => 0,
            Link::STATUS_PENDING => 0,
            Link::STATUS_FAILED => 0,
            Link::STATUS_SKIPPED => 0,
        ];

        foreach ($rows as $row) {
            $stats[(string)$row['status']] = (int)$row['count'];
        }

        $tolerance = Plugin::getInstance()->getSettings()->varianceTolerance;

        $stats['variance'] = (int)(new Query())
            ->from([Table::LINKS])
            ->where(['status' => Link::STATUS_SYNCED])
            ->andWhere(['not', ['variance' => null]])
            ->andWhere(['or', ['>', 'variance', $tolerance], ['<', 'variance', -$tolerance]])
            ->count();

        return $stats;
    }

    /**
     * Links that failed but have attempts left, for the retry sweep.
     *
     * @return Link[]
     */
    public function getRetryable(string $type, int $maxAttempts, int $limit = 100): array
    {
        $rows = (new Query())
            ->select(self::COLUMNS)
            ->from([Table::LINKS])
            ->where(['type' => $type, 'status' => Link::STATUS_FAILED])
            ->andWhere(['<', 'attempts', $maxAttempts])
            ->orderBy(['dateUpdated' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $row) => new Link($row), $rows);
    }

    // Order status (the Orders index column and condition rule)
    // =========================================================================

    /**
     * Where an order stands with Zoho Books, as a single word for the Orders index.
     *
     * In precedence order; {@see orderStatusCondition()} builds exactly the same sets in SQL, for
     * the condition rule, and the test suite holds the two to agreeing:
     *
     * - `failed` — any of its documents failed: the customer, the invoice or sales order, a
     *   payment or a refund. The customer link belongs to the order's customer element (Commerce 5
     *   gives guests one too), so a customer failure counts for that customer's completed orders.
     * - `notReconciled` — its document synced but Zoho's total disagrees with Commerce's.
     * - `synced` — its document is in Zoho Books and reconciles.
     * - `pending` — claimed and not yet sent.
     * - `skipped` — deliberately not sent.
     * - `none` — never synced.
     *
     * @param int[] $orderIds
     * @return array<int, string> order id => status
     */
    public function orderStatuses(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if ($orderIds === []) {
            return [];
        }

        $tolerance = Plugin::getInstance()->getSettings()->varianceTolerance;
        $failed = [];
        $documents = [];

        // Two queries for any number of orders — this runs for every row of the Orders index.
        $rows = (new Query())
            ->select(['elementId', 'type', 'status', 'variance'])
            ->from([Table::LINKS])
            ->where([
                'elementId' => $orderIds,
                'type' => [Link::TYPE_INVOICE, Link::TYPE_SALESORDER, Link::TYPE_PAYMENT, Link::TYPE_REFUND],
            ])
            ->all();

        foreach ($rows as $row) {
            $id = (int)$row['elementId'];

            if ($row['status'] === Link::STATUS_FAILED) {
                $failed[$id] = true;
            }

            if (in_array($row['type'], [Link::TYPE_INVOICE, Link::TYPE_SALESORDER], true)) {
                $documents[$id][] = $row;
            }
        }

        foreach ($this->contactFailureQuery()->andWhere(['o.id' => $orderIds])->column() as $id) {
            $failed[(int)$id] = true;
        }

        $statuses = [];

        foreach ($orderIds as $id) {
            $seen = [];

            foreach ($documents[$id] ?? [] as $row) {
                $seen[$row['status']] = true;

                if ($row['status'] === Link::STATUS_SYNCED && $row['variance'] !== null && abs((float)$row['variance']) > $tolerance) {
                    $seen['variance'] = true;
                }
            }

            $statuses[$id] = match (true) {
                isset($failed[$id]) => Link::ORDER_FAILED,
                isset($seen['variance']) => Link::ORDER_NOT_RECONCILED,
                isset($seen[Link::STATUS_SYNCED]) => Link::ORDER_SYNCED,
                isset($seen[Link::STATUS_PENDING]) => Link::ORDER_PENDING,
                isset($seen[Link::STATUS_SKIPPED]) => Link::ORDER_SKIPPED,
                default => Link::ORDER_NONE,
            };
        }

        return $statuses;
    }

    /**
     * One order's status, from a per-request memo that {@see prefetchOrderStatuses()} fills for a
     * whole index page at once.
     */
    public function orderStatus(int $orderId): string
    {
        if (!isset($this->_orderStatuses[$orderId])) {
            $this->prefetchOrderStatuses([$orderId]);
        }

        return $this->_orderStatuses[$orderId] ?? Link::ORDER_NONE;
    }

    /**
     * @param int[] $orderIds
     */
    public function prefetchOrderStatuses(array $orderIds): void
    {
        $missing = array_diff(array_map('intval', $orderIds), array_keys($this->_orderStatuses));

        if ($missing !== []) {
            $this->_orderStatuses = $this->orderStatuses($missing) + $this->_orderStatuses;
        }
    }

    /**
     * Forget the memo — after a sync changes what it would say.
     */
    public function resetOrderStatusCache(): void
    {
        $this->_orderStatuses = [];
    }

    /**
     * @return array<string, string> status => label
     */
    public static function orderStatusOptions(): array
    {
        return [
            Link::ORDER_SYNCED => Craft::t('zo', 'Synced'),
            Link::ORDER_NOT_RECONCILED => Craft::t('zo', 'Not reconciled'),
            Link::ORDER_FAILED => Craft::t('zo', 'Failed'),
            Link::ORDER_PENDING => Craft::t('zo', 'Pending'),
            Link::ORDER_SKIPPED => Craft::t('zo', 'Skipped'),
            Link::ORDER_NONE => Craft::t('zo', 'Not synced'),
        ];
    }

    /**
     * A WHERE condition on an order id column that is true for orders in exactly this status.
     *
     * Each status excludes every status above it in precedence, so the six sets partition the
     * orders: an order is in one of them and only one.
     *
     * @return array<int|string, mixed>
     */
    public function orderStatusCondition(string $status, string $idColumn = 'elements.id'): array
    {
        $failed = ['in', $idColumn, $this->failedOrdersSubquery()];
        $notFailed = ['not in', $idColumn, $this->failedOrdersSubquery()];
        $variance = ['in', $idColumn, $this->documentSubquery(Link::STATUS_SYNCED, true)];
        $synced = ['in', $idColumn, $this->documentSubquery(Link::STATUS_SYNCED)];
        $pending = ['in', $idColumn, $this->documentSubquery(Link::STATUS_PENDING)];
        $skipped = ['in', $idColumn, $this->documentSubquery(Link::STATUS_SKIPPED)];

        return match ($status) {
            Link::ORDER_FAILED => $failed,
            Link::ORDER_NOT_RECONCILED => ['and', $variance, $notFailed],
            Link::ORDER_SYNCED => ['and', $synced, ['not', $variance], $notFailed],
            Link::ORDER_PENDING => ['and', $pending, ['not', $synced], $notFailed],
            Link::ORDER_SKIPPED => ['and', $skipped, ['not', $synced], ['not', $pending], $notFailed],
            Link::ORDER_NONE => ['and', ['not in', $idColumn, $this->documentSubquery(null)], $notFailed],
            // An unknown status matches nothing, rather than everything.
            default => ['in', $idColumn, (new Query())->select(['elementId'])->from([Table::LINKS])->where('0=1')],
        };
    }

    /**
     * Order ids with an invoice or sales order link, optionally in one status and out of balance.
     */
    private function documentSubquery(?string $status, bool $varianceOnly = false): Query
    {
        $query = (new Query())
            ->select(['elementId'])
            ->from([Table::LINKS])
            ->where(['type' => [Link::TYPE_INVOICE, Link::TYPE_SALESORDER]])
            ->andWhere(['not', ['elementId' => null]]);

        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }

        if ($varianceOnly) {
            $tolerance = Plugin::getInstance()->getSettings()->varianceTolerance;
            $query->andWhere(['not', ['variance' => null]])
                ->andWhere(['or', ['>', 'variance', $tolerance], ['<', 'variance', -$tolerance]]);
        }

        return $query;
    }

    /**
     * Order ids with a failed document, or whose customer's contact failed.
     */
    private function failedOrdersSubquery(): Query
    {
        $documents = (new Query())
            ->select(['elementId'])
            ->from([Table::LINKS])
            ->where([
                'type' => [Link::TYPE_INVOICE, Link::TYPE_SALESORDER, Link::TYPE_PAYMENT, Link::TYPE_REFUND],
                'status' => Link::STATUS_FAILED,
            ])
            ->andWhere(['not', ['elementId' => null]]);

        return $documents->union($this->contactFailureQuery(), true);
    }

    /**
     * Completed orders whose customer's contact link failed. A contact link points at the customer
     * (a user element), not the order: an order whose customer could not be created never got as
     * far as claiming an invoice link, so without this it would read as "not synced", not "failed".
     */
    private function contactFailureQuery(): Query
    {
        return (new Query())
            ->select(['o.id'])
            ->from(['o' => CommerceTable::ORDERS])
            ->innerJoin(['l' => Table::LINKS], '[[l.elementId]] = [[o.customerId]]')
            ->where(['l.type' => Link::TYPE_CONTACT, 'l.status' => Link::STATUS_FAILED])
            ->andWhere(['o.isCompleted' => true]);
    }

    // Private
    // =========================================================================

    /**
     * @param array{type?: string|string[], status?: string|string[], hasVariance?: bool, search?: string} $criteria
     */
    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from([Table::LINKS]);

        if (!empty($criteria['type'])) {
            $query->andWhere(['type' => $criteria['type']]);
        }

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['hasVariance'])) {
            $tolerance = Plugin::getInstance()->getSettings()->varianceTolerance;
            $query->andWhere(['not', ['variance' => null]])
                ->andWhere(['or', ['>', 'variance', $tolerance], ['<', 'variance', -$tolerance]]);
        }

        if (!empty($criteria['search'])) {
            $term = '%' . $criteria['search'] . '%';
            $query->andWhere(['or',
                ['like', 'craftKey', $term, false],
                ['like', 'zohoNumber', $term, false],
                ['like', 'zohoId', $term, false],
            ]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function update(Link $link, array $columns): void
    {
        if ($link->id === null) {
            return;
        }

        $columns['dateUpdated'] = Db::prepareDateForDb(new DateTime());
        $this->_orderStatuses = [];

        Craft::$app->getDb()->createCommand()
            ->update(Table::LINKS, $columns, ['id' => $link->id])
            ->execute();
    }
}
