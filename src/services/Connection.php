<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\helpers\Secret;

/**
 * The Zoho connection made on this environment.
 *
 * Connecting yields a refresh token that reads and writes the merchant's books. Until 5.0.1 it was
 * saved as a plugin setting — project config, committed with the site — and connecting refused to
 * run where admin changes were off, so the token reached production *through* git. It now lives
 * here, encrypted with the site's security key, and each environment connects for itself.
 *
 * The `refreshToken` setting remains as an override: an `$ENV` reference for a site that would
 * rather manage the token itself. {@see \justinholtweb\zo\models\Settings::getParsedRefreshToken()}
 * reads this first.
 */
class Connection extends Component
{
    /** @var array{refreshToken: ?string, dataCenter: ?string, organizationId: ?string}|false|null */
    private array|false|null $row = null;

    /**
     * The stored connection, with the token decrypted, or null.
     *
     * `refreshToken` is null when it can't be decrypted — the security key changed — which means
     * connecting again. Null, not an exception, also before the 5.0.1 migration has made the table:
     * the settings screen must still render to tell the merchant to run it.
     *
     * @return array{refreshToken: ?string, dataCenter: ?string, organizationId: ?string}|null
     */
    public function get(): ?array
    {
        if ($this->row === null) {
            try {
                $row = (new Query())
                    ->select(['refreshToken', 'dataCenter', 'organizationId'])
                    ->from(Table::CONNECTION)
                    ->orderBy(['id' => SORT_DESC])
                    ->one();
            } catch (\Throwable) {
                $row = false;
            }

            $this->row = $row ? [
                'refreshToken' => Secret::decrypt($row['refreshToken'] ?? null),
                'dataCenter' => ($row['dataCenter'] ?? '') !== '' ? (string)$row['dataCenter'] : null,
                'organizationId' => ($row['organizationId'] ?? '') !== '' ? (string)$row['organizationId'] : null,
            ] : false;
        }

        return $this->row ?: null;
    }

    public function store(string $refreshToken, ?string $dataCenter): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $db->transaction(static function() use ($db, $refreshToken, $dataCenter, $now) {
            $db->createCommand()->delete(Table::CONNECTION)->execute();
            $db->createCommand()->insert(Table::CONNECTION, [
                'refreshToken' => Secret::encrypt($refreshToken),
                'dataCenter' => $dataCenter,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        });

        $this->row = null;
    }

    public function setOrganizationId(string $organizationId): void
    {
        Craft::$app->getDb()->createCommand()->update(Table::CONNECTION, [
            'organizationId' => $organizationId,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ])->execute();

        $this->row = null;
    }

    public function forget(): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();
        $this->row = null;
    }

    /** Re-read on next access; for a long-running process that changed the row elsewhere. */
    public function reset(): void
    {
        $this->row = null;
    }
}
