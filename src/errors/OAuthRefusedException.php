<?php

namespace justinholtweb\zo\errors;

/**
 * Zoho's accounts server refused a grant: `{"error":"invalid_grant"}` and its siblings, usually on
 * HTTP 200, or a 4xx from the accounts server itself.
 *
 * Its own type because "Zoho said no" and "Zoho could not be reached" need opposite responses —
 * the first needs a person to reconnect (and is what raises the authentication alert), the second
 * clears by itself.
 */
class OAuthRefusedException extends ZohoApiException
{
}
