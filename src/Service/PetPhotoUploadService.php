<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\PetPhotoUploadException;

final class PetPhotoUploadService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const PUBLIC_PATH_PREFIX = '/uploads/pets/';

    /**
     * @param callable(string, string): bool $move
     */
    public function __construct(
        private readonly string $uploadDir = __DIR__ . '/../../public/uploads/pets',
        private readonly mixed $move = 'move_uploaded_file',
    ) {}

    /**
     * @param array{tmp_name?: string, error?: int, size?: int}|null $file
     */
    public function upload(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw PetPhotoUploadException::uploadFailed();
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw PetPhotoUploadException::tooLarge();
        }

        $extension = self::ALLOWED_MIME_TYPES[(string) mime_content_type($file['tmp_name'])] ?? null;

        if ($extension === null) {
            throw PetPhotoUploadException::unsupportedType();
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw PetPhotoUploadException::uploadFailed();
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!($this->move)($file['tmp_name'], $this->uploadDir . '/' . $filename)) {
            throw PetPhotoUploadException::uploadFailed();
        }

        return self::PUBLIC_PATH_PREFIX . $filename;
    }
}
