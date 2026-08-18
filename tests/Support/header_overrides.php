<?php

declare(strict_types=1);

namespace App\Http\Controller {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        \App\Tests\Support\HeaderSpy::record($header);
    }
}

namespace App\Http\Controller\Owner {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        \App\Tests\Support\HeaderSpy::record($header);
    }
}

namespace App\Http\Controller\Vet {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        \App\Tests\Support\HeaderSpy::record($header);
    }
}

namespace App\Http\Controller\Admin {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        \App\Tests\Support\HeaderSpy::record($header);
    }
}
