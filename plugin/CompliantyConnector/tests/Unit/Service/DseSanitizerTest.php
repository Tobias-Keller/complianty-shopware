<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Unit\Service;

use Complianty\Connector\Service\DseSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DseSanitizer::class)]
final class DseSanitizerTest extends TestCase
{
    /**
     * @param list<string> $forbidden
     */
    #[DataProvider('hostileInput')]
    public function testHostileMarkupIsRemoved(string $html, array $forbidden): void
    {
        $safe = DseSanitizer::sanitize($html);

        foreach ($forbidden as $needle) {
            static::assertStringNotContainsStringIgnoringCase($needle, $safe, \sprintf('"%s" must not survive in: %s', $needle, $safe));
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function hostileInput(): iterable
    {
        yield 'script tag with content' => ['<p>a</p><script>alert(1)</script><p>b</p>', ['<script', 'alert(1)']];
        yield 'style tag with content' => ['<style>body{display:none}</style><p>a</p>', ['<style', 'display:none']];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>', ['<iframe', 'evil.example']];
        yield 'object and embed' => ['<object data="x"></object><embed src="y">', ['<object', '<embed']];
        yield 'svg onload' => ['<svg onload="alert(1)"></svg>', ['<svg', 'onload', 'alert(1)']];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>', ['<img', 'onerror']];
        yield 'event handler on allowed tag' => ['<p onclick="alert(1)">x</p>', ['onclick', 'alert']];
        yield 'event handler on link' => ['<a href="https://a.example" onmouseover="x()">x</a>', ['onmouseover']];
        yield 'javascript link' => ['<a href="javascript:alert(1)">x</a>', ['javascript:']];
        yield 'mixed case javascript link' => ['<a href="JaVaScRiPt:alert(1)">x</a>', ['javascript:']];
        yield 'entity encoded javascript link' => ['<a href="&#106;avascript:alert(1)">x</a>', ['javascript:']];
        yield 'data link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:']];
        yield 'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>', ['vbscript:']];
        yield 'style attribute' => ['<p style="position:fixed;top:0">x</p>', ['style=', 'position']];
        yield 'class and id attributes' => ['<p class="a" id="b">x</p>', ['class=', 'id=']];
        yield 'form' => ['<form action="https://evil.example"><input name="pw"></form>', ['<form', '<input']];
        yield 'html comment' => ['<p>a</p><!-- geheim -->', ['<!--', 'geheim']];
        yield 'target blank' => ['<a href="https://a.example" target="_blank">x</a>', ['target=']];
        yield 'split script tag' => ['<scr<script>ipt>alert(1)</scr</script>ipt>', ['<script', 'alert(1)']];
        yield 'unclosed script' => ['<p>a</p><script>alert(1)', ['<script', 'alert(1)']];
        yield 'base tag' => ['<base href="https://evil.example/">', ['<base']];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', ['<meta', 'refresh']];
    }

    public function testAllowedStructureSurvives(): void
    {
        $html = '<h2>Titel</h2><p>Text <strong>fett</strong> und <em>kursiv</em></p>'
            . '<ul><li>eins</li></ul><ol><li>zwei</li></ol><blockquote>Zitat</blockquote>'
            . '<table><thead><tr><th colspan="2">Tool</th></tr></thead><tbody><tr><td rowspan="2">GA4</td><td>USA</td></tr></tbody></table>';

        $safe = DseSanitizer::sanitize($html);

        foreach (['<h2>Titel</h2>', '<strong>fett</strong>', '<em>kursiv</em>', '<ul><li>eins</li></ul>', '<ol><li>zwei</li></ol>', '<blockquote>', 'colspan="2"', 'rowspan="2"', '<tbody>'] as $expected) {
            static::assertStringContainsString($expected, $safe);
        }
    }

    public function testEveryLinkGetsSafeRel(): void
    {
        $safe = DseSanitizer::sanitize('<a href="https://a.example" rel="opener">a</a> <a href="mailto:x@y.example">b</a> <a href="http://c.example" rel="nofollow">c</a>');

        static::assertSame(3, substr_count($safe, 'rel="noopener noreferrer"'));
        static::assertStringNotContainsString('opener"', str_replace('noopener noreferrer', '', $safe));
        static::assertStringNotContainsString('nofollow', $safe);
    }

    public function testAllowedLinkSchemesSurvive(): void
    {
        $safe = DseSanitizer::sanitize('<a href="https://a.example/x">a</a><a href="http://b.example">b</a><a href="mailto:x@y.example">c</a>');

        static::assertStringContainsString('href="https://a.example/x"', $safe);
        static::assertStringContainsString('href="http://b.example"', $safe);
        static::assertStringContainsString('href="mailto:x@y.example"', $safe);
    }

    public function testTextOfDroppedWrapperTagsIsKept(): void
    {
        static::assertStringContainsString('sichtbarer Text', DseSanitizer::sanitize('<div><span>sichtbarer Text</span></div>'));
    }

    #[DataProvider('tagsOutsideTheAllowlist')]
    public function testTagsOutsideTheAllowlistAreDropped(string $tag): void
    {
        $safe = DseSanitizer::sanitize(\sprintf('<%1$s>Inhalt</%1$s>', $tag));

        static::assertStringNotContainsString('<' . $tag, $safe);
        static::assertStringContainsString('Inhalt', $safe);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tagsOutsideTheAllowlist(): iterable
    {
        foreach (['div', 'span', 'section', 'font', 'center', 'button', 'details', 'u', 'sup'] as $tag) {
            yield $tag => [$tag];
        }
    }

    public function testPlainTextStaysText(): void
    {
        $safe = DseSanitizer::sanitize('a < b && c > d');

        static::assertStringContainsString('a &lt; b', $safe);
        static::assertStringNotContainsString('<b', $safe);
    }

    public function testEmptyInput(): void
    {
        static::assertSame('', DseSanitizer::sanitize(''));
    }

    #[DataProvider('textChecks')]
    public function testHasText(string $html, bool $expected): void
    {
        static::assertSame($expected, DseSanitizer::hasText($html));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function textChecks(): iterable
    {
        yield 'text' => ['<p>Hallo</p>', true];
        yield 'only tags' => ['<p></p><ul><li></li></ul>', false];
        yield 'only whitespace' => ["<p> \n </p>", false];
        yield 'empty' => ['', false];
    }

    public function testLargeInputOfUnclosedTagsFinishesQuickly(): void
    {
        $start = microtime(true);
        DseSanitizer::sanitize(str_repeat('<a href="https://x.example" ', 5000));

        static::assertLessThan(5.0, microtime(true) - $start);
    }
}
