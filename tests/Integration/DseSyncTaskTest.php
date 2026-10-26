<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\ScheduledTask\DseSyncTask;
use Complianty\Connector\ScheduledTask\DseSyncTaskHandler;
use Complianty\Connector\Service\DseStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskEntity;

#[CoversClass(DseSyncTask::class)]
#[CoversClass(DseSyncTaskHandler::class)]
final class DseSyncTaskTest extends PluginIntegrationTestCase
{
    public function testTaskRunsEverySixHours(): void
    {
        static::assertSame('complianty.dse_sync', DseSyncTask::getTaskName());
        static::assertSame(6 * 3600, DseSyncTask::getDefaultInterval());
    }

    public function testPluginInstallationRegistersTheTask(): void
    {
        /** @var EntityRepository<ScheduledTaskCollection> $repository */
        $repository = static::getContainer()->get('scheduled_task.repository');

        $tasks = $repository->search(
            (new Criteria())->addFilter(new EqualsFilter('name', 'complianty.dse_sync')),
            Context::createCLIContext(),
        );

        static::assertSame(1, $tasks->getTotal(), 'Shopware muss den Task bei der Installation anlegen, sonst läuft der Abgleich nie.');
        $task = $tasks->first();
        static::assertInstanceOf(ScheduledTaskEntity::class, $task);
        static::assertSame(6 * 3600, $task->getRunInterval());
        static::assertSame(DseSyncTask::class, $task->getScheduledTaskClass());
    }

    public function testHandlerSynchronisesThePolicy(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dsePayload('<p>Aktuell</p>'));

        $this->service(DseSyncTaskHandler::class)->run();

        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertStringContainsString('Aktuell', $record['html']);
        static::assertSame(['/dse'], $this->api->routes());
    }

    public function testHandlerWithoutConnectionDoesNothing(): void
    {
        $this->service(DseSyncTaskHandler::class)->run();

        static::assertSame([], $this->api->requests());
        static::assertNull($this->service(DseStore::class)->get());
    }

    public function testHandlerDoesNotFailWhenTheServiceIsDown(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 503, 'down');

        $this->service(DseSyncTaskHandler::class)->run();

        static::assertNull($this->service(DseStore::class)->get());
    }
}
