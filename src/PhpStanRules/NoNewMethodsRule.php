<?php

namespace Exdeliver\Drift\PhpStanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\FileTypeMapper;

class NoNewMethodsRule implements Rule
{
    private $baseline;
    private $fileTypeMapper;

    public function __construct(FileTypeMapper $fileTypeMapper)
    {
        $this->fileTypeMapper = $fileTypeMapper;
        $this->loadBaseline();
    }

    private function loadBaseline(): void
    {
        $baselinePath = __DIR__ . '/../../../../../storage/app/code_baseline.json';
        if (file_exists($baselinePath)) {
            $this->baseline = json_decode(file_get_contents($baselinePath), true);
        } else {
            throw new \RuntimeException("Baseline file not found at {$baselinePath}");
        }
    }

    public function getNodeType(): string
    {
        return Node\Stmt\ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        if (!$node instanceof Node\Stmt\ClassMethod) {
            return $errors;
        }

        $className = $scope->getClassReflection()->getName();
        $methodName = $node->name->name;

        if (!$this->isLaravelClass($className)) {
            return $errors; // Only process Laravel classes
        }

        if (!isset($this->baseline[$className])) {
            $errors[] = sprintf(
                'New method "%s" detected in new class "%s". %s',
                $methodName,
                $className,
                $this->suggestForNewMethod($methodName, $className, $node, $scope),
            );

            return $errors;
        }

        $baselineMethods = $this->baseline[$className]['methods'] ?? [];

        if (!isset($baselineMethods[$methodName])) {
            $errors[] = sprintf(
                'New method "%s" detected in class "%s". %s',
                $methodName,
                $className,
                $this->suggestForNewMethod($methodName, $className, $node, $scope),
            );
        } else {
            $baselineMethod = $baselineMethods[$methodName];
            $errors = array_merge($errors, $this->checkMethodChanges($node, $baselineMethod, $className, $methodName, $scope));
        }

        return $errors;
    }

    private function isLaravelClass(string $className): bool
    {
        $laravelNamespaces = [
            'App\\',
            'Illuminate\\',
            'Laravel\\',
        ];

        foreach ($laravelNamespaces as $namespace) {
            if (strpos($className, $namespace) === 0) {
                return true;
            }
        }

        return false;
    }

    private function getVisibility(Node\Stmt\ClassMethod $node): string
    {
        if ($node->isPublic()) {
            return 'public';
        }
        if ($node->isProtected()) {
            return 'protected';
        }
        if ($node->isPrivate()) {
            return 'private';
        }

        return 'unknown';
    }

    private function getTypeName($type): string
    {
        if ($type instanceof Node\Name) {
            return $type->toString();
        }

        if ($type instanceof Node\Identifier) {
            return $type->name;
        }

        if ($type instanceof Node\NullableType) {
            return '?' . $this->getTypeName($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([$this, 'getTypeName'], $type->types));
        }

        return 'mixed';
    }

    private function getDefaultValueAsString(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return "'{$expr->value}'";
        }

        if ($expr instanceof Node\Scalar\LNumber) {
            return (string)$expr->value;
        }

        if ($expr instanceof Node\Expr\Array_) {
            return '[]'; // Simplified representation
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        return 'unknown';
    }

    private function suggestVisibility(string $oldVisibility, string $newVisibility): string
    {
        if ($oldVisibility === 'private' && $newVisibility === 'protected') {
            return 'protected if you need to allow subclass access, otherwise keep it private';
        }
        if ($oldVisibility === 'protected' && $newVisibility === 'public') {
            return 'protected if possible to maintain encapsulation';
        }
        if ($oldVisibility === 'public' && ($newVisibility === 'protected' || $newVisibility === 'private')) {
            return 'public if this method is part of the class\'s public API';
        }

        return $oldVisibility;
    }

    private function suggestReturnType(?string $oldType, ?string $newType): string
    {
        if ($oldType === null && $newType !== null) {
            return 'Adding a return type is good for type safety, but ensure it doesn\'t break existing code.';
        }
        if ($oldType !== null && $newType === null) {
            return 'Consider keeping the return type for better type safety.';
        }
        if ($oldType === 'void' && $newType !== 'void') {
            return 'If the method now returns a value, ensure all calling code is updated.';
        }
        if ($oldType !== 'void' && $newType === 'void') {
            return 'If the method no longer returns a value, ensure all calling code is updated.';
        }

        return 'Ensure this change is compatible with all calling code.';
    }

