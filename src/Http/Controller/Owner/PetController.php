<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\PetRepository;
use Twig\Environment;

final class PetController
{
    use ResolvesOwner;

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $owner = $this->currentOwner();
        $pets = (new PetRepository())->findAllByOwnerId($owner->id);

        return $this->twig->render('owner/pets/index.html.twig', ['pets' => $pets, 'errors' => [], 'old' => []]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function store(array $vars): string
    {
        $owner = $this->currentOwner();

        $errors = new ErrorBag();

        $name = Input::string('name');
        $errors->addIf($name === '', 'Name is required.');

        $species = Input::string('species');
        $errors->addIf($species === '', 'Species is required.');

        $breed = Input::string('breed');

        $birthDateInput = Input::string('birth_date');
        $birthDate = Validate::date($birthDateInput, 'Y-m-d');
        $errors->addIf($birthDateInput !== '' && $birthDate === null, 'Birth date must be a valid date.');

        if ($errors->isEmpty()) {
            (new PetRepository())->create($owner->id, $name, $species, $breed !== '' ? $breed : null, $birthDate);

            header('Location: /owner/pets');

            return '';
        }

        $pets = (new PetRepository())->findAllByOwnerId($owner->id);

        return $this->twig->render('owner/pets/index.html.twig', ['pets' => $pets, 'errors' => $errors->all(), 'old' => $_POST]);
    }
}
