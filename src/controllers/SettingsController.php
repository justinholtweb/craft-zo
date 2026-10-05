<?php

namespace justinholtweb\zo\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\zo\Plugin;
use yii\web\Response;

/**
 * The connect flow and the buttons behind the settings screen.
 *
 * Everything that is not the OAuth round trip posts through `Craft.sendActionRequest`. That is not
 * a style preference: the settings screen is itself a form, and a nested `<form>` is not merely
 * ignored — the HTML parser drops the inner tag and keeps its children, so a second `action` input
 * ends up inside the page's own form and the next save posts to the wrong controller.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // An admin's job, but not a project-config change: since 5.0.1 the connection is stored in
        // Zo's own table, so each environment — the live site included — connects for itself.
        $this->requireAdmin(false);

        return true;
    }

    /**
     * Send the admin to Zoho to authorise Zo.
     */
    public function actionConnect(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->getCanConnect()) {
            Craft::$app->getSession()->setError(Craft::t('zo', 'Save a client ID and secret first.'));

            return $this->redirect('settings/plugins/zo');
        }

        $state = $plugin->getAuth()->generateState();

        return $this->redirect($plugin->getAuth()->getAuthorizationUrl($state));
    }

    /**
     * Where Zoho sends the admin back.
     */
    public function actionCallback(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        $error = $request->getQueryParam('error');

        if ($error !== null) {
            $session->setError(Craft::t('zo', 'Zoho returned “{error}”.', ['error' => $error]));

            return $this->redirect('settings/plugins/zo');
        }

        if (!$plugin->getAuth()->consumeState($request->getQueryParam('state'))) {
            // Either a replay, or the admin started the flow in another browser. Neither should
            // be allowed to write a credential.
            $session->setError(Craft::t('zo', 'That authorisation did not match this session. Start the connection again.'));

            return $this->redirect('settings/plugins/zo');
        }

        $code = (string)$request->getQueryParam('code');

        if ($code === '') {
            $session->setError(Craft::t('zo', 'Zoho sent no authorization code back.'));

            return $this->redirect('settings/plugins/zo');
        }

        try {
            $tokens = $plugin->getAuth()->exchangeCode($code, $request->getQueryParam('accounts-server'));
        } catch (\Throwable $e) {
            $session->setError($e->getMessage());

            return $this->redirect('settings/plugins/zo');
        }

        // Stored encrypted in Zo's own table, never as a setting: settings are project config, and
        // project config is committed. Zoho also says which data centre the merchant actually
        // authorised against, which is kept alongside and beats the dropdown — turning the single
        // most common misconfiguration into a non-event without writing the setting.
        $plugin->getConnection()->store($tokens['refreshToken'], $tokens['dataCenter']);
        $settings = $plugin->getSettings();

        $plugin->getAuth()->forgetAccessToken();

        // Pick the organization automatically when there is only one to pick — which is the case
        // for almost every store, and is otherwise a numeric id the merchant has to go and find.
        if ($settings->getParsedOrganizationId() === '') {
            $this->autoSelectOrganization();
        }

        $session->setNotice(Craft::t('zo', 'Connected to Zoho Books.'));

        return $this->redirect('settings/plugins/zo');
    }

    /**
     * Forget the connection, at both ends.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();

        Craft::$app->getSession()->setNotice(Craft::t('zo', 'Disconnected from Zoho Books.'));

        return $this->redirect('settings/plugins/zo');
    }

    /**
     * Check the credentials and report which organization they land in.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->getIsConnected()) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('zo', 'Zo is not connected yet.'),
            ]);
        }

        return $this->asJson(Plugin::getInstance()->getApi()->testConnection());
    }

    /**
     * The organizations the connected account can see, for the picker.
     */
    public function actionOrganizations(): Response
    {
        $this->requireAcceptsJson();

        try {
            return $this->asJson([
                'success' => true,
                'organizations' => Plugin::getInstance()->getApi()->getOrganizations(),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The organization's tax rates, for the tax mapping table.
     */
    public function actionTaxes(): Response
    {
        $this->requireAcceptsJson();

        try {
            return $this->asJson([
                'success' => true,
                'taxes' => Plugin::getInstance()->getApi()->getTaxes(),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The organization's bank and cash accounts, for the deposit-account setting.
     */
    public function actionAccounts(): Response
    {
        $this->requireAcceptsJson();

        try {
            return $this->asJson([
                'success' => true,
                'accounts' => Plugin::getInstance()->getApi()->getAccounts(),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The exact JSON the most recent order would be sent as.
     *
     * Worth having on the settings screen rather than only on an order: the questions a merchant
     * has while configuring tax mode and number sources are all answered by looking at one
     * payload, and nobody should have to place a test order to see it.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $orderId = Craft::$app->getRequest()->getParam('orderId');

        $order = $orderId
            ? Order::find()->id((int)$orderId)->status(null)->one()
            : Order::find()->isCompleted(true)->orderBy(['commerce_orders.dateOrdered' => SORT_DESC])->one();

        if (!$order instanceof Order) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('zo', 'There are no completed orders to preview.'),
            ]);
        }

        try {
            $preview = Plugin::getInstance()->getSync()->preview($order);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'orderNumber' => $order->reference ?: $order->getShortNumber(),
            'orderUrl' => UrlHelper::cpUrl('commerce/orders/' . $order->id),
            'payload' => Json::encode($preview['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'contact' => Json::encode($preview['contact'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'projectedTotal' => $preview['projectedTotal'],
            'craftTotal' => $preview['craftTotal'],
            'matches' => abs($preview['projectedTotal'] - $preview['craftTotal']) <= 0.005,
        ]);
    }

    // Private
    // =========================================================================

    private function autoSelectOrganization(): void
    {
        $plugin = Plugin::getInstance();

        try {
            $organizations = $plugin->getApi()->getOrganizations();
        } catch (\Throwable) {
            return;
        }

        if (count($organizations) !== 1) {
            return;
        }

        // Kept with the connection rather than written to the setting, which stays free for an
        // explicit choice (or an `$ENV` reference) and is read first.
        $plugin->getConnection()->setOrganizationId((string)$organizations[0]['id']);
    }
}
