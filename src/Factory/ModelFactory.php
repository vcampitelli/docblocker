<?php

declare(strict_types=1);

namespace DocBlocker\Factory;

class ModelFactory
{
    /**
     * @template T
     * @param string<T> $class
     * @return T
     */
    public function __invoke(string $class): object
    {
        return \app($class);
    }
}
