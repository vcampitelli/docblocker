<?php

declare(strict_types=1);

namespace DocBlocker;

use Composer\Autoload\ClassLoader;
use DocBlocker\Factory\ModelFactoryInterface;
use DocBlocker\TypeMapper\CannotProcessTableException;
use DocBlocker\TypeMapper\MySqlTypeMapper;
use DocBlocker\TypeMapper\TypeMapperInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function error_reporting;
use function fclose;
use function feof;
use function fgets;
use function fopen;
use function fwrite;
use function pathinfo;
use function rename;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const E_ALL;
use const E_DEPRECATED;
use const E_NOTICE;
use const PATHINFO_FILENAME;

readonly class Command
{
    public function __construct(
        private InputInterface $input,
        private OutputInterface $output,
        private ModelFactoryInterface $modelFactory,
    ) {
    }

    public function __invoke(): int
    {
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

        /** @var string $path */
        $path = $this->input->getArgument('path');
        $autoloadPath = "{$path}/vendor/autoload.php";
        /** @var ClassLoader $loader */
        $loader = require $autoloadPath;

        /** @var string[] $namespace */
        $namespace = $this->input->getOption('namespace');
        /** @var string[] $denyList */
        $denyList = $this->input->getOption('namespace-ignore');

        $validator = new ClassValidator(
            $this->output,
            $namespace,
            $denyList
        );

        $discovery = new Discovery($this->output, $validator, $this->modelFactory);
        $mapping = $discovery($loader);
        $typeMapper = new MySqlTypeMapper($this->input, $this->output);

        $modifiedClasses = 0;

        /**
         * @var string $table
         * @var string $file
         */
        foreach ($mapping as $table => $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $this->output->writeln(
                "<question> </question> <fg=cyan>{$className}</> (<options=underscore>{$table}</>)",
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERBOSE
            );

            if ($this->inject($file, $className, $table, $typeMapper)) {
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
     * @param string $table
     * @param TypeMapperInterface $typeMapper
     * @return bool
     */
    protected function inject(
        string $file,
        string $className,
        string $table,
        TypeMapperInterface $typeMapper
    ): bool {
        $sourceHandler = fopen($file, 'r');
        if ($sourceHandler === false) {
            throw new RuntimeException('Erro ao ler arquivo ' . $file);
        }

        $tempFile = tempnam(sys_get_temp_dir(), $className);
        if ($tempFile === false) {
            throw new RuntimeException('Erro ao gerar nome do arquivo temporário de escrita');
        }
        $tempHandler = fopen($tempFile, 'w');
        if ($tempHandler === false) {
            throw new RuntimeException('Erro ao criar arquivo temporário de escrita');
        }

        $found = false;
        while (!feof($sourceHandler)) {
            $line = fgets($sourceHandler);
            if ($line === false) {
                break;
            }
            if (str_starts_with(trim($line), "class {$className} ")) {
                $found = true;
                $this->output->writeln(
                    '  <options=bold>' . sprintf('%-30s', 'Coluna') . "</>" .
                    '<options=bold>' . sprintf('%-20s', 'Tipo no Banco') . "</>" .
                    '<options=bold>' . sprintf('%-30s', 'Tipo no PHP') . "</>",
                    OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERY_VERBOSE
                );
                fwrite($tempHandler, "/**" . PHP_EOL);
                try {
                    $this->writeProperties($typeMapper, $tempHandler, $table);
                } catch (CannotProcessTableException $e) {
                    $this->output->writeln("{$table}: {$e->getMessage()}");
                    continue;
                }
                fwrite($tempHandler, " */" . PHP_EOL);
            }
            fwrite($tempHandler, $line);
        }
        fclose($sourceHandler);
        fclose($tempHandler);

        if (!$found) {
            $this->output->writeln("<error>Erro ao processar definição da classe {$className}</>");
            unlink($tempFile);
            return false;
        }

        rename($tempFile, $file);
        \chown($file, 1000);
        \chgrp($file, 1000);

        return true;
    }

    /**
     * @param TypeMapperInterface $typeMapper
     * @param resource $tempHandler
     * @param string $table
     * @return void
     */
    private function writeProperties(
        TypeMapperInterface $typeMapper,
        mixed $tempHandler,
        string $table
    ): void {
        foreach ($typeMapper->run($table) as $field => [$rawType, $type]) {
            $this->output->writeln(
                '  <options=bold>' . sprintf('%-30s', $field) . "</>" .
                sprintf('%-20s', $rawType) .
                sprintf('%-30s', $type),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERY_VERBOSE
            );
            fwrite($tempHandler, " * @property {$type} \${$field}" . PHP_EOL);
        }
    }
}
