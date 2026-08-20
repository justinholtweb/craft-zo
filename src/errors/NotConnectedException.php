<?php

namespace justinholtweb\zo\errors;

/**
 * Zo has no usable Zoho Books credentials.
 *
 * Distinct from an API failure on purpose: nothing is wrong with Zoho, the plugin simply has not
 * been connected yet (or the refresh token has been revoked). Callers surface this as "connect
 * Zo" rather than as a sync error, and never retry it.
 */
class NotConnectedException extends \RuntimeException
{
}
