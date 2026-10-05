<?php

namespace justinholtweb\zo\helpers;

use Craft;

/**
 * Encrypting what must not be stored in the clear: the Zoho refresh token, which reads and writes
 * the merchant's books. The same helper as craft-freshh's and craft-bird's.
 *
 * **Why this exists rather than calling `Craft::$app->getSecurity()` directly.**
 * `encryptByKey()` returns *raw binary*, and a `text` column in a utf8mb4 database rejects it —
 * MySQL answers `1366 Incorrect string value`, which is a confusing error to receive when you
 * were saving a token. Base64 on the way in and out is the whole fix, and having it in one place
 * means a reader and a writer cannot disagree about whether it was applied.
 */
abstract class Secret
{
    /**
     * Encrypt a value for storage. Returns null for nothing, so callers can pass a nullable
     * token straight through.
     */
    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    /**
     * Decrypt a stored value.
     *
     * Returns null rather than throwing when it cannot: the usual cause is a rotated security
     * key, and that is a "reconnect the site" situation, not a fatal one. The CP can say so if
     * it is handed a null; it cannot say anything at all if the request 500s.
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = base64_decode($value, true);

        if ($raw === false) {
            return null;
        }

        try {
            $decrypted = Craft::$app->getSecurity()->decryptByKey($raw);
        } catch (\Throwable $e) {
            Craft::warning('Zo could not decrypt a stored secret: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        return $decrypted === false ? null : $decrypted;
    }
}
