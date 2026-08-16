<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Owner = 'owner';
    case Vet = 'vet';
    case Admin = 'admin';
}
