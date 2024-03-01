<?php

declare(strict_types=1);

namespace DocBlocker\TypeMapper;

use PDO;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

/**
 * @phpstan-type ForeignKeysArray array<string, array<string, array{'table': string, 'column': string}>>
 * @phpstan-type DefinitionArray array{'Field': string, 'Type': string}
 */
readonly class MySqlTypeMapper implements TypeMapperInterface
{
    private PDO $dbh;

    /**
     * @var ForeignKeysArray
     */
    private array $foreignKeys;

    public function __construct(InputInterface $input, private OutputInterface $output)
    {
        $search = [';', '='];

        /** @var string $dbName */
        $dbName = $input->getOption('db-dbname');
        /** @var string $dbHost */
        $dbHost = $input->getOption('db-host');
        /** @var string $dbPort */
        $dbPort = $input->getOption('db-port');
        /** @var string $dbUsername */
        $dbUsername = $input->getOption('db-username');
        /** @var string $dbPassword */
        $dbPassword = $input->getOption('db-password');

        $this->dbh = new PDO(
            $input->getOption('db') . ':' .
            'host=' . str_replace($search, '', $dbHost) . ';' .
            'port=' . str_replace($search, '', $dbPort) . ';' .
            'dbname=' . str_replace($search, '', $dbName),
            $dbUsername,
            $dbPassword,
        );

        $this->foreignKeys = $this->setupForeignKeys($dbName);
    }

    /**
     * @param string $dbName
     * @return ForeignKeysArray
     */
    protected function setupForeignKeys(string $dbName): array
    {
        $statement = $this->dbh->prepare(
            <<<SQL
            SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND
                  REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME
        SQL
        );
        if (!$statement->execute([$dbName])) {
            throw new RuntimeException("Erro ao buscar chaves estrangerias da base {$dbName}");
        }

        $foreignKeys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            /**
             * @var array{
             *     'TABLE_NAME': string,
             *     'COLUMN_NAME': string,
             *     'REFERENCED_TABLE_NAME': string,
             *     'REFERENCED_COLUMN_NAME': string
             * } $row
             */
            if (!isset($foreignKeys[$row['TABLE_NAME']])) {
                $foreignKeys[$row['TABLE_NAME']] = [];
            }
            $foreignKeys[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [
                'table' => $row['REFERENCED_TABLE_NAME'],
                'column' => $row['REFERENCED_COLUMN_NAME'],
            ];
        }
        return $foreignKeys;
    }

    public function run(string $originalTable): \Generator
    {
        $table = \preg_replace('/[^a-zA-Z_]*/', '', $originalTable);
        if ($table === null) {
            throw new CannotProcessTableException($originalTable);
        }

        $statement = $this->dbh->query("DESCRIBE `{$table}`");
        if ((!$statement) || (!$statement->execute())) {
            throw new CannotProcessTableException($originalTable);
        }

        while ($definition = $statement->fetch(PDO::FETCH_ASSOC)) {
            /** @var DefinitionArray $definition */
            try {
                $type = $this->map($table, $definition);
            } catch (TypeNotMappedException $e) {
                $this->output->writeln($e->getMessage());
                $type = 'string';
            }
            yield $definition['Field'] => [$definition['Type'], $type];
        }
    }

    /**
     * @param string $table
     * @param DefinitionArray $definition
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function map(string $table, array $definition): string
    {
        if (isset($this->foreignKeys[$table][$definition['Field']])) {
            return 'App\\Models\\' . $this->foreignKeys[$table][$definition['Field']]['table']; // @FIXME namespace
        }

        if ($definition['Type'] === 'tinyint(1)') {
            return 'boolean';
        }

        [$type] = \explode('(', $definition['Type']);
        return match ($type) {
            'int', 'smallint', 'tinyint', 'mediumint', 'bigint', 'timestamp' => 'int',
            'float', 'double', 'decimal', 'real', 'fixed' => 'float',
            'varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'date', 'time' => 'string',
            'datetime' => 'string|\DateTimeInterface',
            default => throw new TypeNotMappedException($type),
        };
    }
}
