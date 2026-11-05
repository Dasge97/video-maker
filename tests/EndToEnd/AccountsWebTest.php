<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Account\Account;
use App\Account\AccountStore;
use App\Job\JobStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The web with accounts: nothing without logging in, each account sees only its own videos,
 * and without keys it is sent to «Ajustes».
 */
final class AccountsWebTest extends WebTestCase
{
    public function testWithoutLoggingInEverythingAsksToLogIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos');
        self::assertResponseRedirects('/entrar');
        $client->request('GET', '/entrar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Entra');
        $client->request('GET', '/registro');
        self::assertResponseStatusCodeSame(404, 'The sign up is closed unless REGISTRO_ABIERTO=1.');
    }

    public function testTheLoginFormLetsTheAccountIn(): void
    {
        $client = static::createClient();
        $this->account('entra-'.bin2hex(random_bytes(3)).'@example.com', $email);
        $crawler = $client->request('GET', '/entrar');
        $client->submit($crawler->selectButton('Entrar')->form(['email' => $email, 'password' => 'una-contrasena-larga']));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseRedirects('/ajustes', null, 'Without keys it goes to «Ajustes».');
        $client->followRedirect();
        self::assertSelectorTextContains('.notice', 'faltan las claves');
    }

    public function testAnAccountDoesNotSeeTheVideosOfAnother(): void
    {
        $client = static::createClient();
        $mine = $this->account('una-'.bin2hex(random_bytes(3)).'@example.com');
        $other = $this->account('otra-'.bin2hex(random_bytes(3)).'@example.com');
        /** @var JobStore $jobs */
        $jobs = static::getContainer()->get(JobStore::class);
        $theirs = $jobs->create(['mode' => 'voice_over', 'product' => 'Lo de la otra cuenta', 'brand' => ['name' => 'Ajena']], $other->id());
        $own = $jobs->create(['mode' => 'voice_over', 'product' => 'Lo mío', 'brand' => ['name' => 'Propia']], $mine->id());
        $jobs->locked($own->id(), static function ($j): void { $j->data['status'] = 'proposal'; });
        $jobs->locked($theirs->id(), static function ($j): void { $j->data['status'] = 'proposal'; });

        $client->loginUser($mine);
        $client->request('GET', '/videos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.videos', 'Propia');
        self::assertSelectorTextNotContains('.videos', 'Ajena');
        $client->request('GET', '/videos/'.$theirs->id().'/estado');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/videos/'.$theirs->id().'/borrar');
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($jobs->get($theirs->id()));

        $client->request('POST', '/videos/'.$own->id().'/borrar');
        self::assertResponseIsSuccessful();
        self::assertNull($jobs->get($own->id()));
        $jobs->delete($theirs->id());
    }

    private function account(string $email, ?string &$out = null): Account
    {
        $container = static::getContainer();
        /** @var AccountStore $accounts */
        $accounts = $container->get(AccountStore::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $account = $accounts->create($email, '');
        $account->data['passwordHash'] = $hasher->hashPassword($account, 'una-contrasena-larga');
        $accounts->save($account);
        $out = $account->email();

        return $account;
    }
}
