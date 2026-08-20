<?php

namespace justinholtweb\zo\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\zo\jobs\BackfillJob;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Sync screen and the actions on it.
 */
class SyncController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('zo-viewSync');

        return true;
    }

    /**
     * What is in the books, what is not, and what went wrong.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $type = (string)$request->getQueryParam('type', '');
        $status = (string)$request->getQueryParam('status', '');
        $search = trim((string)$request->getQueryParam('search', ''));
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;

        $criteria = array_filter([
            'type' => $type !== '' ? $type : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'hasVariance' => $status === 'variance' ? true : null,
        ]);

        // "Variance" is a filter on synced rows, not a status of its own — a document that does
        // not reconcile still exists in Zoho, and showing it as anything but synced would invite
        // somebody to re-send it.
        if ($status === 'variance') {
            $criteria['status'] = Link::STATUS_SYNCED;
        }

        $links = $plugin->getLinks()->getLinks($criteria, $perPage, ($page - 1) * $perPage);
        $total = $plugin->getLinks()->countLinks($criteria);

        return $this->renderTemplate('zo/sync/_index', [
            'links' => $links,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $plugin->getLinks()->getStats(),
            'type' => $type,
            'status' => $status,
            'search' => $search,
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'canSync' => Craft::$app->getUser()->checkPermission('zo-syncOrders'),
            'canManage' => Craft::$app->getUser()->checkPermission('zo-manageLinks'),
            'unsynced' => $plugin->getSync()->countUnsyncedOrders(),
        ]);
    }

    /**
     * Sync one order now, from the order edit screen or the Sync index.
     */
    public function actionOrder(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('zo-syncOrders');

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }

        // Clicking the button is the merchant overriding the eligibility rules, so it forces.
        $result = Plugin::getInstance()->getSync()->syncOrder($order, true);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $result->getIsSuccessful(),
                'message' => $result->getSummary(),
                'steps' => $result->steps,
                'invoiceNumber' => $result->invoiceNumber,
                'variance' => $result->variance,
            ]);
        }

        if ($result->getIsSuccessful()) {
            $this->setSuccessFlash($result->getSummary());
        } else {
            $this->setFailFlash($result->getSummary());
        }

        return $this->redirectToPostedUrl($order, UrlHelper::cpUrl('commerce/orders/' . $order->id));
    }

    /**
     * Put a failed link back in the queue's way.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('zo-syncOrders');

        $link = $this->linkFromRequest();

        if (!Plugin::getInstance()->getLinks()->resetForRetry($link)) {
            return $this->asFailure(Craft::t('zo', 'That document is already in Zoho Books. Unlink it first if you really want a new one.'));
        }

        if ($link->elementId !== null) {
            $order = Order::find()->id($link->elementId)->status(null)->one();

            if ($order instanceof Order) {
                Plugin::getInstance()->getSync()->queue($order, true);
            }
        }

        return $this->asSuccess(Craft::t('zo', 'Queued for another attempt.'));
    }

    /**
     * Forget a link, so the next sync creates a fresh document.
     */
    public function actionUnlink(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('zo-manageLinks');

        $link = $this->linkFromRequest();

        Plugin::getInstance()->getLinks()->delete($link);

        return $this->asSuccess(Craft::t(
            'zo',
            'Unlinked. The document is still in Zoho Books — delete it there too, or the next sync will create a second one.'
        ));
    }

    /**
     * Queue a backfill of everything that has never reached the books.
     */
    public function actionBackfill(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('zo-syncOrders');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Backfill is a Pro feature.');
        }

        $since = trim((string)Craft::$app->getRequest()->getBodyParam('since', ''));

        Queue::push(new BackfillJob([
            'since' => $since !== '' ? $since . ' 00:00:00' : null,
        ]));

        return $this->asSuccess(Craft::t('zo', 'Backfill queued.'));
    }

    /**
     * The payload an order would be sent as, for the order edit screen.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $orderId = (int)Craft::$app->getRequest()->getRequiredParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $preview = Plugin::getInstance()->getSync()->preview($order);

        return $this->asJson([
            'success' => true,
            'payload' => Json::encode($preview['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'projectedTotal' => $preview['projectedTotal'],
            'craftTotal' => $preview['craftTotal'],
        ]);
    }

    // Private
    // =========================================================================

    private function linkFromRequest(): Link
    {
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('linkId');
        $link = Plugin::getInstance()->getLinks()->getLinkById($id);

        if ($link === null) {
            throw new NotFoundHttpException('Link not found.');
        }

        return $link;
    }
}
