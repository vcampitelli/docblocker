<?php

declare(strict_types=1);

namespace DocBlocker\TypeMapper;

interface TypeMapperInterface
{
    public function __invoke(string $type): string;
}
