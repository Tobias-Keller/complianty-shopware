<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Api\Result;
use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Service\DseSync;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DseSync::class)]
final class DseSyncTest extends PluginIntegrationTestCase
{
    public function testWithoutTokenNothingIsRequested(): void
    {
        $result = $this->service(DseSync::class)->sync();

        static::assertSame(Result::NO_TOKEN, $result->kind);
        static::assertSame([], $this->api->requests());
        static::assertNull($this->service(DseStore::class)->get());
    }

    public function testActivePolicyIsStoredFiltered(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dsePayload('<h2>DSE</h2><p onclick="x()">Text</p><iframe src="https://evil.example"></iframe>'));

        $result = $this->service(DseSync::class)->sync();

        static::assertTrue($result->isOk());
        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertSame('active', $record['state']);
        static::assertStringContainsString('<h2>DSE</h2>', $record['html']);
        static::assertStringNotContainsString('onclick', $record['html']);
        static::assertStringNotContainsString('iframe', $record['html']);
    }

    public function testRevokingTheApprovalInComplianyKeepsTheLastApprovedCopy(): void
    {
        $this->storeToken();
        $sync = $this->service(DseSync::class);
        $this->api->respond('/dse', 200, self::dsePayload('<p>Freigegeben</p>'));
        $sync->sync();

        $this->api->respond('/dse', 200, self::dseState('not_approved'));
        $sync->sync();

        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertStringContainsString('Freigegeben', $record['html']);
        static::assertSame('not_approved', $record['remote_state']);
    }

    public function testServerErrorLeavesTheCopyUntouched(): void
    {
        $this->storeToken();
        $sync = $this->service(DseSync::class);
        $this->api->respond('/dse', 200, self::dsePayload('<p>Freigegeben</p>'));
        $sync->sync();
        $before = $this->service(DseStore::class)->get();

        $this->api->respond('/dse', 500, 'boom');
        $result = $sync->sync();

        static::assertSame(Result::UNREACHABLE, $result->kind);
        static::assertSame($before, $this->service(DseStore::class)->get());
    }

    public function testInvalidAnswerLeavesTheCopyUntouched(): void
    {
        $this->storeToken();
        $sync = $this->service(DseSync::class);
        $this->api->respond('/dse', 200, self::dsePayload('<p>Freigegeben</p>'));
        $sync->sync();
        $before = $this->service(DseStore::class)->get();

        $this->api->respond('/dse', 200, ['state' => 'active', 'html' => null, 'approved_at' => null, 'generated_at' => null]);
        $result = $sync->sync();

        static::assertSame(Result::INVALID, $result->kind);
        static::assertSame($before, $this->service(DseStore::class)->get());
    }

    public function testAnActiveAnswerThatFiltersToNothingIsRejected(): void
    {
        $this->storeToken();
        $sync = $this->service(DseSync::class);
        $this->api->respond('/dse', 200, self::dsePayload('<p>Freigegeben</p>'));
        $sync->sync();

        $this->api->respond('/dse', 200, self::dsePayload('<script>alert(1)</script>'));
        $result = $sync->sync();

        static::assertSame(Result::INVALID, $result->kind);
        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertStringContainsString('Freigegeben', $record['html']);
    }

    public function testFirstFetchRequestsTheDseOnce(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dsePayload());

        $this->service(DseSync::class)->firstFetch();

        static::assertSame(['/dse'], $this->api->routes());
        static::assertNotNull($this->service(DseStore::class)->get());
    }

    public function testFirstFetchSurvivesAnUnreachableService(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 503, 'down');

        $this->service(DseSync::class)->firstFetch();

        static::assertNull($this->service(DseStore::class)->get());
    }
}
