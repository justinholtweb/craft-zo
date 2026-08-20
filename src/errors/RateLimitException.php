<?php

namespace justinholtweb\zo\errors;

/**
 * Zoho Books refused the call because the account is over its request allowance.
 *
 * Zoho enforces two ceilings — 100 requests a minute per organization, and a daily cap that
 * depends on the plan — and reports both as a 429. The daily one is the dangerous one: waiting a
 * few seconds will not clear it, so a job that retries on a tight loop simply keeps the account
 * locked out. `retryAfter` carries the sensible wait.
 */
class RateLimitException extends ZohoApiException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter = 60,
        public readonly bool $isDaily = false,
        ?int $statusCode = 429,
        ?int $zohoCode = null,
        ?string $body = null,
    ) {
        parent::__construct($message, $statusCode, $zohoCode, $body);
    }

    /**
     * @inheritdoc
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
