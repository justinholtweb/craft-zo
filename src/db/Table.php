<?php

namespace justinholtweb\zo\db;

/**
 * Zo's database tables.
 */
abstract class Table
{
    public const LINKS = '{{%zo_links}}';
    public const LOG = '{{%zo_log}}';
    public const CONNECTION = '{{%zo_connection}}';

    /** Failure-alert latches: one row per incident type. */
    public const ALERTS = '{{%zo_alerts}}';
}
