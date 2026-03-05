<?php

namespace App\Ai\Dto;

class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {}
}
