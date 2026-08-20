<?php

namespace justinholtweb\zo\errors;

/**
 * A call to Zoho Books came back with something other than success.
 *
 * `zohoCode` is Zoho's own numeric code from the response envelope, which is far more actionable
 * than the HTTP status: a 400 tells you nothing, a 1001 tells you the contact name is already
 * taken.
 */
class ZohoApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $zohoCode = null,
        public readonly ?string $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * Whether retrying this exact request could ever produce a different answer.
     *
     * A 400 with "invoice number already exists" will fail identically forever; a 500 or a
     * connection reset is worth another go. Getting this wrong in either direction is expensive —
     * retrying a permanent failure burns the daily API quota, and giving up on a transient one
     * leaves the books short an invoice.
     */
    public function isRetryable(): bool
    {
        if ($this->statusCode === null) {
            // No response at all: DNS, TLS, connect timeout. Always worth retrying.
            return true;
        }

        if ($this->statusCode >= 500) {
            return true;
        }

        // 429 arrives as its own exception type, but a proxy can rewrite it.
        return $this->statusCode === 429 || $this->statusCode === 408;
    }
}
