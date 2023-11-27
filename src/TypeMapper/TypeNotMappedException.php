<?php

declare(strict_types=1);

namespace DocBlocker\TypeMapper;

class TypeNotMappedException extends \DomainException
{
    public function __construct(private readonly string $type)
    {
        parent::__construct("Tipo de coluna desconhecida: {$type}");
    }

    public function getType(): string
    {
        return $this->type;
    }
}
