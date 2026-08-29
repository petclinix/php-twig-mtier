<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Role;
use App\Http\Session;
use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Service\AuthService;
use App\Service\Exception\EmailAlreadyRegisteredException;
use App\Service\ServiceFactory;
use Twig\Environment;

final class AuthController
{
    private readonly AuthService $auth;

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {
        $this->auth = $this->services->authService();
    }

    public function showRegister(): string
    {
        return $this->twig->render('auth/register.html.twig', ['errors' => [], 'old' => []]);
    }

    public function register(): string
    {
        $errors = new ErrorBag();

        $email = Input::string('email');
        $errors->addIf($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false, 'Enter a valid email address.');

        $password = Input::raw('password');
        $passwordConfirmation = Input::raw('password_confirmation');
        $errors->addIf(strlen($password) < 8, 'Password must be at least 8 characters.');
        $errors->addIf($password !== $passwordConfirmation, 'Passwords do not match.');

        $roleInput = Input::raw('role');
        $errors->addIf(!in_array($roleInput, ['owner', 'vet'], true), 'Choose a role.');

        $firstName = Input::string('first_name');
        $lastName = Input::string('last_name');
        $errors->addIf($firstName === '' || $lastName === '', 'First and last name are required.');

        $phone = Input::string('phone');
        $address = Input::string('address');
        $errors->addIf($roleInput === 'owner' && ($phone === '' || $address === ''), 'Phone and address are required for owners.');

        $specialty = Input::string('specialty');
        $errors->addIf($roleInput === 'vet' && $specialty === '', 'Specialty is required for vets.');

        if ($errors->isEmpty()) {
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
                $errors->add('An account with that email already exists.');
            }
        }

        return $this->twig->render('auth/register.html.twig', ['errors' => $errors->all(), 'old' => $_POST]);
    }

    public function showLogin(): string
    {
        return $this->twig->render('auth/login.html.twig', ['error' => null, 'old' => []]);
    }

    public function login(): string
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

        if (!$user->isActive) {
            return $this->twig->render('auth/login.html.twig', [
                'error' => 'This account has been deactivated.',
                'old' => ['email' => $email],
            ]);
        }

        Session::login($user->id, $user->role->value);
        $this->auth->recordLogin($user->id);

        header('Location: /dashboard');

        return '';
    }

    public function logout(): string
    {
        Session::logout();

        header('Location: /');

        return '';
    }
}
