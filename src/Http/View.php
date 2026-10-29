<?php

declare(strict_types=1);

namespace App\Http;

final class View
{
    public function __construct(private readonly string $viewsDir)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): void
    {
        if (!function_exists('e')) {
            require __DIR__ . '/html.php';
        }

        extract($data, EXTR_SKIP);
        require $this->viewsDir . '/' . $template;
    }
}
