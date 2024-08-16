<?php

namespace Exdeliver\Drift\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

class GenerateBaseLineCommand extends Command
{
    protected $signature = 'code:generate-baseline {--path=app : The path to analyze}';

    protected $description = 'Generate code baseline for drift detection';

    private array $baseline = [];

    public function handle(): void
    {
        $this->info('Generating baseline...');

        $path = base_path($this->option('path'));
        $this->analyzeDirectory($path);

        $this->saveBaseline();

        $this->info('Baseline generated successfully.');
    }

    private function analyzeDirectory($path): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->analyzeFile($file->getPathname());
            }
        }
    }

    private function analyzeFile($filePath): void
    {
        $contents = file_get_contents($filePath);
        $namespace = $this->getNamespace($contents);
        $className = $this->getClassName($contents);

        if ($namespace && $className) {
            $fullClassName = $namespace . '\\' . $className;
            $this->analyzeClass($fullClassName);
        }
    }

    private function getNamespace($contents): ?string
    {
        if (preg_match('/namespace\s+(.+?);/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getClassName($contents): ?string
    {
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function analyzeClass($fullClassName): void
    {
        if (!is_string($fullClassName) && !class_exists($fullClassName)) {
            return;
        }

        $reflection = new ReflectionClass($fullClassName);

        $this->baseline[$fullClassName] = [
            'methods' => $this->getMethodsInfo($reflection),
            'properties' => $this->getPropertiesInfo($reflection),
            'interfaces' => $reflection->getInterfaceNames(),
            'traits' => array_keys($reflection->getTraits()),
            'parent' => $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null,
        ];
    }

    private function getMethodsInfo(ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            $methods[$method->getName()] = [
                'visibility' => $this->getVisibility($method),
                'static' => $method->isStatic(),
                'parameters' => $this->getParametersInfo($method),
                'return_type' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
            ];
        }

        return $methods;
    }

    private function getParametersInfo(ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $parameters[$param->getName()] = [
                'type' => $param->hasType() ? (string) $param->getType() : null,
                'default_value' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $parameters;
    }

    private function getPropertiesInfo(ReflectionClass $reflection): array
    {
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            $properties[$property->getName()] = [
                'visibility' => $this->getVisibility($property),
                'static' => $property->isStatic(),
                'type' => $property->hasType() ? (string) $property->getType() : null,
            ];
        }

        return $properties;
    }

    private function getVisibility($reflection): string
    {
        if ($reflection->isPublic()) {
            return 'public';
        }
        if ($reflection->isProtected()) {
            return 'protected';
        }
        if ($reflection->isPrivate()) {
            return 'private';
        }
    }

    private function saveBaseline(): void
    {
        $baselinePath = storage_path('app/code_baseline.json');
        File::put($baselinePath, json_encode($this->baseline, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->info("Baseline saved to: {$baselinePath}");
    }
}
