<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\Account;
use App\Account\AccountStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Logging in, and signing up when REGISTRO_ABIERTO=1. Each account pays its own videos with its
 * own keys, so an open sign up costs the server nothing but disk.
 */
final class SecurityController extends AbstractController
{
    public const MIN_PASSWORD = 10;

    public function __construct(
        private readonly AccountStore $accounts,
        #[Autowire('%env(bool:default::REGISTRO_ABIERTO)%')]
        private readonly ?bool $signUpOpen = false,
    ) {
    }

    #[Route('/entrar', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser() instanceof Account) {
            return $this->redirectToRoute('home');
        }
        $error = $auth->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'email' => $auth->getLastUsername(),
            'error' => null === $error ? null : (str_contains($error::class, 'TooManyLogin') ? 'Demasiados intentos. Espera un minuto y vuelve a probar.' : 'El correo o la contraseña no son correctos.'),
            'signUpOpen' => true === $this->signUpOpen,
        ]);
    }

    #[Route('/registro', name: 'signup', methods: ['GET', 'POST'])]
    public function signUp(Request $request, UserPasswordHasherInterface $hasher, Security $security): Response
    {
        if (true !== $this->signUpOpen) {
            throw $this->createNotFoundException();
        }
        $values = ['name' => '', 'email' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('signup', $request->request->getString('_csrf_token'))) {
                $errors[''] = 'La página ha caducado. Vuelve a enviar el formulario.';
            }
            $values = ['name' => mb_substr(trim($request->request->getString('name')), 0, 80), 'email' => AccountStore::normalEmail($request->request->getString('email'))];
            $password = $request->request->getString('password');
            if (false === filter_var($values['email'], \FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Escribe un correo válido.';
            } elseif (null !== $this->accounts->byEmail($values['email'])) {
                $errors['email'] = 'Ya hay una cuenta con este correo. Entra con ella.';
            }
            if (mb_strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = \sprintf('La contraseña necesita %d caracteres como mínimo.', self::MIN_PASSWORD);
            }
            if ([] === $errors) {
                $account = $this->accounts->create($values['email'], $values['name']);
                $account->data['passwordHash'] = $hasher->hashPassword($account, $password);
                $this->accounts->save($account);
                $security->login($account, 'form_login', 'main');

                return $this->redirectToRoute('settings', ['seccion' => 'claves']);
            }
        }

        return $this->render('security/signup.html.twig', ['values' => $values, 'errors' => $errors, 'minPassword' => self::MIN_PASSWORD]);
    }

    #[Route('/salir', name: 'logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('El cortafuegos cierra la sesión antes de llegar aquí.');
    }
}
