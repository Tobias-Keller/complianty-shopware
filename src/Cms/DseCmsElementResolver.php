<?php declare(strict_types=1);

namespace Complianty\Connector\Cms;

use Complianty\Connector\Service\DseSanitizer;
use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Service\DseSync;
use Complianty\Connector\Service\TokenStore;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;

/**
 * Stellt die lokale, gefilterte Kopie der Datenschutzerklärung für das CMS-Element bereit.
 *
 * Mit Kopie gibt es nie einen synchronen Aufruf bei Besuchern. Der Abgleich läuft per Zeitplan.
 */
class DseCmsElementResolver extends AbstractCmsElementResolver
{
    public function __construct(
        private readonly DseStore $store,
        private readonly DseSync $sync,
        private readonly TokenStore $tokens,
    ) {
    }

    public function getType(): string
    {
        return 'complianty-dse';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $record = $this->store->get();

        if ($record === null && $this->tokens->get() !== null) {
            $this->sync->firstFetch();
            $record = $this->store->get();
        }

        // Beim Speichern und bei der Ausgabe gefiltert: ein manipulierter DB-Wert kann kein Markup einschleusen.
        $html = $record !== null && $record['html'] !== '' ? DseSanitizer::sanitize($record['html']) : '';

        $slot->setData(new ArrayStruct(['html' => $html]));
    }
}
