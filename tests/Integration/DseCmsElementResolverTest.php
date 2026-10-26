<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Cms\DseCmsElementResolver;
use Complianty\Connector\Service\DseStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

#[CoversClass(DseCmsElementResolver::class)]
final class DseCmsElementResolverTest extends PluginIntegrationTestCase
{
    private const TEMPLATE = '@CompliantyConnector/storefront/element/cms-element-complianty-dse.html.twig';

    public function testElementTypeAndNoCriteria(): void
    {
        $resolver = $this->service(DseCmsElementResolver::class);

        static::assertSame('complianty-dse', $resolver->getType());
        static::assertNull($resolver->collect(new CmsSlotEntity(), $this->resolverContext()));
    }

    public function testWithoutTokenAndCopyNothingIsShownAndNothingIsRequested(): void
    {
        $slot = $this->resolve();

        static::assertSame('', $this->html($slot));
        static::assertSame([], $this->api->requests());
        static::assertSame('', $this->render($slot), 'Ohne Kopie wird nicht einmal der Rahmen ausgegeben.');
    }

    public function testFirstVisitWithTokenFetchesTheCopyOnce(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dsePayload('<h2>Datenschutz</h2><p>Text</p>'));

        $first = $this->resolve();
        $second = $this->resolve();

        static::assertSame('<h2>Datenschutz</h2><p>Text</p>', $this->html($first));
        static::assertSame($this->html($first), $this->html($second));
        static::assertSame(['/dse'], $this->api->routes(), 'Der zweite Besucher bekommt die lokale Kopie, ohne dass der Dienst gefragt wird.');
    }

    public function testStaleCopyIsServedWithoutContactingTheService(): void
    {
        $this->storeToken();
        $this->service(DseStore::class)->apply(self::dsePayload('<p>Alte Fassung</p>'), time() - DseStore::MAX_AGE - 3600);
        $this->api->respond('/dse', 200, self::dsePayload('<p>Neue Fassung</p>'));

        $slot = $this->resolve();

        static::assertStringContainsString('Alte Fassung', $this->html($slot));
        static::assertSame([], $this->api->requests(), 'Besucher lösen nie einen Abruf aus, wenn eine Kopie existiert.');
    }

    public function testNotApprovedWithoutCopyShowsNothing(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dseState('not_approved'));

        $slot = $this->resolve();

        static::assertSame('', $this->html($slot));
        static::assertSame('', $this->render($slot));
    }

    public function testTamperedDatabaseValueIsFilteredOnOutput(): void
    {
        $this->service(SystemConfigService::class)->set(DseStore::CONFIG_KEY, [
            'html' => '<p>Text</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a>',
            'state' => 'active',
            'remote_state' => 'active',
            'approved_at' => '2026-10-26T08:00:00Z',
            'generated_at' => null,
            'checked_at' => time(),
        ]);

        $slot = $this->resolve();
        $output = $this->render($slot);

        static::assertStringContainsString('<p>Text</p>', $output);
        foreach (['<script', 'alert(', 'onerror', 'javascript:', '<img'] as $forbidden) {
            static::assertStringNotContainsString($forbidden, $output);
        }
    }

    public function testTemplateWrapsTheCopyInItsContainer(): void
    {
        $this->service(DseStore::class)->apply(self::dsePayload('<h2>Datenschutz</h2>'), time());

        $output = $this->render($this->resolve());

        static::assertStringContainsString('class="cms-element-complianty-dse complianty-dse"', $output);
        static::assertStringContainsString('<h2>Datenschutz</h2>', $output);
    }

    private function resolve(): CmsSlotEntity
    {
        $slot = new CmsSlotEntity();
        $slot->setUniqueIdentifier(Uuid::randomHex());
        $slot->setType('complianty-dse');

        $this->service(DseCmsElementResolver::class)->enrich($slot, $this->resolverContext(), new ElementDataCollection());

        return $slot;
    }

    private function resolverContext(): ResolverContext
    {
        return new ResolverContext($this->createMock(SalesChannelContext::class), new Request());
    }

    private function html(CmsSlotEntity $slot): string
    {
        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);

        return (string) $data->get('html');
    }

    private function render(CmsSlotEntity $slot): string
    {
        return trim($this->service(Environment::class)->render(self::TEMPLATE, ['element' => $slot]));
    }
}
