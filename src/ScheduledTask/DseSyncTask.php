<?php declare(strict_types=1);

namespace Complianty\Connector\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class DseSyncTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'complianty.dse_sync';
    }

    public static function getDefaultInterval(): int
    {
        return 6 * 3600;
    }
}
