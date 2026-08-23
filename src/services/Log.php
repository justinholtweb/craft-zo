<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\Plugin;

/**
 * The Zoho Books connection log.
 *
 * An accounting integration that silently does nothing is indistinguishable from one that is
 * working on a quiet day, and the merchant only finds out at the end of the quarter. Every call
 * Zo makes, and every decision it takes not to make one, lands here.
 */
class Log extends Component
{
    /**
     * Payload bodies longer than this are truncated. A backfill response can run to megabytes and
     * nobody reads past the first screen.
     */
    public const MAX_PAYLOAD = 65535;

    /**
     * Keys whose values never reach the log, at any level, in any payload.
     *
     * Zo posts client secrets and refresh tokens to Zoho's accounts server, and a log that
     * faithfully records the request body would hand a permanent credential to anyone with
     * "view the log" permission.
     */
    private const REDACTED_KEYS = [
        'client_secret',
        'refresh_token',
        'access_token',
        'code',
        'authorization',
        'api-key',
    ];

    /**
     * @param array{
     *     level?: string,
     *     method?: string|null,
     *     endpoint?: string|null,
     *     statusCode?: int|null,
     *     zohoCode?: int|null,
     *     durationMs?: int|null,
     *     elementId?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        $keepPayloads = $settings->logPayloads;

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'method' => $data['method'] ?? null,
                'endpoint' => isset($data['endpoint']) ? mb_substr((string)$data['endpoint'], 0, 255) : null,
                'statusCode' => $data['statusCode'] ?? null,
                'zohoCode' => $data['zohoCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'elementId' => $data['elementId'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $keepPayloads ? $this->prepare($data['request'] ?? null) : null,
                'response' => $keepPayloads ? $this->prepare($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write it must not take down the
            // sync it was describing.
            Craft::warning('Zo could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @param array{action?: string, level?: string, elementId?: int} $criteria
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = (new Query())
            ->select([
                'id', 'action', 'level', 'method', 'endpoint', 'statusCode', 'zohoCode',
                'durationMs', 'elementId', 'summary', 'message', 'dateCreated', 'uid',
            ])
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        foreach (['action', 'level', 'elementId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * @param array{action?: string, level?: string, elementId?: int} $criteria
     */
    public function count(array $criteria = []): int
    {
        $query = (new Query())->from([Table::LOG]);

        foreach (['action', 'level', 'elementId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return (int)$query->count();
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    /**
     * Redact, then truncate. In that order: truncating first can cut a secret in half and leave
     * the first 40 characters of it sitting in the table.
     */
    private function prepare(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $payload = self::redact($payload);

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        return substr($payload, 0, self::MAX_PAYLOAD) . "\n…[truncated]";
    }

    /**
     * Blank out credential values wherever they appear, in JSON or in form encoding.
     */
    public static function redact(string $payload): string
    {
        foreach (self::REDACTED_KEYS as $key) {
            // "client_secret":"abc"  /  "client_secret": "abc"
            $payload = preg_replace(
                '/("' . preg_quote($key, '/') . '"\s*:\s*")[^"]*(")/i',
                '${1}…redacted…${2}',
                $payload
            ) ?? $payload;

            // client_secret=abc&…
            $payload = preg_replace(
                '/(\b' . preg_quote($key, '/') . '=)[^&\s]*/i',
                '${1}…redacted…',
                $payload
            ) ?? $payload;

            // Authorization: Zoho-oauthtoken abc
            //
            // The whole rest of the line has to go, not the next word: the value is
            // `Zoho-oauthtoken <token>`, so a `\S+` match consumes the scheme and leaves the
            // credential sitting immediately after the word "redacted".
            $payload = preg_replace(
                '/(' . preg_quote($key, '/') . '\s*:\s*)[^\r\n]+/i',
                '${1}…redacted…',
                $payload
            ) ?? $payload;
        }

        return $payload;
    }
}
