<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Exception\PetPhotoUploadException;
use App\Service\PetPhotoUploadService;
use PHPUnit\Framework\TestCase;

final class PetPhotoUploadServiceTest extends TestCase
{
    /** A minimal valid 1x1 transparent PNG. */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    private string $uploadDir;
    private PetPhotoUploadService $service;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/petclinix-test-uploads-' . bin2hex(random_bytes(6));
        // A test-only mover: production uses move_uploaded_file(), which only
        // ever succeeds for a genuine PHP-SAPI upload and always fails in a
        // CLI/test context — copy() exercises the same validation/naming logic
        // against real temp files instead.
        $this->service = new PetPhotoUploadService($this->uploadDir, copy(...));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            foreach (glob($this->uploadDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->uploadDir);
        }
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'petclinix-upload-test-');
        file_put_contents($path, $contents);

        return $path;
    }

    public function testUploadReturnsNullWhenNoFileGiven(): void
    {
        self::assertNull($this->service->upload(null));
    }

    public function testUploadReturnsNullWhenErrorIsNoFile(): void
    {
        self::assertNull($this->service->upload(['error' => UPLOAD_ERR_NO_FILE]));
    }

    public function testUploadStoresValidImageAndReturnsPublicPath(): void
    {
        //arrange
        $tmpPath = $this->tempFile(self::PNG_BYTES);

        //act
        $url = $this->service->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen(self::PNG_BYTES), 'tmp_name' => $tmpPath]);

        //assert
        self::assertStringStartsWith('/uploads/pets/', $url);
        self::assertStringEndsWith('.png', $url);
        $storedFile = $this->uploadDir . '/' . basename($url);
        self::assertFileExists($storedFile);
        self::assertSame(self::PNG_BYTES, file_get_contents($storedFile));
    }

    public function testUploadThrowsForOversizedFile(): void
    {
        $this->expectException(PetPhotoUploadException::class);

        $this->service->upload(['error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024, 'tmp_name' => '/nonexistent']);
    }

    public function testUploadThrowsForUnsupportedMimeType(): void
    {
        //arrange
        $tmpPath = $this->tempFile('just some plain text, not an image');

        //assert
        $this->expectException(PetPhotoUploadException::class);

        //act
        $this->service->upload(['error' => UPLOAD_ERR_OK, 'size' => 30, 'tmp_name' => $tmpPath]);
    }

    public function testUploadThrowsWhenPhpReportsUploadError(): void
    {
        $this->expectException(PetPhotoUploadException::class);

        $this->service->upload(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '']);
    }
}
