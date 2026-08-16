<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Role;
use App\Http\Session;
use App\Repository\OwnerRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Service\AuthService;
use App\Service\EmailAlreadyRegisteredException;
use Twig\Environment;

final class AuthController
{
    private readonly AuthService $auth;

    public function __construct(private readonly Environment $twig)
    {
        $this->auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository());
    }

    /**
     * @param array<string, string> $vars
     */
    public function showRegister(array $vars): string
    {
        return $this->twig->render('auth/register.html.twig', ['errors' => [], 'old' => []]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function register(array $vars): string
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
        $roleInput = (string) ($_POST['role'] ?? '');
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $specialty = trim((string) ($_POST['specialty'] ?? ''));

        $errors = [];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $passwordConfirmation) {
            $errors[] = 'Passwords do not match.';
        }
        if (!in_array($roleInput, ['owner', 'vet'], true)) {
            $errors[] = 'Choose a role.';
        }
        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First and last name are required.';
        }
        if ($roleInput === 'owner' && ($phone === '' || $address === '')) {
            $errors[] = 'Phone and address are required for owners.';
        }
        if ($roleInput === 'vet' && $specialty === '') {
            $errors[] = 'Specialty is required for vets.';
        }

        if ($errors === []) {
            $role = Role::from($roleInput);
            $profile = $role === Role::Owner
                ? ['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'address' => $address]
                : ['first_name' => $firstName, 'last_name' => $lastName, 'specialty' => $specialty];

            try {
                $user = $this->auth->register($email, $password, $role, $profile);
                Session::login($user->id, $user->role->value);

                header('Location: /dashboard');

                return '';
            } catch (EmailAlreadyRegisteredException) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        return $this->twig->render('auth/register.html.twig', ['errors' => $errors, 'old' => $_POST]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function showLogin(array $vars): string
    {
        return $this->twig->render('auth/login.html.twig', ['error' => null, 'old' => []]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function login(array $vars): string
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = $this->auth->attempt($email, $password);

        if ($user === null) {
            return $this->twig->render('auth/login.html.twig', [
                'error' => 'Invalid email or password.',
                'old' => ['email' => $email],
            ]);
        }

        Session::login($user->id, $user->role->value);

        header('Location: /dashboard');

        return '';
    }

    /**
     * @param array<string, string> $vars
     */
    public function logout(array $vars): string
    {
        Session::logout();

        header('Location: /');

        return '';
    }
}
