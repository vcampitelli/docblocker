<?php

declare(strict_types=1);

namespace DocBlocker;

use Composer\Autoload\ClassLoader;
use DocBlocker\Factory\ModelFactory;
use DocBlocker\TypeMapper\MySqlTypeMapper;
use DocBlocker\TypeMapper\TypeMapperInterface;
use DocBlocker\TypeMapper\TypeNotMappedException;
use PDO;
use PDOStatement;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class Command
{
    public function __construct(private InputInterface $input, private OutputInterface $output)
    {
    }

    public function __invoke(): int
    {
        \error_reporting(\E_ALL & ~\E_NOTICE & ~\E_DEPRECATED);

        $autoloadPath = $this->input->getArgument('autoload-path');
        /** @var ClassLoader $loader */
        $loader = require $autoloadPath;

        $namespaces = $this->input->getOption('namespace');
        $validator = new ClassValidator($this->output, $namespaces);

        $discovery = new Discovery($this->output, $validator, new ModelFactory());
        $mapping = $discovery($loader);
        $typeMapper = new MySqlTypeMapper($this->output);

        $dbh = $this->buildPdo();

        $modifiedClasses = 0;

        /**
         * @var string $table
         * @var string $class
         */
        foreach ($mapping as $table => $file) {
            $className = \pathinfo($file, \PATHINFO_FILENAME);
            $this->output->writeln(
                "<question> </question> <fg=cyan>{$className}</> (<options=underscore>{$table}</>)",
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERBOSE
            );
            $table = \preg_replace('/[^a-zA-Z_]*/', '', $table);
            $statement = $dbh->query("DESCRIBE `{$table}`");
            if ((!$statement) || (!$statement->execute())) {
                // @TODO log
                continue;
            }

            if ($this->inject($file, $className, $statement, $typeMapper)) {
                $modifiedClasses++;
            }
        }
        $this->output->writeln(
            '<info>' . (($modifiedClasses === 0)
                ? 'Nenhuma classe foi alterada'
                : ("{$modifiedClasses} " .
                    (($modifiedClasses === 1) ? 'classe foi modificada' : 'classes foram modificadas') .
                    ' com sucesso')) .
            '</>'
        );
        return 0;
    }

    /**
     * @param string $file
     * @param string $className
     * @param PDOStatement $statement
     * @param TypeMapperInterface $typeMapper
     * @return bool
     */
    protected function inject(
        string $file,
        string $className,
        PDOStatement $statement,
        TypeMapperInterface $typeMapper
    ): bool {
        $found = false;
        $sourceHandler = \fopen($file, 'r');
        $tempFile = \tempnam(\sys_get_temp_dir(), $className);
        $tempHandler = \fopen($tempFile, 'w');
        while (!\feof($sourceHandler)) {
            $line = \fgets($sourceHandler);
            if ($line === false) {
                break;
            }
            if (\str_starts_with(\trim($line), "class {$className} ")) {
                $found = true;
                $this->output->writeln(
                    '  <options=bold>' . \sprintf('%-30s', 'Coluna') . "</>" .
                    '<options=bold>' . \sprintf('%-20s', 'Tipo no Banco') . "</>" .
                    '<options=bold>' . \sprintf('%-30s', 'Tipo no PHP') . "</>",
                    OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERY_VERBOSE
                );
                \fwrite($tempHandler, "/**" . PHP_EOL);
                $this->writeProperties($statement, $typeMapper, $tempHandler);
                \fwrite($tempHandler, " */" . PHP_EOL);
            }
            \fwrite($tempHandler, $line);
        }
        \fclose($sourceHandler);
        \fclose($tempHandler);

        if (!$found) {
            $this->output->writeln("<error>Erro: não achei a classe {$className}</>");
            return false;
        }

        \rename($tempFile, $file);
        \chown($file, 1000);
        \chgrp($file, 1000);

        return true;
    }

    /**
     * @param PDOStatement $statement
     * @param TypeMapperInterface $typeMapper
     * @param resource $tempHandler
     * @return void
     */
    private function writeProperties(
        PDOStatement $statement,
        TypeMapperInterface $typeMapper,
        mixed $tempHandler
    ): void {
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $definition) {
            try {
                $type = $typeMapper($definition['Type']);
            } catch (TypeNotMappedException $e) {
                $this->output->writeln($e->getMessage());
                $type = 'string';
            }
            $this->output->writeln(
                '  <options=bold>' . \sprintf('%-30s', $definition['Field']) . "</>" .
                \sprintf('%-20s', $definition['Type']) . "" .
                \sprintf('%-30s', $type),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERY_VERBOSE
            );
            \fwrite($tempHandler, " * @property {$type} \${$definition['Field']}" . PHP_EOL);
        }
    }


    /**
     * @return PDO
     */
    protected function buildPdo(): PDO
    {
        $search = [';', '='];
        return new PDO(
            $this->input->getOption('db') . ':' .
            'host=' . \str_replace($search, '', $this->input->getOption('db-host')) . ';' .
            'port=' . \str_replace($search, '', $this->input->getOption('db-port')) . ';' .
            'dbname=' . \str_replace($search, '', $this->input->getOption('db-dbname')),
            $this->input->getOption('db-username'),
            $this->input->getOption('db-password'),
        );
    }
}
