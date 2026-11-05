<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Account\Account;
use App\Account\AccountStore;
use App\Account\Brands;
use App\Account\KeyCipher;
use App\Reference\Transcriber;
use PHPUnit\Framework\TestCase;

/**
 * The accounts in files, their keys encrypted, their saved brands, and the segments made from
 * the words of the transcription of ElevenLabs.
 */
final class AccountsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/vm-accounts-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testAnAccountIsFoundByItsEmailWhateverTheCase(): void
    {
        $store = new AccountStore($this->dir);
        $account = $store->create(' Ana@Example.com ', 'Ana');
        self::assertSame('ana@example.com', $account->email());
        self::assertSame($account->id(), $store->byEmail('ANA@example.com')?->id());
        self::assertNull($store->byEmail('otra@example.com'));
        self::assertSame($account->id(), $store->refreshUser($account)->id());
    }

    public function testTheSessionNeverKeepsTheKeys(): void
    {
        $account = new Account('/tmp/x', ['id' => '01abc', 'email' => 'a@b.es', 'passwordHash' => 'h', 'keys' => ['fal' => 'cifrada']]);
        $copy = unserialize(serialize($account));
        self::assertInstanceOf(Account::class, $copy);
        self::assertArrayNotHasKey('keys', $copy->data);
        self::assertSame('a@b.es', $copy->getUserIdentifier());
    }

    public function testAKeyIsEncryptedAndReadBack(): void
    {
        $cipher = new KeyCipher(base64_encode(random_bytes(32)));
        $encrypted = $cipher->encrypt('sk-secreta-1234');
        self::assertStringNotContainsString('secreta', $encrypted);
        self::assertSame('sk-secreta-1234', $cipher->decrypt($encrypted));
        self::assertNull((new KeyCipher(base64_encode(random_bytes(32))))->decrypt($encrypted), 'Another secret cannot read it.');
        self::assertNull($cipher->decrypt('basura'));
    }

    public function testWithoutTheSecretNothingIsEncrypted(): void
    {
        $this->expectExceptionMessage('APP_KEYS_SECRET');
        (new KeyCipher(''))->encrypt('x');
    }

    public function testABrandWithTheSameNameIsUpdated(): void
    {
        $store = new AccountStore($this->dir);
        $brands = new Brands($store);
        $account = $store->create('a@b.es', '');
        $logo = $this->dir.'/logo.png';
        imagepng(imagecreatetruecolor(8, 8), $logo);

        $first = $brands->save($account, 'Brisa', ['#112233', '#FFFFFF'], 'Poppins', ['barato'], $logo);
        $again = $brands->save($account, 'brisa', ['#445566'], 'NoExiste', [], null);
        $account = $store->get($account->id());
        self::assertCount(1, $account->brands());
        self::assertSame($first['id'], $again['id']);
        self::assertSame(['#445566'], $account->brands()[0]['colors']);
        self::assertSame('Poppins', $account->brands()[0]['font'], 'An unknown font falls back to the default one.');
        self::assertNotNull($brands->logo($account, $first['id']), 'Without a new logo it keeps the one it had.');

        self::assertTrue($brands->delete($account, $first['id']));
        self::assertSame([], $store->get($account->id())->brands());
        self::assertFileDoesNotExist($account->path((string) $first['logo']));
    }

    public function testTheWordsOfElevenLabsBecomeSegments(): void
    {
        $words = [
            ['text' => 'Hola', 'start' => 0.0, 'end' => 0.4, 'type' => 'word'],
            ['text' => ' ', 'start' => 0.4, 'end' => 0.5, 'type' => 'spacing'],
            ['text' => 'amigos.', 'start' => 0.5, 'end' => 1.0, 'type' => 'word'],
            ['text' => 'Mira', 'start' => 1.1, 'end' => 1.4, 'type' => 'word'],
            ['text' => 'esto', 'start' => 2.5, 'end' => 2.9, 'type' => 'word'],
        ];
        self::assertSame([
            ['start' => 0.0, 'end' => 1.0, 'text' => 'Hola amigos.'],
            ['start' => 1.1, 'end' => 1.4, 'text' => 'Mira'],
            ['start' => 2.5, 'end' => 2.9, 'text' => 'esto'],
        ], Transcriber::segments($words));
    }
}