    private function suggestParameterType(?string $oldType, ?string $newType): string
    {
        if ($oldType === null && $newType !== null) {
            return 'Adding a type is good for type safety, but ensure it doesn\'t break existing code.';
        }
        if ($oldType !== null && $newType === null) {
            return 'Consider keeping the type for better type safety.';
        }
        if ($oldType !== $newType) {
            return 'Ensure this change is compatible with all calling code. Consider using union types if multiple types are valid.';
        }

        return '';
    }

    private function suggestForNewMethod(string $methodName, string $className, Node\Stmt\ClassMethod $node, Scope $scope): string
    {
        $suggestions = [];

        $classType = $this->determineClassType($className);
        $suggestions = array_merge($suggestions, $this->getClassSpecificSuggestions($classType, $methodName, $node));

        if ($node->isPrivate()) {
            $suggestions[] = "This is a private method. Ensure it's only used within this class and consider if it could be extracted to a separate class.";
        }

        if ($node->isStatic()) {
            $suggestions[] = "This is a static method. In Laravel, consider if this functionality could be provided by a service class or facade instead.";
        }

        $returnType = $node->getReturnType();
        if ($returnType instanceof Node\Name && $returnType->toString() === 'void') {
            $suggestions[] = "This method returns void. In Laravel, consider if it should return a response, a model, or affect the application state in a clear way.";
        }

        return implode(" ", $suggestions);
    }

    private function determineClassType(string $className): string
    {
        if (strpos($className, 'App\\Http\\Controllers') === 0) {
            return 'controller';
        }
        if (strpos($className, 'App\\Models') === 0) {
            return 'model';
        }
        if (strpos($className, 'App\\Providers') === 0) {
            return 'provider';
        }
        if (strpos($className, 'App\\Jobs') === 0) {
            return 'job';
        }
        if (strpos($className, 'App\\Events') === 0) {
            return 'event';
        }
        if (strpos($className, 'App\\Listeners') === 0) {
            return 'listener';
        }
        if (strpos($className, 'App\\Policies') === 0) {
            return 'policy';
        }
        if (strpos($className, 'App\\Rules') === 0) {
            return 'rule';
        }
        if (strpos($className, 'App\\Services') === 0) {
            return 'service';
        }
        if (strpos($className, 'App\\Repositories') === 0) {
            return 'repository';
        }
        if (strpos($className, 'App\\Actions') === 0) {
            return 'action';
        }

        return 'unknown';
    }

    private function getClassSpecificSuggestions(string $classType, string $methodName, Node\Stmt\ClassMethod $node): array
    {
        switch ($classType) {
            case 'controller':
                return $this->getControllerSuggestions($methodName, $node);
            case 'model':
                return $this->getModelSuggestions($methodName, $node);
            case 'provider':
                return $this->getProviderSuggestions($methodName, $node);
            case 'job':
                return $this->getJobSuggestions($methodName, $node);
            case 'event':
                return $this->getEventSuggestions($methodName, $node);
            case 'listener':
                return $this->getListenerSuggestions($methodName, $node);
            case 'policy':
                return $this->getPolicySuggestions($methodName, $node);
            case 'rule':
                return $this->getRuleSuggestions($methodName, $node);
            case 'service':
                return $this->getServiceSuggestions($methodName, $node);
            case 'repository':
                return $this->getRepositorySuggestions($methodName, $node);
            case 'action':
                return $this->getActionSuggestions($methodName, $node);
            default:
                return [];
        }
    }

