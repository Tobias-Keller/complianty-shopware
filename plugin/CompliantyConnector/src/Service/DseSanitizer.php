<?php declare(strict_types=1);

namespace Complianty\Connector\Service;

/**
 * Schmale Whitelist für die Datenschutzerklärung aus dem Dienst.
 *
 * Gespeichert und ausgegeben wird nur die gefilterte Fassung, das Roh-HTML nie.
 */
final class DseSanitizer
{
    private const ALLOWED = 'h1,h2,h3,h4,h5,h6,p,br,ul,ol,li,strong,b,em,i,blockquote,table,thead,tbody,tr,'
        . 'th[colspan|rowspan],td[colspan|rowspan],a[href|rel]';

    public static function sanitize(string $html): string
    {
        // Kosmetik: Skript-/Style-Quelltext würde sonst als Text stehen bleiben. Das Sicherheitstor ist der Purifier.
        for ($pass = 0; $pass < 5; ++$pass) {
            $before = $html;
            $html = (string) preg_replace(
                '#<(script|style|textarea|title|iframe|object|noscript|template|svg|math)\b[^>]{0,1000}+>.*?(?:</\1\s*>|$)#is',
                '',
                $html,
            );
            if ($html === $before) {
                break;
            }
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer']);
        $config->set('HTML.TargetBlank', false);
        $config->set('Cache.DefinitionImpl', null);
        $config->set('AutoFormat.RemoveEmpty', false);

        $safe = (new \HTMLPurifier($config))->purify($html);

        // Jeder Link bekommt ein festes, sicheres rel. Nur echte Tags werden angefasst.
        return (string) preg_replace_callback(
            '/<a\s[^>]*>/i',
            static function (array $found): string {
                $tag = (string) preg_replace('/\srel="[^"]*"/i', '', $found[0]);

                return (string) preg_replace('/^<a\s+/i', '<a rel="noopener noreferrer" ', $tag);
            },
            $safe,
        );
    }

    public static function hasText(string $html): bool
    {
        return trim(strip_tags($html)) !== '';
    }
}
