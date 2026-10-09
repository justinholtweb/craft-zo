<?php

namespace justinholtweb\zo\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\zo\db\Table;

/**
 * Zo install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        // Failure alerts (5.1.0). Defined once, in the migration that added it.
        m261009_000000_alerts::createAlertsTable($this);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::ALERTS);
        $this->dropTableIfExists(Table::CONNECTION);
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::LINKS);

        return true;
    }

    private function createTables(): void
    {
        // The id map. One row per (type, craftKey) pair, and that pair is unique — which is the
        // whole reason an order cannot be invoiced twice however many times a queue job, a
        // console backfill and an impatient merchant clicking "Sync now" collide.
        $this->createTable(Table::LINKS, [
            'id' => $this->primaryKey(),
            'type' => $this->string(32)->notNull(),
            // The canonical Craft-side identity, e.g. `order:1042`, `user:19`,
            // `transaction:88`, `purchasable:7`, `email:sam@example.com`.
            'craftKey' => $this->string(255)->notNull(),
            // The element this link belongs to, when there is one. Deleting the order should
            // take its invoice, payment and credit note links with it; a payment link therefore
            // points at the *order*, not at the transaction, which is not an element.
            'elementId' => $this->integer(),
            'zohoId' => $this->string(64),
            'zohoNumber' => $this->string(100),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            // Commerce's total at the time of the sync, and what Zoho Books came back with.
            // A document that does not reconcile is a bookkeeping problem, so it is stored,
            // not merely logged.
            'craftTotal' => $this->decimal(14, 4),
            'zohoTotal' => $this->decimal(14, 4),
            'variance' => $this->decimal(14, 4),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(64)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'method' => $this->string(8),
            'endpoint' => $this->string(255),
            'statusCode' => $this->integer(),
            'zohoCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'elementId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CONNECTION, self::connectionColumns($this));
    }

    /**
     * The Zoho connection made on this environment: one row. Runtime state rather than settings,
     * because the refresh token is a credential and project config is committed.
     */
    public static function connectionColumns(Migration $migration): array
    {
        return [
            'id' => $migration->primaryKey(),
            // Secret::encrypt() output — base64 of Craft's encryptByKey().
            'refreshToken' => $migration->text()->notNull(),
            // The data centre Zoho reported on the redirect, which beats the setting.
            'dataCenter' => $migration->string(16),
            // Picked automatically when the account has exactly one organization.
            'organizationId' => $migration->string(32),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ];
    }

    private function createIndexes(): void
    {
        // The idempotency guarantee.
        $this->createIndex(null, Table::LINKS, ['type', 'craftKey'], true);
        $this->createIndex(null, Table::LINKS, ['type', 'zohoId'], false);
        $this->createIndex(null, Table::LINKS, ['elementId'], false);
        $this->createIndex(null, Table::LINKS, ['status'], false);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['elementId'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::LINKS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOG, ['elementId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
    }
}
