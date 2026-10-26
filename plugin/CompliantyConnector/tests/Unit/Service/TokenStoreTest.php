<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Unit\Service;

use Complianty\Connector\Service\TokenStore;
use Complianty\Connector\Tests\Support\InMemorySystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenStore::class)]
final class TokenStoreTest extends TestCase
{
    private const TOKEN = 'clp_site_FakeTokenForTests0123456789abcdefghijk';

    private InMemorySystemConfig $config;

    protected function setUp(): void
    {
        $this->config = new InMemorySystemConfig();
    }

    public function testRoundTrip(): void
    {
        $store = new TokenStore($this->config, 'secret-a');
        $store->set(self::TOKEN);

        static::assertSame(self::TOKEN, $store->get());
    }

    public function testStoredValueIsEncrypted(): void
    {
        (new TokenStore($this->config, 'secret-a'))->set(self::TOKEN);

        $raw = $this->config->raw(TokenStore::CONFIG_KEY);
        static::assertIsString($raw);
        static::assertStringNotContainsString('clp_site_', $raw);
        static::assertStringNotContainsString(self::TOKEN, (string) base64_decode($raw, true));
    }

    public function testSameTokenIsStoredWithDifferentCiphertext(): void
    {
        $store = new TokenStore($this->config, 'secret-a');

        $store->set(self::TOKEN);
        $first = $this->config->raw(TokenStore::CONFIG_KEY);
        $store->set(self::TOKEN);

        static::assertNotSame($first, $this->config->raw(TokenStore::CONFIG_KEY));
    }

    public function testOtherAppSecretCannotDecrypt(): void
    {
        (new TokenStore($this->config, 'secret-a'))->set(self::TOKEN);

        static::assertNull((new TokenStore($this->config, 'secret-b'))->get());
    }

    public function testNothingStored(): void
    {
        static::assertNull((new TokenStore($this->config, 'secret-a'))->get());
    }

    #[DataProvider('corruptValues')]
    public function testCorruptStoredValueYieldsNull(mixed $value): void
    {
        $this->config->set(TokenStore::CONFIG_KEY, $value);

        static::assertNull((new TokenStore($this->config, 'secret-a'))->get());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function corruptValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'not base64' => ['%%%'];
        yield 'too short' => [base64_encode('abc')];
        yield 'garbage of nonce length' => [base64_encode(str_repeat('x', \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 40))];
        yield 'wrong type' => [['a' => 1]];
    }

    public function testClear(): void
    {
        $store = new TokenStore($this->config, 'secret-a');
        $store->set(self::TOKEN);
        $store->clear();

        static::assertNull($store->get());
        static::assertNull($store->hint());
    }

    public function testHintRevealsOnlyThePrefix(): void
    {
        $store = new TokenStore($this->config, 'secret-a');
        $store->set(self::TOKEN);

        $hint = $store->hint();

        static::assertSame('clp_site_Fake…', $hint);
        static::assertStringNotContainsString(substr(self::TOKEN, 20), $hint);
    }

    #[DataProvider('tokens')]
    public function testIsWellFormed(string $token, bool $expected): void
    {
        static::assertSame($expected, TokenStore::isWellFormed($token));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tokens(): iterable
    {
        yield 'valid' => [self::TOKEN, true];
        yield 'minimum length' => ['clp_site_' . str_repeat('a', 20), true];
        yield 'too short' => ['clp_site_' . str_repeat('a', 19), false];
        yield 'too long' => ['clp_site_' . str_repeat('a', 121), false];
        yield 'wrong prefix' => ['clp_live_' . str_repeat('a', 30), false];
        yield 'no prefix' => [str_repeat('a', 40), false];
        yield 'illegal characters' => ['clp_site_' . str_repeat('a', 30) . '!', false];
        yield 'trailing newline' => [self::TOKEN . "\n", false];
        yield 'empty' => ['', false];
    }

    public function testSetDoesNotValidate(): void
    {
        // Die Prüfung liegt beim Aufrufer. get() lehnt Unbrauchbares beim Lesen trotzdem ab.
        $store = new TokenStore($this->config, 'secret-a');
        $store->set('not-a-token');

        static::assertNull($store->get());
    }
}
