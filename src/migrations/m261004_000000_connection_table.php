<?php

namespace justinholtweb\zo\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\helpers\Secret;

/**
 * Moves the Zoho connection out of project config (5.0.1).
 *
 * Until now the OAuth callback saved the refresh token as a plugin setting, so it was committed
 * with the site. A token already stored as a literal setting is copied into the new table here, so
 * the connection keeps working; the setting itself is left alone — a migration that rewrote project
 * config would fight the deploy that brings it — and the settings screen asks for it to be removed.
 */
class m261004_000000_connection_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::CONNECTION)) {
            $this->createTable(Table::CONNECTION, Install::connectionColumns($this));
        }

        $settings = Craft::$app->getProjectConfig()->get('plugins.zo.settings') ?? [];
        $token = trim((string)($settings['refreshToken'] ?? ''));

        // Only a literal: an `$ENV` reference was never in project config, and keeps working as the
        // override it now is.
        if ($token !== '' && !str_starts_with($token, '$') && !(new Query())->from(Table::CONNECTION)->exists()) {
            $now = Db::prepareDateForDb(new DateTime());
            $this->insert(Table::CONNECTION, [
                'refreshToken' => Secret::encrypt($token),
                'dataCenter' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::CONNECTION);

        return true;
    }
}
