<?php

declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

final class PetPhotoUploadException extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new self('Photo must be smaller than 5 MB.');
    }

    public static function unsupportedType(): self
    {
        return new self('Photo must be a JPEG, PNG, GIF, or WebP image.');
    }

    public static function uploadFailed(): self
    {
        return new self('Photo upload failed. Please try again.');
    }
}
