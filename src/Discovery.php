<?php

declare(strict_types=1);

namespace DocBlocker;

use Composer\Autoload\ClassLoader;
use DocBlocker\Factory\ModelFactory;
use Symfony\Component\Console\Output\OutputInterface;

readonly class Discovery
{
    public function __construct(
        private OutputInterface $output,
        private ClassValidator $validator,
        private ModelFactory $factory
    ) {
    }

    /**
     * @param ClassLoader $loader
     * @return \Generator
     */
    public function __invoke(ClassLoader $loader): \Generator
    {
        $mapping = [];
        $factory = $this->factory;

        foreach ($loader->getClassMap() as $class => $file) {
            $reflection = $this->validator->validateAndReturnReflection($class);
            if (!$reflection) {
                continue;
            }

            // Tentando buscar o valor padrão da propriedade $table
            $table = $reflection->getDefaultProperties()['table'] ?? null;
            if (empty($table)) {
                // Senão, temos que criar a model para buscar a tabela (ex: model do usuário)
                $model = $factory($class);
                $table = $model->getTable();
            }
            if (empty($table)) {
                $this->output->writeln("<error>{$class} não possui uma tabela definida</>");
                continue;
            }

            if (isset($mapping[$table])) {
                $this->output->writeln(
                    "<error>Erro: {$class} possui a tabela {$table}, mas ela já está sendo usada por " .
                    "{$mapping[$table]}</>"
                );
                continue;
            }

            yield $table => $file;
            $mapping[$table] = $class;
        }
    }
}