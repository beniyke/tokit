<?php

declare(strict_types=1);

/**
 * Anchor Framework
 *
 * Wrapper for compressed Tokit strings.
 *
 * @author BenIyke <beniyke34@gmail.com> | Twitter: @BigBeniyke
 */

namespace Tokit;

final class TokitString
{
    private string $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function __toString(): string
    {
        return $this->data;
    }

    public function decompress(): array
    {
        return Tokit::decompress($this->data);
    }

    public function tokens(): int
    {
        return (int) ceil(strlen($this->data) / 3.85);
    }
}
