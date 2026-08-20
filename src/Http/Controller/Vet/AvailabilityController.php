<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AvailabilityRepository;
use Twig\Environment;

final class AvailabilityController
{
    use ResolvesVet;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $vet = $this->currentVet();
        $slots = (new AvailabilityRepository())->findAllByVetId($vet->id);

        return $this->twig->render('vet/availability/index.html.twig', ['slots' => $slots, 'errors' => [], 'old' => []]);
    }

    public function store(): string
    {
        $vet = $this->currentVet();

        $errors = new ErrorBag();
        $startsAt = null;
        $endsAt = null;

        $startsAtInput = Input::string('starts_at');
        if ($startsAtInput === '') {
            $errors->add('Choose a start time.');
        } else {
            $startsAt = Validate::date($startsAtInput, 'Y-m-d\TH:i');
            $errors->addIf($startsAt === null, 'Start time must be valid.');
        }

        $endsAtInput = Input::string('ends_at');
        if ($endsAtInput === '') {
            $errors->add('Choose an end time.');
        } else {
            $endsAt = Validate::date($endsAtInput, 'Y-m-d\TH:i');
            $errors->addIf($endsAt === null, 'End time must be valid.');
        }

        $errors->addIf($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt, 'End time must be after the start time.');

        if ($errors->isEmpty() && $startsAt !== null && $endsAt !== null) {
            (new AvailabilityRepository())->create($vet->id, $startsAt, $endsAt);

            header('Location: /vet/availability');

            return '';
        }

        $slots = (new AvailabilityRepository())->findAllByVetId($vet->id);

        return $this->twig->render('vet/availability/index.html.twig', ['slots' => $slots, 'errors' => $errors->all(), 'old' => $_POST]);
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