    private function getControllerSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];
        $resourceMethods = ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'];

        if (in_array($methodName, $resourceMethods)) {
            $suggestions[] = "This is a standard resource controller method. Ensure it follows RESTful conventions and returns appropriate responses.";
        } else {
            $suggestions[] = "This is a custom controller method. Consider if it fits into RESTful conventions or if it should be moved to a service class.";
        }

        if (count($node->params) > 3) {
            $suggestions[] = "This method has many parameters. Consider using form requests for validation and data handling.";
        }

        return $suggestions;
    }

    private function getModelSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if (strpos($methodName, 'scope') === 0) {
            $suggestions[] = "This looks like a query scope. Ensure it's chainable and returns a query builder instance.";
        }

        if ($methodName === 'boot') {
            $suggestions[] = "This is the model's boot method. Use it for registering model events, but be cautious of putting too much logic here.";
        }

        return $suggestions;
    }

    private function getProviderSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === 'register') {
            $suggestions[] = "This is the register method. Use it for binding things into the service container.";
        }

        if ($methodName === 'boot') {
            $suggestions[] = "This is the boot method. Use it for registering view composers, event listeners, etc. after all other services have been registered.";
        }

        return $suggestions;
    }

    private function getJobSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === 'handle') {
            $suggestions[] = "This is the main method for job execution. Ensure it's focused on a single responsibility and consider breaking it down if it's too complex.";
        }

        return $suggestions;
    }

    private function getEventSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === '__construct') {
            $suggestions[] = "This is the event constructor. Ensure you're only passing in the necessary data for the event.";
        }

        return $suggestions;
    }

    private function getListenerSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === 'handle') {
            $suggestions[] = "This is the main method for event handling. Ensure it's focused and consider queuing the listener if it performs heavy operations.";
        }

        return $suggestions;
    }

    private function getPolicySuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        $commonPolicyMethods = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'];

        if (in_array($methodName, $commonPolicyMethods)) {
            $suggestions[] = "This is a standard policy method. Ensure it returns a boolean and properly checks user permissions.";
        }

        return $suggestions;
    }

    private function getRuleSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === 'passes') {
            $suggestions[] = "This is the main validation method. Ensure it returns a boolean and properly validates the input.";
        }

        if ($methodName === 'message') {
            $suggestions[] = "This method should return a string with the error message when validation fails.";
        }

        return $suggestions;
    }

    private function getServiceSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        $suggestions[] = "This is a service method. Ensure it encapsulates a specific piece of business logic and consider its responsibility within the service.";

        return $suggestions;
    }

    private function getRepositorySuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        $commonRepositoryMethods = ['all', 'find', 'create', 'update', 'delete'];

        if (in_array($methodName, $commonRepositoryMethods)) {
            $suggestions[] = "This is a common repository method. Ensure it interacts with the database or storage mechanism in a consistent way.";
        } else {
            $suggestions[] = "This is a custom repository method. Ensure it follows the single responsibility principle and fits with the repository pattern.";
        }

        return $suggestions;
    }

    private function getActionSuggestions(string $methodName, Node\Stmt\ClassMethod $node): array
    {
        $suggestions = [];

        if ($methodName === '__construct') {
            $suggestions[] = "This is the constructor for an Action class. Consider injecting any dependencies needed for this action.";
            $suggestions[] = "The 'handle' method in an Action class should be static. Consider adding the 'static' keyword.";
        } elseif ($methodName === 'handle') {
            if (!$node->isStatic()) {
                $suggestions[] = "The 'handle' method in an Action class should be static. Consider adding the 'static' keyword.";
            }
            $suggestions[] = "This is the main method of the Action class. Ensure it performs a single, well-defined operation.";
            $suggestions[] = "This method should be called statically, e.g., FoobarAction::handle(...)";
            $suggestions[] = "Consider using type-hinted parameters and return types for better clarity and type safety.";

            if (count($node->params) > 3) {
                $suggestions[] = "This action method has many parameters. Consider using a DTO (Data Transfer Object) to encapsulate the input data.";
            }

            if (!$node->returnType) {
                $suggestions[] = "Consider adding a return type to this method for better type safety and self-documentation.";
            }
        } else {
            $suggestions[] = "This is an additional method in an Action class. Ensure it directly supports the main 'handle' method and consider if it should be private or protected.";
            if ($node->isPublic()) {
                $suggestions[] = "Public methods other than 'handle' in Action classes are unusual. Consider making this method private or protected if it's only used internally.";
            }
        }

        if ($node->isStatic() && $methodName !== 'handle') {
            $suggestions[] = "Static methods other than 'handle' in Action classes are unusual. Consider making this a non-static method or moving it to a separate utility class if it's truly stateless.";
        }

        return $suggestions;
    }
}
