<?php

declare(strict_types=1);

namespace DocBlocker\TypeMapper;

interface TypeMapperInterface
{
    /**
     * @param string $table
     * @return \Generator<string, array{string, string}>
     */
    public function run(string $table): \Generator;
}
