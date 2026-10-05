<?php

namespace justinholtweb\zo\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\zo\Plugin;

/**
 * Zo settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * required attribute makes the settings screen unsaveable on a fresh install — which is exactly
 * when the Zoho credentials do not exist yet.
 *
 * @property array<string, string> $customFields  field handle => template
 * @property array<string, string> $taxMap        tax category => Zoho tax id
 * @property array<string, string> $paymentModeMap gateway => Zoho payment mode
 */
class Settings extends Model
{
    /**
     * Zoho runs one set of data centres per region and they do not share data. A token minted at
     * `accounts.zoho.eu` is meaningless to `zohoapis.com`, which fails as a flat 401 with no hint
     * that the region is the problem — so the region is a first-class setting rather than
     * something guessed from the account.
     */
    public const DATA_CENTERS = [
        'com' => 'United States (.com)',
        'eu' => 'Europe (.eu)',
        'in' => 'India (.in)',
        'com.au' => 'Australia (.com.au)',
        'jp' => 'Japan (.jp)',
        'ca' => 'Canada (.ca)',
        'sa' => 'Saudi Arabia (.sa)',
        'com.cn' => 'China (.com.cn)',
    ];

    public const TAX_MODE_ADJUSTMENT = 'adjustment';
    public const TAX_MODE_MAPPED = 'mapped';
    public const TAX_MODE_NONE = 'none';

    public const DOCUMENT_INVOICE = 'invoice';
    public const DOCUMENT_SALESORDER = 'salesorder';
    public const DOCUMENT_BOTH = 'both';

    // Connection
    // -------------------------------------------------------------------------

    /** Zoho API console client id. Env-parseable. */
    public string $clientId = '';

    /** Zoho API console client secret. Env-parseable. */
    public string $clientSecret = '';

    /**
     * An override for the refresh token: an environment variable (`$ZOHO_REFRESH_TOKEN`) for a site
     * that manages it itself. Connecting stores the token in Zo's own table, encrypted —
     * {@see \justinholtweb\zo\services\Connection} — never here.
     *
     * Before 5.0.1 the connect flow saved the token here, literally, and so committed it with
     * project config. A literal is now refused unless it is that already-stored value, which the
     * settings screen never renders and asks to remove.
     */
    public string $refreshToken = '';

    /**
     * The Zoho Books organization every call is scoped to. Env-parseable. Empty falls back to the
     * one picked automatically when the connected account has only one.
     */
    public string $organizationId = '';

    public string $dataCenter = 'com';

    /**
     * Override the API base URL that the data centre would otherwise imply.
     *
     * Blank — the normal case — derives it. Set it when outbound traffic has to leave through an
     * internal egress proxy on its own hostname, which is common enough in larger organisations
     * that hard-coding `zohoapis.com` would rule Zo out entirely. Env-parseable.
     */
    public string $apiBaseUrl = '';

    /**
     * The matching override for the accounts server that issues tokens. Env-parseable.
     */
    public string $accountsUrl = '';

    // What to sync, and when
    // -------------------------------------------------------------------------

    /** Push an order the moment Commerce marks it complete. */
    public bool $autoSyncOnComplete = true;

    /** Push an order when it becomes fully paid instead of (or as well as) on completion. */
    public bool $autoSyncOnPaid = false;

    /**
     * Order status handles that trigger a sync when an order moves into them.
     *
     * @var string[]
     */
    public array $syncOnStatusHandles = [];

    /**
     * Order status handles eligible for syncing at all. Empty means every completed order.
     *
     * @var string[]
     */
    public array $eligibleStatusHandles = [];

    /** Orders below this total are skipped. Useful for $0 comp orders. */
    public float $minimumOrderTotal = 0.0;

    /** Hand the work to the queue rather than doing it in the request that triggered it. */
    public bool $syncViaQueue = true;

    /** How many times a failing order is retried before it stops being retried automatically. */
    public int $maxAttempts = 5;

    // Documents
    // -------------------------------------------------------------------------

    /** `invoice`, `salesorder`, or `both` (Pro). */
    public string $documentType = self::DOCUMENT_INVOICE;

    /**
     * Where the Zoho document number comes from. `zoho` lets Zoho Books auto-number, which keeps
     * the books' own sequence intact; anything else sends Craft's number and asks Zoho to accept
     * it. Sequential numbering is a legal requirement in several jurisdictions, so this is not a
     * cosmetic choice.
     */
    public string $invoiceNumberSource = 'zoho';

