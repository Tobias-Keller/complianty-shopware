<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Unit\Service;

use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Tests\Support\InMemorySystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DseStore::class)]
final class DseStoreTest extends TestCase
{
    private InMemorySystemConfig $config;

    private DseStore $store;

    protected function setUp(): void
    {
        $this->config = new InMemorySystemConfig();
        $this->store = new DseStore($this->config);
    }

    public function testNothingStoredYieldsNull(): void
    {
        static::assertNull($this->store->get());
    }

    public function testActivePolicyIsStoredFiltered(): void
    {
        $applied = $this->store->apply(self::remote('active', '<h2>DSE</h2><script>alert(1)</script><p onclick="x()">Text</p>', '2026-10-26T08:00:00Z'), 1000);

        $record = $this->store->get();

        static::assertTrue($applied);
        static::assertNotNull($record);
        static::assertSame('active', $record['state']);
        static::assertSame('active', $record['remote_state']);
        static::assertSame('2026-10-26T08:00:00Z', $record['approved_at']);
        static::assertSame(1000, $record['checked_at']);
        static::assertStringContainsString('<h2>DSE</h2>', $record['html']);
        static::assertStringNotContainsString('script', $record['html']);
        static::assertStringNotContainsString('onclick', $record['html']);
    }

    public function testActiveResponseThatFiltersToNothingIsRejectedAndKeepsOldCopy(): void
    {
        $this->store->apply(self::remote('active', '<p>Alt</p>', '2026-10-26T08:00:00Z'), 1000);

        $applied = $this->store->apply(self::remote('active', '<script>alert(1)</script><iframe src="x"></iframe>', '2026-10-27T08:00:00Z'), 2000);

        static::assertFalse($applied);
        $record = $this->store->get();
        static::assertNotNull($record);
        static::assertStringContainsString('Alt', $record['html']);
        static::assertSame(1000, $record['checked_at']);
    }

    public function testNotApprovedKeepsTheLastApprovedCopy(): void
    {
        $this->store->apply(self::remote('active', '<p>Freigegeben</p>', '2026-10-26T08:00:00Z'), 1000);

        $this->store->apply(self::remote('not_approved'), 2000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertStringContainsString('Freigegeben', $record['html']);
        static::assertSame('active', $record['state']);
        static::assertSame('not_approved', $record['remote_state']);
        static::assertSame('2026-10-26T08:00:00Z', $record['approved_at']);
        static::assertSame(2000, $record['checked_at']);
    }

    public function testMissingKeepsTheLastApprovedCopy(): void
    {
        $this->store->apply(self::remote('active', '<p>Freigegeben</p>', '2026-10-26T08:00:00Z'), 1000);

        $this->store->apply(self::remote('missing'), 2000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertStringContainsString('Freigegeben', $record['html']);
        static::assertSame('missing', $record['remote_state']);
    }

    public function testNotApprovedWithoutCopyStoresNoHtml(): void
    {
        $this->store->apply(self::remote('not_approved'), 1000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertSame('', $record['html']);
        static::assertSame('not_approved', $record['remote_state']);
    }

    public function testLapsedNoticeReplacesTheCopy(): void
    {
        $this->store->apply(self::remote('active', '<p>Freigegeben</p>', '2026-10-26T08:00:00Z'), 1000);

        $this->store->apply(self::remote('lapsed', '<p>Abo abgelaufen</p>'), 2000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertSame('lapsed', $record['state']);
        static::assertStringContainsString('Abo abgelaufen', $record['html']);
        static::assertStringNotContainsString('Freigegeben', $record['html']);
        static::assertNull($record['approved_at']);
    }

    public function testLapsedNoticeIsDroppedOnceTheServiceNoLongerSendsIt(): void
    {
        $this->store->apply(self::remote('lapsed', '<p>Abo abgelaufen</p>'), 1000);

        // Abo wieder aktiv, aber Erklärung (noch) nicht freigegeben: der Hinweistext darf nicht stehen bleiben.
        $this->store->apply(self::remote('not_approved'), 2000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertSame('', $record['html']);
        static::assertSame('', $record['state']);
        static::assertNull($record['approved_at']);
    }

    public function testActiveAfterLapsedRestoresApprovedPolicy(): void
    {
        $this->store->apply(self::remote('lapsed', '<p>Abo abgelaufen</p>'), 1000);

        $this->store->apply(self::remote('active', '<p>Wieder da</p>', '2026-11-01T08:00:00Z'), 2000);
        $record = $this->store->get();

        static::assertNotNull($record);
        static::assertSame('active', $record['state']);
        static::assertStringContainsString('Wieder da', $record['html']);
    }

    public function testIsStale(): void
    {
        static::assertTrue($this->store->isStale(null, 1000));

        $record = ['checked_at' => 1000];
        static::assertFalse($this->store->isStale($record, 1000 + DseStore::MAX_AGE - 1));
        static::assertTrue($this->store->isStale($record, 1000 + DseStore::MAX_AGE));
    }

    public function testClear(): void
    {
        $this->store->apply(self::remote('active', '<p>x</p>', '2026-10-26T08:00:00Z'), 1000);
        $this->store->clear();

        static::assertNull($this->store->get());
    }

    #[DataProvider('corruptRecords')]
    public function testCorruptRecordYieldsNull(mixed $value): void
    {
        $this->config->set(DseStore::CONFIG_KEY, $value);

        static::assertNull($this->store->get());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function corruptRecords(): iterable
    {
        yield 'string' => ['x'];
        yield 'without checked_at' => [['html' => '<p>x</p>']];
        yield 'checked_at as string' => [['html' => '<p>x</p>', 'checked_at' => 'gestern']];
    }

    public function testRecordWithWrongFieldTypesIsNormalised(): void
    {
        $this->config->set(DseStore::CONFIG_KEY, ['html' => ['x'], 'state' => 5, 'approved_at' => 7, 'checked_at' => 10]);

        $record = $this->store->get();

        static::assertSame(['html' => '', 'state' => '', 'remote_state' => '', 'approved_at' => null, 'generated_at' => null, 'checked_at' => 10], $record);
    }

    /**
     * @return array<string, mixed>
     */
    private static function remote(string $state, ?string $html = null, ?string $approvedAt = null): array
    {
        return ['state' => $state, 'html' => $html, 'approved_at' => $approvedAt, 'generated_at' => '2026-10-25T08:00:00Z'];
    }
}
