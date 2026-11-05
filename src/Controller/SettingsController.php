<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\Account;
use App\Account\AccountStore;
use App\Account\Brands;
use App\Account\CurrentAccount;
use App\Account\KeyCheck;
use App\Account\KeyCipher;
use App\Account\Spending;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Ajustes» of the account: its provider keys (encrypted, never shown again whole), its monthly
 * spending and limit, its saved brands and its password.
 */
final class SettingsController extends AbstractController
{
    public const SECTIONS = ['claves' => 'Claves', 'gasto' => 'Gasto', 'marcas' => 'Marcas', 'cuenta' => 'Cuenta'];

    public function __construct(
        private readonly CurrentAccount $current,
        private readonly AccountStore $accounts,
        private readonly KeyCipher $cipher,
        private readonly KeyCheck $check,
        private readonly Spending $spending,
        private readonly Brands $brands,
    ) {
    }

    #[Route('/ajustes/{seccion}', name: 'settings', requirements: ['seccion' => 'claves|gasto|marcas|cuenta'], defaults: ['seccion' => 'claves'], methods: ['GET'])]
    public function index(string $seccion): Response
    {
        $account = $this->current->require();
        $keys = [];
        foreach (CurrentAccount::PROVIDERS + CurrentAccount::OPTIONAL as $provider => $info) {
            $value = $this->current->key($provider);
            $keys[$provider] = $info + ['required' => isset(CurrentAccount::PROVIDERS[$provider]), 'saved' => '' !== $value, 'hint' => '' === $value ? null : '…'.mb_substr($value, -4)];
        }

        return $this->render('settings/index.html.twig', [
            'section' => $seccion,
            'sections' => self::SECTIONS,
            'account' => $account,
            'keys' => $keys,
            'aihubUrl' => $this->current->aihubUrl(),
            'missing' => $this->current->missingKeys(),
            'spent' => $this->spending->month($account),
            'limit' => $account->monthlyLimit(),
            'videos' => $this->monthVideos($account),
        ]);
    }

    /** Saves the keys written; the ones left empty stay as they were. A key that fails its check is not saved. */
    #[Route('/ajustes/claves', name: 'settings_keys', methods: ['POST'])]
    public function keys(Request $request): JsonResponse
    {
        $account = $this->fresh();
        $errors = [];
        $notes = [];
        $saved = 0;
        foreach (array_keys(CurrentAccount::PROVIDERS + CurrentAccount::OPTIONAL) as $provider) {
            $value = trim($request->request->getString($provider));
            if ('' === $value) {
                continue;
            }
            if (mb_strlen($value) > 400 || 1 === preg_match('/\s/', $value)) {
                $errors[$provider] = 'Esto no parece una clave: pégala entera, sin espacios.';
                continue;
            }
            $result = $this->check->check($provider, $value);
            if (false === $result['ok']) {
                $errors[$provider] = $result['message'];
                continue;
            }
            if (null === $result['ok'] && 'aihub' !== $provider) {
                $notes[] = (CurrentAccount::PROVIDERS[$provider]['label'] ?? $provider).': guardada sin comprobar. '.$result['message'];
            }
            $account->data['keys'][$provider] = $this->cipher->encrypt($value);
            ++$saved;
        }
        $url = trim($request->request->getString('aihubUrl'));
        if ('' !== $url && 1 !== preg_match('#^https://[^\s/]+(/\S*)?$#', $url)) {
            $errors['aihubUrl'] = 'La dirección debe empezar por https://.';
        } else {
            $account->data['aihubUrl'] = rtrim($url, '/');
        }
        $this->accounts->save($account);
        if ([] !== $errors) {
            if ($saved > 0) {
                $errors[''] = 'Las demás claves sí se han guardado.';
            }

            return new JsonResponse(['errors' => $errors], 422);
        }

        return new JsonResponse(['ok' => true, 'notes' => $notes]);
    }

    #[Route('/ajustes/claves/{provider}/borrar', name: 'settings_key_delete', requirements: ['provider' => 'anthropic|elevenlabs|fal|openai|aihub'], methods: ['POST'])]
    public function deleteKey(string $provider): JsonResponse
    {
        $account = $this->fresh();
        unset($account->data['keys'][$provider]);
        $this->accounts->save($account);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/ajustes/limite', name: 'settings_limit', methods: ['POST'])]
    public function limit(Request $request): JsonResponse
    {
        $raw = str_replace(',', '.', trim($request->request->getString('limit')));
        if ('' !== $raw && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100000)) {
            return new JsonResponse(['errors' => ['limit' => 'Escribe una cantidad en dólares, por ejemplo 20, o déjalo vacío para no tener límite.']], 422);
        }
        $account = $this->fresh();
        $account->data['monthlyLimitUsd'] = '' === $raw ? null : round((float) $raw, 2);
        $this->accounts->save($account);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/ajustes/marcas/{id}/borrar', name: 'settings_brand_delete', requirements: ['id' => '[0-9a-z]{26}'], methods: ['POST'])]
    public function deleteBrand(string $id): JsonResponse
    {
        return $this->brands->delete($this->fresh(), $id) ? new JsonResponse(['ok' => true]) : new JsonResponse(['errors' => ['' => 'Esa marca ya no existe.']], 404);
    }

    #[Route('/marcas/{id}/logo', name: 'brand_logo', requirements: ['id' => '[0-9a-z]{26}'], methods: ['GET'])]
    public function brandLogo(string $id): Response
    {
        $file = $this->brands->logo($this->current->require(), $id) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($file);
    }

    #[Route('/ajustes/contrasena', name: 'settings_password', methods: ['POST'])]
    public function password(Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $account = $this->fresh();
        if (!$hasher->isPasswordValid($account, $request->request->getString('current'))) {
            return new JsonResponse(['errors' => ['current' => 'La contraseña actual no es correcta.']], 422);
        }
        $new = $request->request->getString('new');
        if (mb_strlen($new) < SecurityController::MIN_PASSWORD) {
            return new JsonResponse(['errors' => ['new' => \sprintf('La contraseña nueva necesita %d caracteres como mínimo.', SecurityController::MIN_PASSWORD)]], 422);
        }
        $account->data['passwordHash'] = $hasher->hashPassword($account, $new);
        $this->accounts->save($account);

        return new JsonResponse(['ok' => true]);
    }

    /** The account read again from its file, to change it. */
    private function fresh(): Account
    {
        return $this->accounts->get($this->current->require()->id()) ?? throw $this->createAccessDeniedException();
    }

    /**
     * What each video of the account cost this month, the most expensive first.
     *
     * @return list<array{id: string, name: string, usd: float}>
     */
    private function monthVideos(Account $account): array
    {
        $month = date('Y-m');
        $rows = [];
        foreach ($this->spending->jobsOf($account) as $job) {
            $usd = array_sum(array_map(static fn (array $c) => str_starts_with((string) $c['at'], $month) ? (float) $c['usd'] : 0.0, $job->data['costs'] ?? []));
            if ($usd > 0) {
                $input = $job->input();
                $rows[] = ['id' => $job->id(), 'name' => ($input['brand']['name'] ?? '') ?: mb_substr((string) $input['product'], 0, 40), 'usd' => round($usd, 4)];
            }
        }
        usort($rows, static fn (array $a, array $b) => $b['usd'] <=> $a['usd']);

        return $rows;
    }
}