    /** What lands in Zoho's `reference_number`. One of `reference`, `number`, `shortNumber`, `id`. */
    public string $referenceSource = 'reference';

    /** Days after the invoice date that payment is due. */
    public int $paymentTermsDays = 0;

    /** Move the invoice out of draft, so it counts towards receivables. */
    public bool $markInvoiceSent = true;

    /** Have Zoho Books email the invoice to the customer. Off by default — Commerce already did. */
    public bool $emailInvoice = false;

    /** Object templates rendered against the order. */
    public string $invoiceNotes = '';
    public string $invoiceTerms = '';

    /**
     * Zoho custom field placeholder (`cf_purchase_order`) => object template rendered against the
     * order (Pro).
     *
     * Backed by a private property because it is edited as a Craft editable table, which posts a
     * list of rows rather than a map. See {@see attributes()} for why that needs care.
     *
     * @var array<string, string>
     */
    private array $_customFields = [];

    // Line items
    // -------------------------------------------------------------------------

    /** Create or match a Zoho Books item per purchasable, so the books report per product (Pro). */
    public bool $syncItems = false;

    /** How an existing Zoho item is recognised: `sku` or `name`. */
    public string $itemMatchBy = 'sku';

    /** Object template rendered against each line item for its Zoho description. */
    public string $lineDescriptionTemplate = '{{ object.sku }}';

    public bool $includeShipping = true;

    public bool $includeDiscount = true;

    // Tax
    // -------------------------------------------------------------------------

    /**
     * How Commerce's tax reaches Zoho Books.
     *
     * - `adjustment` (default) — Commerce is the source of truth. Zoho is told to apply no tax and
     *   the tax Commerce actually charged is sent as an adjustment, so the invoice total equals
     *   what the customer paid, to the cent, always.
     * - `mapped` — line items carry Zoho `tax_id`s and Zoho recomputes. Correct tax reporting,
     *   but Zoho's answer can differ from Commerce's; the difference is measured and surfaced.
     * - `none` — no tax anywhere. Only sane for tax-exempt stores.
     */
    public string $taxMode = self::TAX_MODE_ADJUSTMENT;

    /**
     * Commerce tax category handle => Zoho `tax_id` (Pro, `mapped` mode).
     *
     * @var array<string, string>
     */
    private array $_taxMap = [];

    /** What the adjustment line is called on the Zoho invoice in `adjustment` mode. */
    public string $adjustmentDescription = 'Sales tax';

    /** Cents of disagreement tolerated between Commerce's total and Zoho's before it is flagged. */
    public float $varianceTolerance = 0.01;

    /**
     * Treat a document whose total does not match Commerce as a failed sync rather than a synced
     * one with a warning. Off by default: the invoice does exist in Zoho, and pretending it does
     * not invites a duplicate.
     */
    public bool $blockOnVariance = false;

    // Contacts
    // -------------------------------------------------------------------------

    /** Look for an existing Zoho contact by email before creating one. */
    public bool $reuseContactByEmail = true;

    /** Push address and name changes back onto an already-linked Zoho contact on every sync. */
    public bool $updateContactOnSync = false;

    /** Mark a contact `business` when the order's billing address carries an organization name. */
    public bool $inferBusinessCustomers = true;

    /**
     * Handle of the custom field on Craft addresses holding a phone number.
     *
     * Craft 5 moved addresses out of Commerce and dropped the native phone attribute, so there is
     * no way to find one without being told where it is.
     */
    public string $phoneFieldHandle = '';

    // Payments and refunds (Pro)
    // -------------------------------------------------------------------------

    public bool $syncPayments = true;

    public bool $syncRefunds = true;

    /**
     * Commerce gateway handle => Zoho `payment_mode`.
     *
     * @var array<string, string>
     */
    private array $_paymentModeMap = [];

    /** Used when the gateway is not mapped. One of Zoho's fixed vocabulary. */
    public string $defaultPaymentMode = 'creditcard';

    /** Zoho chart-of-accounts id the payment is deposited into. Blank uses Zoho's default. */
    public string $depositAccountId = '';

    // Transport
    // -------------------------------------------------------------------------

    /**
     * Zoho allows 100 requests a minute per organization. Staying under it deliberately leaves
     * headroom for anything else talking to the same organization — a Zoho Flow, a phone app, the
     * merchant's own script.
     */
    public int $requestsPerMinute = 90;

    /** How long a call will wait for a rate-limit slot before giving up and retrying later. */
    public int $rateLimitWaitSeconds = 5;

