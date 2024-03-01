<?php

namespace DocBlocker\TypeMapper;

class CannotProcessTableException extends \DomainException
{
    public function __construct(private readonly string $table)
    {
        parent::__construct("Erro ao buscar detalhes da tabela {$table}");
    }

    public function getTable(): string
    {
        return $this->table;
    }
}
