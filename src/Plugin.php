<?php

namespace justinholtweb\zo;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Transactions;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\zo\elements\actions\SyncToZoho;
use justinholtweb\zo\elements\conditions\ZohoStatusConditionRule;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\services\Alerts;
use justinholtweb\zo\services\Api;
use justinholtweb\zo\services\Auth;
use justinholtweb\zo\services\Connection;
use justinholtweb\zo\services\Contacts;
use justinholtweb\zo\services\Documents;
use justinholtweb\zo\services\Items;
use justinholtweb\zo\services\Links;
use justinholtweb\zo\services\Log;
use justinholtweb\zo\services\Sync;
use justinholtweb\zo\twig\ZoVariable;
use justinholtweb\zo\widgets\HealthWidget;
use yii\base\Event;

/**
 * Zo — Zoho Books for Craft Commerce.
 *
 * @property-read Alerts $alerts
 * @property-read Api $api
 * @property-read Auth $auth
 * @property-read Connection $connection
 * @property-read Contacts $contacts
 * @property-read Documents $documents
 * @property-read Items $items
 * @property-read Links $links
 * @property-read Log $log
 * @property-read Sync $sync
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'zo';

    public string $schemaVersion = '5.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'alerts' => ['class' => Alerts::class],
                'api' => ['class' => Api::class],
                'auth' => ['class' => Auth::class],
                'connection' => ['class' => Connection::class],
                'contacts' => ['class' => Contacts::class],
                'documents' => ['class' => Documents::class],
                'items' => ['class' => Items::class],
                'links' => ['class' => Links::class],
                'log' => ['class' => Log::class],
                'sync' => ['class' => Sync::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerWidgets();

        // Zo can be installed while Commerce is disabled or mid-upgrade, and everything below
        // reaches for an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEvents();
        $this->_registerOrderEditPanel();
        $this->_registerOrderIndex();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function getAlerts(): Alerts
    {
        return $this->get('alerts');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getConnection(): Connection
    {
        return $this->get('connection');
    }

    public function getContacts(): Contacts
    {
        return $this->get('contacts');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getItems(): Items
    {
        return $this->get('items');
    }

    public function getLinks(): Links
    {
        return $this->get('links');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('zo/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'commerceReady' => self::commerceIsReady(),
            'statusOptions' => $this->getOrderStatusOptions(),
            'taxCategoryOptions' => $this->getTaxCategoryOptions(),
            'gatewayOptions' => $this->getGatewayOptions(),
            'dataCenterOptions' => $this->getDataCenterOptions(),
            'paymentModeOptions' => $this->getPaymentModeOptions(),
            'customFieldRows' => $this->getSettings()->getCustomFieldRows(),
            'taxMapRows' => $this->getSettings()->getTaxMapRows(),
            'paymentModeRows' => $this->getSettings()->getPaymentModeMapRows(),
            'depositAccountRows' => $this->getSettings()->getDepositAccountMapRows(),
        ]);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getDataCenterOptions(): array
    {
        $options = [];

        foreach (Settings::DATA_CENTERS as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getPaymentModeOptions(): array
    {
        $labels = [
            'creditcard' => Craft::t('zo', 'Credit card'),
            'banktransfer' => Craft::t('zo', 'Bank transfer'),
            'bankremittance' => Craft::t('zo', 'Bank remittance'),
            'check' => Craft::t('zo', 'Check'),
            'cash' => Craft::t('zo', 'Cash'),
            'autotransaction' => Craft::t('zo', 'Auto transaction'),
            'others' => Craft::t('zo', 'Other'),
        ];

        $options = [];

        foreach (Settings::paymentModes() as $mode) {
            $options[] = ['label' => $labels[$mode] ?? $mode, 'value' => $mode];
        }

        return $options;
    }

    /**
     * Commerce's order statuses as `{label, value}` rows.
     *
     * Built here rather than in the template so the settings screen still renders when Commerce is
     * missing — a plugin whose settings screen 500s is a plugin nobody can turn off.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getOrderStatusOptions(): array
    {
        if (!self::commerceIsReady()) {
            return [];
        }

        $options = [];

        try {
            $statuses = \craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses();
        } catch (\Throwable) {
            return [];
        }

        foreach ($statuses as $status) {
            $options[] = ['label' => $status->name, 'value' => $status->handle];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getTaxCategoryOptions(): array
    {
        if (!self::commerceIsReady()) {
            return [];
        }

        $options = [];

        try {
            $categories = \craft\commerce\Plugin::getInstance()->getTaxCategories()->getAllTaxCategories();
        } catch (\Throwable) {
            return [];
        }

        foreach ($categories as $category) {
            $options[] = ['label' => (string)$category->name, 'value' => (string)$category->handle];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getGatewayOptions(): array
    {
        if (!self::commerceIsReady()) {
            return [];
        }

        $options = [];

        try {
            $gateways = \craft\commerce\Plugin::getInstance()->getGateways()->getAllGateways();
        } catch (\Throwable) {
            return [];
        }

        foreach ($gateways as $gateway) {
            $options[] = ['label' => (string)$gateway->name, 'value' => (string)$gateway->handle];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('zo', 'Zo');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('zo-viewSync')) {
            $subNav['sync'] = [
                'label' => Craft::t('zo', 'Sync'),
                'url' => 'zo/sync',
            ];
        }

        if ($user->checkPermission('zo-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('zo', 'Log'),
                'url' => 'zo/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('zo', 'Settings'),
                'url' => 'settings/plugins/zo',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('zo', ZoVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('zo', 'Zo'),
                    'permissions' => [
                        'zo-viewSync' => [
                            'label' => Craft::t('zo', 'View what has been synced'),
                            'nested' => [
                                'zo-syncOrders' => [
                                    'label' => Craft::t('zo', 'Sync orders to Zoho Books'),
                                ],
                                'zo-manageLinks' => [
                                    'label' => Craft::t('zo', 'Unlink orders from Zoho Books'),
                                ],
                            ],
                        ],
                        'zo-viewLog' => [
                            'label' => Craft::t('zo', 'View the connection log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['zo'] = 'zo/sync/index';
                $event->rules['zo/sync'] = 'zo/sync/index';
                $event->rules['zo/log'] = 'zo/log/index';
                $event->rules['zo/log/<entryId:\d+>'] = 'zo/log/detail';
                // Where Zoho sends the merchant back after consent. A CP route so the registered
                // redirect URI is a bare path — see Settings::getRedirectUri().
                $event->rules['zo/oauth/callback'] = 'zo/settings/callback';
            }
        );
    }

    /**
     * The three moments an order is worth sending.
     */
    private function _registerOrderEvents(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                $plugin = Plugin::getInstance();

                if (!$plugin->getSettings()->autoSyncOnComplete) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $plugin->getSync()->queue($order);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ORDER_PAID,
            static function(Event $event) {
                $plugin = Plugin::getInstance();

                if (!$plugin->getSettings()->autoSyncOnPaid) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $plugin->getSync()->queue($order);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(OrderStatusEvent $event) {
                $plugin = Plugin::getInstance();
                $handles = $plugin->getSettings()->syncOnStatusHandles;

                if ($handles === []) {
                    return;
                }

                $handle = $event->order->getOrderStatus()?->handle;

                if ($handle !== null && in_array($handle, $handles, true)) {
                    $plugin->getSync()->queue($event->order);
                }
            }
        );

        // A payment or refund landing after the order was already invoiced. Without this, an
        // order captured or refunded a day later shows as paid in Commerce and unpaid in the
        // books until somebody re-syncs it by hand.
        Event::on(
            Transactions::class,
            Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            static function(TransactionEvent $event) {
                $plugin = Plugin::getInstance();
                $settings = $plugin->getSettings();
                $transaction = $event->transaction;

                if ($transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                    return;
                }

                $isPayment = in_array($transaction->type, [
                    TransactionRecord::TYPE_PURCHASE,
                    TransactionRecord::TYPE_CAPTURE,
                ], true);
                $isRefund = $transaction->type === TransactionRecord::TYPE_REFUND;

                if (!($isPayment && $settings->syncPayments) && !($isRefund && $settings->syncRefunds)) {
                    return;
                }

                $order = $transaction->getOrder();

                if ($order === null || !$order->isCompleted) {
                    return;
                }

                $plugin->getSync()->queue($order);
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = HealthWidget::class;
            }
        );
    }

    /**
     * The Orders index: a Zoho column, a "Zoho Books status" filter, and a bulk "Sync to Zoho
     * Books" action.
     *
     * Every hook is attached to the Order class, not to Element: the table-attribute events do not
     * say which element type is asking.
     */
    private function _registerOrderIndex(): void
    {
        // Registered unconditionally. A rule registered only for some users or settings is
        // dropped from saved conditions, and a custom source built on it silently widens to
        // every order.
        Event::on(
            OrderCondition::class,
            OrderCondition::EVENT_REGISTER_CONDITION_RULES,
            static function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = ZohoStatusConditionRule::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['zoStatus'] = ['label' => Craft::t('zo', 'Zoho Books')];
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'zoStatus') {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $event->html = Plugin::getInstance()->orderStatusHtml($order);
                $event->handled = true;
            }
        );

        // One lookup per index page rather than per row.
        Event::on(
            OrderQuery::class,
            OrderQuery::EVENT_AFTER_POPULATE_ELEMENTS,
            static function(PopulateElementsEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || ($request->getActionSegments()[0] ?? null) !== 'element-indexes') {
                    return;
                }

                $ids = [];

                foreach ($event->elements as $element) {
                    if ($element instanceof Order && $element->id) {
                        $ids[] = $element->id;
                    }
                }

                Plugin::getInstance()->getLinks()->prefetchOrderStatuses($ids);
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            static function(RegisterElementActionsEvent $event) {
                // Actions are not saved anywhere, so offering this only to people who may use it
                // is safe; the action checks the permission again when it runs.
                if (Craft::$app->getUser()->checkPermission('zo-syncOrders')) {
                    $event->actions[] = SyncToZoho::class;
                }
            }
        );
    }

    /**
     * The Orders index cell: a status dot and a word, linking to the order's Zoho rows.
     */
    public function orderStatusHtml(Order $order): string
    {
        if (!$order->id || !Craft::$app->getUser()->checkPermission('zo-viewSync')) {
            return '';
        }

        $status = $this->getLinks()->orderStatus($order->id);
        $label = Links::orderStatusOptions()[$status] ?? $status;
        $color = match ($status) {
            Link::ORDER_SYNCED => 'green',
            Link::ORDER_NOT_RECONCILED => 'orange',
            Link::ORDER_FAILED => 'red',
            Link::ORDER_PENDING => 'yellow',
            default => 'disabled',
        };

        if ($status === Link::ORDER_NONE) {
            return Html::tag('span', Html::encode($label), ['class' => 'light']);
        }

        return Html::tag('span', '', ['class' => ['status', $color], 'aria-hidden' => 'true'])
            . Html::encode($label);
    }

    /**
     * Zo's panel on Commerce's own order edit screen — where a merchant actually looks when they
     * want to know whether an order made it into the books.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('zo-viewSync')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('zo/_order-panel', [
                'order' => $order,
                'status' => $this->getSync()->getOrderStatus($order),
                'canSync' => Craft::$app->getUser()->checkPermission('zo-syncOrders'),
                'canManage' => Craft::$app->getUser()->checkPermission('zo-manageLinks'),
                'tolerance' => $this->getSettings()->varianceTolerance,
            ], View::TEMPLATE_MODE_CP);
        });
    }
}
