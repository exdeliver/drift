<?php

namespace Exdeliver\Drift\Insights\Rules;

use NunoMaduro\PhpInsights\Domain\Insights\Insight;
use ReflectionClass;
use ReflectionMethod;

final class NoNewMethodsRule extends Insight
{
    private $baseline;
    private $issues = [];

    public function hasIssue(): bool
    {
        $this->loadBaseline();
        $this->issues = $this->getIssues();

        return count($this->issues) > 0;
    }

    public function getTitle(): string
    {
        return 'New or changed methods detected';
    }

    public function process(): array
    {
        return $this->issues;
    }

    private function loadBaseline(): void
    {
        $baselinePath = storage_path('app/code_baseline.json');
        if (file_exists($baselinePath)) {
            $this->baseline = json_decode(file_get_contents($baselinePath), true);
        } else {
            throw new \RuntimeException("Baseline file not found at {$baselinePath}");
        }
    }

    private function getIssues(): array
    {
        $issues = [];

        $classes = [];
        $classes = array_merge($classes, $this->collector->getAbstractClasses());
        $classes = array_merge($classes, $this->collector->getConcreteNonFinalClasses());
        $classes = array_merge($classes, $this->collector->getConcreteFinalClasses());

        foreach ($classes as $file) {
            $class = $this->getClassNameFromFile($file);

            try {
                $class = new ReflectionClass($class);
            } catch (\ReflectionException $e) {
                throw new \RuntimeException("Unable to load class '{$class}' by file '{$file}'");
            }
            $className = $class->getName();
            $baselineMethods = $this->getBaselineMethods($className);
            $currentMethods = $this->getClassMethods($class);

            foreach ($currentMethods as $methodName => $methodInfo) {
                if (!isset($baselineMethods[$methodName])) {
                    $issues[] = "New method '{$methodName}' detected HY in class '{$className}'";
                } else {
                    $changes = $this->detectMethodChanges($baselineMethods[$methodName], $methodInfo);
                    foreach ($changes as $change) {
                        $issues[] = "Method '{$methodName}' in class '{$className}' has changed: {$change}";
                    }
                }
            }
        }

        return $issues;
    }

    private function getClassNameFromFile(string $filePath): string
    {
        $fileContents = file_get_contents($filePath);

        // Match namespace and class name
        if (preg_match('/namespace\s+([^;]+);/', $fileContents, $namespaceMatches) &&
            preg_match('/class\s+(\w+)/', $fileContents, $classMatches)) {
            $namespace = trim($namespaceMatches[1]);
            $className = $classMatches[1];

            return $namespace . '\\' . $className; // Return fully qualified class name
        }

        throw new \RuntimeException("Unable to determine class name from file: {$filePath}");
    }

    private function getClassMethods(\ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[$method->getName()] = [
                'visibility' => $this->getVisibility($method),
                'static' => $method->isStatic(),
                'return_type' => $this->getReflectionType($method),
                'parameters' => $this->getParameters($method),
            ];
        }

        return $methods;
    }

    private function getReflectionType($object): string
    {
        if ($object instanceof ReflectionMethod) {
            try {
                return $object->hasReturnType() ? $object->getReturnType()?->getName() : '';
            } catch (\Throwable $e) {
                $returnType = null;
                foreach ($object->getReturnType()->getTypes() as $type) {
                    $typeName = $type->getName();
                    $returnType .= '|' . $typeName;
                }

                return $returnType ?? '';
            }
        } elseif ($object instanceof \ReflectionParameter) {
            try {
                return $object->hasType() ? $object->getType()?->getName() : '';
            } catch (\Throwable $e) {
                $returnType = null;
                foreach ($object->getType() as $type) {
                    $typeName = $type->getName();
                    $returnType .= '|' . $typeName;
                }

                return $returnType ?? '';
            }
        }

        return '';
    }

    private function getBaselineMethods(string $className): array
    {
        return $this->baseline[$className]['methods'] ?? [];
    }

    private function getVisibility(\ReflectionMethod $method): string
    {
        if ($method->isPublic()) {
            return 'public';
        }
        if ($method->isProtected()) {
            return 'protected';
        }
        if ($method->isPrivate()) {
            return 'private';
        }

        return 'unknown';
    }

    private function getParameters(\ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $parameters[$param->getName()] = [
                'type' => $param->hasType() ? $this->getReflectionType($param) : null,
                'default_value' => $param->isDefaultValueAvailable() ? $this->getDefaultValueAsString($param) : null,
            ];
        }

        return $parameters;
    }

    private function getDefaultValueAsString(\ReflectionParameter $param): string
    {
        if (!$param->isDefaultValueAvailable()) {
            return 'none';
        }

        $value = $param->getDefaultValue();

        if (is_string($value)) {
            return "'{$value}'";
        }

        if (is_array($value)) {
            return '[]'; // Simplified representation
        }

        if ($value === null) {
            return 'null';
        }

        return (string)$value;
    }

    private function detectMethodChanges(array $baselineMethod, array $currentMethod): array
    {
        $changes = [];

        if ($baselineMethod['visibility'] !== $currentMethod['visibility']) {
            $changes[] = "visibility changed from {$baselineMethod['visibility']} to {$currentMethod['visibility']}";
        }

        if ($baselineMethod['static'] !== $currentMethod['static']) {
            $changes[] = $currentMethod['static'] ? 'became static' : 'is no longer static';
        }

        if ($baselineMethod['return_type'] !== $currentMethod['return_type']) {
            $changes[] = "return type changed from {$baselineMethod['return_type']} to {$currentMethod['return_type']}";
        }

        $paramChanges = $this->detectParameterChanges($baselineMethod['parameters'], $currentMethod['parameters']);
        $changes = array_merge($changes, $paramChanges);

        return $changes;
    }

    private function detectParameterChanges(array $baselineParams, array $currentParams): array
    {
        $changes = [];

        if (count($baselineParams) !== count($currentParams)) {
            $changes[] = 'number of parameters changed';
        }

        foreach ($currentParams as $name => $info) {
            if (!isset($baselineParams[$name])) {
                $changes[] = "new parameter '{$name}' added";
            } elseif ($baselineParams[$name]['type'] !== $info['type']) {
                $changes[] = "type of parameter '{$name}' changed from {$baselineParams[$name]['type']} to {$info['type']}";
            } elseif ($baselineParams[$name]['default_value'] !== $info['default_value']) {
                $changes[] = "default value of parameter '{$name}' changed";
            }
        }

        foreach ($baselineParams as $name => $info) {
            if (!isset($currentParams[$name])) {
                $changes[] = "parameter '{$name}' removed";
            }
        }

        return $changes;
    }
}