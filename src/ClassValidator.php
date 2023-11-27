<?php

declare(strict_types=1);

namespace DocBlocker;

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Output\OutputInterface;

use function array_flip;
use function array_map;
use function str_starts_with;
use function trim;

readonly class ClassValidator
{
    /**
     * @var string[]
     */
    private array $namespaces;

    private array $denyList;

    public function __construct(private OutputInterface $output, array $namespaces)
    {
        $this->namespaces = array_map(fn($namespace) => trim($namespace, '\\') . '\\', $namespaces);
        $this->denyList = array_flip([
            'App\Models\Base',
        ]);
    }

    public function validateAndReturnReflection(string $class): ReflectionClass|false
    {
        // Checando o namespace
        if (!$this->hasNamespace($class)) {
            return false;
        }

        if (isset($this->denyList[$class])) {
            $this->error("{$class} ignorada");
            return false;
        }

        if (!\is_a($class, \Illuminate\Database\Eloquent\Model::class, true)) {
            $this->error("{$class} não é uma Model");
            return false;
        }

        return $this->reflection($class) ?: false;
    }

    protected function hasNamespace(string $class): bool
    {
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        $this->error("{$class} não possui nenhum namespace (" . \implode(', ', $this->namespaces) . ')');
        return false;
    }

    protected function reflection(string $class): ?ReflectionClass
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            $this->error("{$class} com erro ao criar Reflection: {$e->getMessage()}");
            return null;
        }

        if ($reflection->isAbstract()) {
            $this->error("{$class} é uma classe abstrata");
            return null;
        }

        if ($reflection->isTrait()) {
            $this->error("{$class} é uma trait");
            return null;
        }

        $docComment = $reflection->getDocComment();
        // @TODO merge with doc comment
        if (!empty($docComment)) {
            $this->error("{$class} já possui um DocBlock");
            return null;
        }

        return $reflection;
    }

    protected function error(string $message): void
    {
        $this->output->writeln(
            "<comment>{$message}</>",
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_VERY_VERBOSE
        );
    }
}
