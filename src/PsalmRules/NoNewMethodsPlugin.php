<?php

namespace Exdeliver\Drift\PsalmRules;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

class NoNewMethodsPlugin implements PluginEntryPointInterface, AfterClassLikeAnalysisInterface
{
    private static $baseline = [];

    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $registration->registerHooksFromClass(self::class);
        self::loadBaseline($config);
    }

    private static function loadBaseline(?SimpleXMLElement $config): void
    {
        $baselinePath = $config && isset($config->baselinePath)
            ? (string)$config->baselinePath
            : __DIR__ . '/../../../../../storage/app/code_baseline.json';

        if (file_exists($baselinePath)) {
            self::$baseline = json_decode(file_get_contents($baselinePath), true);
        } else {
            echo "Warning: Baseline file not found at {$baselinePath}\n";
        }
    }

    public static function afterClassLikeAnalysis(AfterClassLikeAnalysisEvent $event): void
    {
        $storage = $event->getClasslikeStorage();
        $className = $storage->name;

        if (!isset(self::$baseline[$className])) {
            // Class is not in the baseline, possibly a new class
            return;
        }

        $baselineMethods = self::$baseline[$className]['methods'] ?? [];
        $currentMethods = $storage->methods;

        foreach ($currentMethods as $methodName => $methodStorage) {
            if (!isset($baselineMethods[$methodName])) {
                // New method detected
                self::reportNewMethod($event, $className, $methodName);
            } else {
                // Method exists in baseline, check for changes
                self::checkMethodChanges($event, $className, $methodName, $baselineMethods[$methodName], $methodStorage);
            }
        }
    }

    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        // This method is called after each statement in a class-like structure
        // We can use it for more granular analysis if needed
        // For now, we'll return null to indicate we haven't changed anything
        return null;
    }

    private static function reportNewMethod(AfterClassLikeAnalysisEvent $event, string $className, string $methodName): void
    {
        $message = "New method '{$methodName}' detected in class '{$className}'";
        $codeLocation = self::findMethodCodeLocation($event, $methodName);

        if ($codeLocation) {
            IssueBuffer::accepts(
                new NewMethodIssue($message, $codeLocation),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function checkMethodChanges(
        AfterClassLikeAnalysisEvent $event,
        string $className,
        string $methodName,
        array $baselineMethod,
        \Psalm\Storage\MethodStorage $currentMethod,
    ): void {
        // Check for changes in method signature
        if ($baselineMethod['visibility'] !== self::getVisibility($currentMethod)) {
            self::reportMethodChange($event, $className, $methodName, 'visibility');
        }

        if ($baselineMethod['static'] !== $currentMethod->is_static) {
            self::reportMethodChange($event, $className, $methodName, 'static modifier');
        }

        if ($baselineMethod['return_type'] !== (string)$currentMethod->return_type) {
            self::reportMethodChange($event, $className, $methodName, 'return type');
        }

        // Check for changes in parameters
        $baselineParams = $baselineMethod['parameters'];
        $currentParams = $currentMethod->params;

        if (count($baselineParams) !== count($currentParams)) {
            self::reportMethodChange($event, $className, $methodName, 'number of parameters');
        } else {
            foreach ($currentParams as $index => $param) {
                $baselineParam = $baselineParams[$index] ?? null;
                if (!$baselineParam) {
                    continue;
                }

                if ($baselineParam['type'] !== (string)$param->type) {
                    self::reportMethodChange($event, $className, $methodName, "type of parameter '{$param->name}'");
                }

                if ($baselineParam['default_value'] !== $param->default_type) {
                    self::reportMethodChange($event, $className, $methodName, "default value of parameter '{$param->name}'");
                }
            }
        }
    }

    private static function reportMethodChange(AfterClassLikeAnalysisEvent $event, string $className, string $methodName, string $changeType): void
    {
        $message = "Method '{$methodName}' in class '{$className}' has changed its {$changeType}";
        $codeLocation = self::findMethodCodeLocation($event, $methodName);

        if ($codeLocation) {
            IssueBuffer::accepts(
                new MethodChangeIssue($message, $codeLocation),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function findMethodCodeLocation(AfterClassLikeAnalysisEvent $event, string $methodName): ?CodeLocation
    {
        $stmts = $event->getStatementsSource()->getAST();
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod && $stmt->name->name === $methodName) {
                return new CodeLocation($event->getStatementsSource(), $stmt);
            }
        }

        return null;
    }

    private static function getVisibility(\Psalm\Storage\MethodStorage $method): string
    {
        if ($method->visibility === \ReflectionMethod::IS_PUBLIC) {
            return 'public';
        }
        if ($method->visibility === \ReflectionMethod::IS_PROTECTED) {
            return 'protected';
        }
        if ($method->visibility === \ReflectionMethod::IS_PRIVATE) {
            return 'private';
        }

        return 'unknown';
    }
}

class NewMethodIssue extends PluginIssue
{
}

class MethodChangeIssue extends PluginIssue
{
}