    public int $requestTimeout = 20;

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /** Keep request and response bodies on log rows (Pro). Off keeps only the summary line. */
    public bool $logPayloads = true;

    /** Days of log history to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['dataCenter'], 'in', 'range' => array_keys(self::DATA_CENTERS)],
            [['refreshToken'], 'validateRefreshToken'],
            [['taxMode'], 'in', 'range' => [self::TAX_MODE_ADJUSTMENT, self::TAX_MODE_MAPPED, self::TAX_MODE_NONE]],
            [['documentType'], 'in', 'range' => [self::DOCUMENT_INVOICE, self::DOCUMENT_SALESORDER, self::DOCUMENT_BOTH]],
            [['invoiceNumberSource'], 'in', 'range' => ['zoho', 'reference', 'number', 'shortNumber']],
            [['referenceSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id']],
            [['itemMatchBy'], 'in', 'range' => ['sku', 'name']],
            [['defaultPaymentMode'], 'in', 'range' => self::paymentModes()],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 25],
            [['paymentTermsDays', 'logRetentionDays'], 'integer', 'min' => 0],
            [['requestsPerMinute'], 'integer', 'min' => 1, 'max' => 100],
            [['rateLimitWaitSeconds'], 'integer', 'min' => 0, 'max' => 60],
            [['requestTimeout'], 'integer', 'min' => 1, 'max' => 120],
            [['minimumOrderTotal', 'varianceTolerance'], 'number', 'min' => 0],
            [
                [
                    'clientId', 'clientSecret', 'refreshToken', 'organizationId', 'adjustmentDescription',
                    'invoiceNotes', 'invoiceTerms', 'lineDescriptionTemplate', 'depositAccountId',
                    'phoneFieldHandle', 'apiBaseUrl', 'accountsUrl',
                ],
                'string',
            ],
            [
                [
                    'syncOnStatusHandles', 'eligibleStatusHandles', 'customFields', 'taxMap',
                    'paymentModeMap',
                ],
                'safe',
            ],
        ];
    }

    /**
     * Zoho Books' fixed payment-mode vocabulary. Sending anything outside it is a 400.
     *
     * @return string[]
     */
    public static function paymentModes(): array
    {
        return ['check', 'cash', 'creditcard', 'banktransfer', 'bankremittance', 'autotransaction', 'others'];
    }

    // Editable-table maps
    // -------------------------------------------------------------------------

    /**
     * Craft's editable table posts `[['field' => 'cf_x', 'template' => '{{ … }}'], …]`, while
     * every consumer wants `['cf_x' => '{{ … }}']`. Normalising on the way in means the shape is
     * decided once, here, rather than being re-guessed at each of the four call sites.
     */
    public function setCustomFields(mixed $value): void
    {
        $this->_customFields = self::normalizeMap($value, 'field', 'template');
    }

    /**
     * @return array<string, string>
     */
    public function getCustomFields(): array
    {
        return $this->_customFields;
    }

    /**
     * @return array<int, array{field: string, template: string}>
     */
    public function getCustomFieldRows(): array
    {
        return self::toRows($this->_customFields, 'field', 'template');
    }

    public function setTaxMap(mixed $value): void
    {
        $this->_taxMap = self::normalizeMap($value, 'category', 'taxId');
    }

    /**
     * @return array<string, string>
     */
    public function getTaxMap(): array
    {
        return $this->_taxMap;
    }

    /**
     * @return array<int, array{category: string, taxId: string}>
     */
    public function getTaxMapRows(): array
    {
        return self::toRows($this->_taxMap, 'category', 'taxId');
    }

    public function setPaymentModeMap(mixed $value): void
    {
        $this->_paymentModeMap = self::normalizeMap($value, 'gateway', 'mode');
    }

    /**
     * @return array<string, string>
     */
    public function getPaymentModeMap(): array
    {
        return $this->_paymentModeMap;
    }

    /**
     * @return array<int, array{gateway: string, mode: string}>
     */
    public function getPaymentModeMapRows(): array
    {
        return self::toRows($this->_paymentModeMap, 'gateway', 'mode');
    }

