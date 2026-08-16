<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Repository\AvailabilityRepository;
use DateTimeImmutable;
use Twig\Environment;

final class AvailabilityController
{
    use ResolvesVet;

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $vet = $this->currentVet();
        $slots = (new AvailabilityRepository())->findAllByVetId($vet->id);

        return $this->twig->render('vet/availability/index.html.twig', ['slots' => $slots, 'errors' => [], 'old' => []]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function store(array $vars): string
    {
        $vet = $this->currentVet();

        $startsAtInput = trim((string) ($_POST['starts_at'] ?? ''));
        $endsAtInput = trim((string) ($_POST['ends_at'] ?? ''));

        $errors = [];
        $startsAt = null;
        $endsAt = null;

        if ($startsAtInput === '') {
            $errors[] = 'Choose a start time.';
        } else {
            $startsAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startsAtInput) ?: null;
            if ($startsAt === null) {
                $errors[] = 'Start time must be valid.';
            }
        }

        if ($endsAtInput === '') {
            $errors[] = 'Choose an end time.';
        } else {
            $endsAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endsAtInput) ?: null;
            if ($endsAt === null) {
                $errors[] = 'End time must be valid.';
            }
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors[] = 'End time must be after the start time.';
        }

        if ($errors === [] && $startsAt !== null && $endsAt !== null) {
            (new AvailabilityRepository())->create($vet->id, $startsAt, $endsAt);

            header('Location: /vet/availability');

            return '';
        }

        $slots = (new AvailabilityRepository())->findAllByVetId($vet->id);

        return $this->twig->render('vet/availability/index.html.twig', ['slots' => $slots, 'errors' => $errors, 'old' => $_POST]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function destroy(array $vars): string
    {
        $vet = $this->currentVet();
        (new AvailabilityRepository())->delete((int) $vars['id'], $vet->id);

        header('Location: /vet/availability');

        return '';
    }
}
