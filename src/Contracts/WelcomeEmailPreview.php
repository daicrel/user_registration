<?php

declare(strict_types=1);

namespace App\Contracts;

interface WelcomeEmailPreview
{
    /**
     * @return array{to: string, name: string, subject: string, body: string, disclaimer: string}|null
     */
    public function lastEmail(): ?array;
}