    /**
     * Accept either shape: the row list an editable table posts, or the map that comes back out of
     * project config on the next request.
     *
     * @return array<string, string>
     */
    private static function normalizeMap(mixed $value, string $keyColumn, string $valueColumn): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $mapKey = trim((string)($item[$keyColumn] ?? ''));
                $mapValue = (string)($item[$valueColumn] ?? '');
            } else {
                $mapKey = trim((string)$key);
                $mapValue = (string)$item;
            }

            // A row with a key and no value is a half-finished edit, not an instruction to map
            // something to nothing.
            if ($mapKey === '' || trim($mapValue) === '') {
                continue;
            }

            $out[$mapKey] = $mapValue;
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     * @return array<int, array<string, string>>
     */
    private static function toRows(array $map, string $keyColumn, string $valueColumn): array
    {
        $rows = [];

        foreach ($map as $key => $value) {
            $rows[] = [$keyColumn => (string)$key, $valueColumn => (string)$value];
        }

        return $rows;
    }

    /**
     * @inheritdoc
     *
     * Craft persists plugin settings by iterating the model's attributes, and Yii does not count a
     * private property as one. Without this the three maps above would save as nothing at all —
     * the screen reporting success while every mapping was discarded.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['customFields', 'taxMap', 'paymentModeMap']);
    }

    // Parsed credentials
    // -------------------------------------------------------------------------

    public function getParsedClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getParsedClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    /**
     * The connection made on this environment first, then the setting — an `$ENV` override, or a
     * literal stored before 5.0.1.
     */
    public function getParsedRefreshToken(): string
    {
        $connected = self::connection()['refreshToken'] ?? null;

        return $connected !== null && $connected !== '' ? $connected : trim((string)App::parseEnv($this->refreshToken));
    }

    public function getParsedOrganizationId(): string
    {
        $configured = trim((string)App::parseEnv($this->organizationId));

        return $configured !== '' ? $configured : (string)(self::connection()['organizationId'] ?? '');
    }

    /**
     * Whether the setting holds a token itself rather than naming an environment variable — and so
     * sits in project config. Only an install from before 5.0.1 can be in this state.
     */
    public function storesLiteralRefreshToken(): bool
    {
        $value = trim($this->refreshToken);

        return $value !== '' && !str_starts_with($value, '$');
    }

    /**
     * The settings screen never renders a stored literal token, so it posts an empty field and
     * `refreshTokenKept`. Empty plus the flag means "unchanged" — unless "Remove it" was ticked.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values)) {
            if (!empty($values['refreshTokenKept']) && trim((string)($values['refreshToken'] ?? '')) === '') {
                if (empty($values['refreshTokenRemove'])) {
                    unset($values['refreshToken']);
                } else {
                    $values['refreshToken'] = '';
                }
            }

            unset($values['refreshTokenKept'], $values['refreshTokenRemove']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function validateRefreshToken(string $attribute): void
    {
        if (!$this->storesLiteralRefreshToken()) {
            return;
        }

        $stored = Craft::$app->getProjectConfig()->get('plugins.zo.settings.refreshToken');

        if (!is_string($stored) || trim($stored) !== trim($this->refreshToken)) {
            $this->addError($attribute, Craft::t('zo', 'Enter an environment variable, such as `$ZOHO_REFRESH_TOKEN`, not the token itself: settings are saved to project config, which is committed with your site. Connecting stores the token for you.'));
        }
    }

    /**
     * @return array{refreshToken: ?string, dataCenter: ?string, organizationId: ?string}|null
     */
    private static function connection(): ?array
    {
        $plugin = Plugin::getInstance();

        return $plugin?->getConnection()->get();
    }

    /**
     * Whether Zo has everything it needs to talk to Zoho Books.
     */
    public function getIsConnected(): bool
    {
        return $this->getHasCredentials() && $this->getParsedOrganizationId() !== '';
    }

    /**
     * Whether Zo can get an access token — everything but the organization, which is what the
     * organization lookup itself needs.
     */
    public function getHasCredentials(): bool
    {
        return $this->getParsedClientId() !== ''
            && $this->getParsedClientSecret() !== ''
            && $this->getParsedRefreshToken() !== '';
    }

    /**
     * Whether the OAuth handshake can even be started.
     */
    public function getCanConnect(): bool
    {
        return $this->getParsedClientId() !== '' && $this->getParsedClientSecret() !== '';
    }

    // Endpoints
    // -------------------------------------------------------------------------

    public function getAccountsUrl(): string
    {
        $override = trim((string)App::parseEnv($this->accountsUrl));

        if ($override !== '') {
            // No trailing slash: every caller appends `/oauth/v2/…`, and `//oauth` is a 404 on
            // some proxies and a redirect on others.
            return rtrim($override, '/');
        }

        return 'https://accounts.zoho.' . $this->getSafeDataCenter();
    }

    public function getApiBase(): string
    {
        $override = trim((string)App::parseEnv($this->apiBaseUrl));

        if ($override !== '') {
            // Always a trailing slash: callers concatenate a relative path onto this, so
            // without one `…/v3` + `contacts` becomes `…/v3contacts`.
            return rtrim($override, '/') . '/';
        }

        return 'https://www.zohoapis.' . $this->getSafeDataCenter() . '/books/v3/';
    }

    /**
     * The URL to register as an Authorized Redirect URI in the Zoho API console.
     *
     * A control panel route rather than an action URL, deliberately. `UrlHelper::actionUrl()`
     * produces `…/index.php?p=actions/zo/settings/callback` on any install without
     * `omitScriptNameInUrls`, and a redirect URI carrying a query string is at best ugly and at
     * worst refused — Zoho's own examples are all bare paths. A CP route gives
     * `…/admin/zo/oauth/callback` on a normally configured site.
     *
     * Zoho matches this string exactly, so it is shown on the settings screen for copying rather
     * than described in prose.
     */
    public function getRedirectUri(): string
    {
        // Craft appends `?site=` to CP URLs on a multi-site install. A redirect URI has to be one
        // fixed string registered in advance, so that has to come back off — but only that one
        // param, because on an install without `omitScriptNameInUrls` the *path itself* lives in
        // the query as `?p=admin/…`, and stripping the whole query string would leave a URL
        // pointing at the site root.
        return UrlHelper::removeParam(UrlHelper::cpUrl('zo/oauth/callback'), 'site');
    }

    /**
     * Whether the redirect URI still contains a query string, which happens on an install with
     * neither `omitScriptNameInUrls` nor `usePathInfo`. Worth warning about rather than letting
     * the merchant find out from Zoho.
     */
    public function getRedirectUriIsClean(): bool
    {
        return !str_contains($this->getRedirectUri(), '?');
    }

    /**
     * A `.com` fallback keeps a hand-edited config from producing `https://accounts.zoho.` and a
     * DNS failure that looks like an outage.
     */
    public function getSafeDataCenter(): string
    {
        // The data centre Zoho reported when this environment connected beats the setting: a token
        // from one region is rejected by every other. Before 5.0.1 the callback corrected the
        // setting instead, which wrote project config.
        $connected = self::connection()['dataCenter'] ?? null;

        if ($connected !== null && isset(self::DATA_CENTERS[$connected])) {
            return $connected;
        }

        return isset(self::DATA_CENTERS[$this->dataCenter]) ? $this->dataCenter : 'com';
    }

    /**
     * The OAuth scopes Zo asks for, narrowed to what the current configuration actually uses.
     * Asking for `fullaccess` would work and is what most integrations do; asking for less means a
     * leaked token cannot touch the merchant's bank feeds or payroll.
     *
     * @return string[]
     */
    public function getScopes(): array
    {
        $scopes = [
            'ZohoBooks.contacts.CREATE',
            'ZohoBooks.contacts.READ',
            'ZohoBooks.contacts.UPDATE',
            'ZohoBooks.invoices.CREATE',
            'ZohoBooks.invoices.READ',
            'ZohoBooks.invoices.UPDATE',
            'ZohoBooks.settings.READ',
        ];

        if ($this->syncItems) {
            $scopes[] = 'ZohoBooks.items.CREATE';
            $scopes[] = 'ZohoBooks.items.READ';
        }

        if ($this->syncPayments) {
            $scopes[] = 'ZohoBooks.customerpayments.CREATE';
            $scopes[] = 'ZohoBooks.customerpayments.READ';
        }

        if ($this->syncRefunds) {
            $scopes[] = 'ZohoBooks.creditnotes.CREATE';
            $scopes[] = 'ZohoBooks.creditnotes.READ';
        }

        if ($this->documentType !== self::DOCUMENT_INVOICE) {
            $scopes[] = 'ZohoBooks.salesorders.CREATE';
            $scopes[] = 'ZohoBooks.salesorders.READ';
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'clientId' => Craft::t('zo', 'Client ID'),
            'clientSecret' => Craft::t('zo', 'Client secret'),
            'refreshToken' => Craft::t('zo', 'Refresh token'),
            'organizationId' => Craft::t('zo', 'Organization ID'),
            'dataCenter' => Craft::t('zo', 'Data centre'),
            'taxMode' => Craft::t('zo', 'Tax handling'),
            'documentType' => Craft::t('zo', 'Document type'),
            'maxAttempts' => Craft::t('zo', 'Maximum attempts'),
        ];
    }
}
