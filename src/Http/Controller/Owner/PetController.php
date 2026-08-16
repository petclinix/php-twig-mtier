<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Repository\PetRepository;
use DateTimeImmutable;
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

        $name = trim((string) ($_POST['name'] ?? ''));
        $species = trim((string) ($_POST['species'] ?? ''));
        $breed = trim((string) ($_POST['breed'] ?? ''));
        $birthDateInput = trim((string) ($_POST['birth_date'] ?? ''));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($species === '') {
            $errors[] = 'Species is required.';
        }

        $birthDate = null;
        if ($birthDateInput !== '') {
            $birthDate = DateTimeImmutable::createFromFormat('Y-m-d', $birthDateInput) ?: null;
            if ($birthDate === null) {
                $errors[] = 'Birth date must be a valid date.';
            }
        }

        if ($errors === []) {
            (new PetRepository())->create($owner->id, $name, $species, $breed !== '' ? $breed : null, $birthDate);

            header('Location: /owner/pets');

            return '';
        }

        $pets = (new PetRepository())->findAllByOwnerId($owner->id);

        return $this->twig->render('owner/pets/index.html.twig', ['pets' => $pets, 'errors' => $errors, 'old' => $_POST]);
    }
}
