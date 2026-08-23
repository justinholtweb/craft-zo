<?php

namespace justinholtweb\zo\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log screen.
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('zo-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        $criteria = array_filter([
            'action' => (string)$request->getQueryParam('action', '') ?: null,
            'level' => (string)$request->getQueryParam('level', '') ?: null,
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;

        return $this->renderTemplate('zo/log/_index', [
            'entries' => $log->getEntries($criteria, $perPage, ($page - 1) * $perPage),
            'total' => $log->count($criteria),
            'page' => $page,
            'perPage' => $perPage,
            'level' => $criteria['level'] ?? '',
            'action' => $criteria['action'] ?? '',
            'levels' => LogEntry::levels(),
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found.');
        }

        return $this->renderTemplate('zo/log/_detail', [
            'entry' => $entry,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('zo-viewLog');

        $count = Plugin::getInstance()->getLog()->clear();

        return $this->asSuccess(Craft::t('zo', '{count} log entries cleared.', ['count' => $count]));
    }
}
