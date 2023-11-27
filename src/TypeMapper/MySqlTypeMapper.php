<?php

declare(strict_types=1);

namespace DocBlocker\TypeMapper;

readonly class MySqlTypeMapper implements TypeMapperInterface
{
    /**
     * @param string $type
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function __invoke(string $type): string
    {
        if ($type === 'tinyint(1)') {
            return 'boolean';
        }

        [$type] = \explode('(', $type);
        switch ($type) {
            case 'int':
            case 'smallint':
            case 'tinyint':
            case 'mediumint':
            case 'bigint':
            case 'timestamp':
                return 'int';

            case 'float':
            case 'double':
            case 'decimal':
            case 'real':
            case 'fixed':
                return 'float';

            case 'varchar':
            case 'char':
            case 'text':
            case 'tinytext':
            case 'mediumtext':
            case 'longtext':
            case 'blob':
            case 'date':
            case 'time':
                return 'string';

            case 'datetime':
                return 'string|\DateTimeInterface';

            default:
                throw new TypeNotMappedException($type);
        }
    }
}
