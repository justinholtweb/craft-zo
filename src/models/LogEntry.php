<?php

namespace justinholtweb\zo\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * One request to (or decision about) Zoho Books.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?string $method = null;
    public ?string $endpoint = null;
    public ?int $statusCode = null;
    public ?int $zohoCode = null;
    public ?int $durationMs = null;
    public ?int $elementId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        foreach (['dateCreated', 'dateUpdated'] as $attribute) {
            if ($this->$attribute !== null && !$this->$attribute instanceof DateTime) {
                $this->$attribute = DateTimeHelper::toDateTime($this->$attribute) ?: null;
            }
        }
    }

    /**
     * @return string[]
     */
    public static function levels(): array
    {
        return [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR];
    }
}
