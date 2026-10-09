<?php

namespace justinholtweb\zo\migrations;

use craft\db\Migration;
use justinholtweb\zo\db\Table;

/**
 * Failure alerts (5.1.0): one latch row per incident type.
 *
 * The table definition lives in {@see createAlertsTable()} so `Install` and this migration cannot
 * drift apart — a fresh install and an upgrade end up with the same columns.
 */
class m261009_000000_alerts extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::ALERTS)) {
            self::createAlertsTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::ALERTS);

        return true;
    }

    /**
     * The latch. Unique on `incident`, so there is exactly one row to compare and stamp — the
     * database, not a remembered check, is what makes "one email per incident" true when a queue
     * worker and cron both run a check in the same minute.
     *
     * Erpy keys the same table on `(connectionId, incident)`. Zo has one connection per
     * environment, so the column would only ever hold one value.
     */
    public static function createAlertsTable(Migration $migration): void
    {
        $migration->createTable(Table::ALERTS, [
            'id' => $migration->primaryKey(),
            'incident' => $migration->string(32)->notNull(),
            // `ok` or `open`.
            'state' => $migration->string(8)->notNull()->defaultValue('ok'),
            // Redacted, short: what the last check saw. Never a document or a credential.
            'detail' => $migration->text(),
            // Pushed signals (auth failures) — the other incidents are measured, not signalled.
            'signalledAt' => $migration->dateTime()->null(),
            'signalClearedAt' => $migration->dateTime()->null(),
            'openedAt' => $migration->dateTime()->null(),
            'notifiedAt' => $migration->dateTime()->null(),
            'recoveredAt' => $migration->dateTime()->null(),
            'recoveryNotifiedAt' => $migration->dateTime()->null(),
            // Set when a recovery goes out: a reopening before this is held, not sent.
            'quietUntil' => $migration->dateTime()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::ALERTS, ['incident'], true);
    }
}
