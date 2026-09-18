<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Ast;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\Reference;
use Peralta\AgentKit\Refactoring\Analysis\DTOs\SymbolDefinition;
use Peralta\AgentKit\Refactoring\Analysis\Graph\Confidence;
use Peralta\AgentKit\Refactoring\Analysis\Graph\DependencyType;

final class StructureCollector extends NodeVisitorAbstract
{
    private array $symbols = [];
    private array $references = [];
    private ?string $namespace = null;
    private array $imports = [];
    private array $classStack = [];
    private ?string $currentClass = null;
    private ?string $currentParent = null;
    private ?string $currentMethod = null;
    private ?array $symbol = null;
    private array $propertyTypes = [];
    private array $localTypes = [];
    private array $localScopeStack = [];
    private array $conditionalScopes = [];
    private array $taintedLocals = [];
    private bool $allLocalsTainted = false;
    private readonly NameContext $nameContext;

    public function __construct(
        private readonly string $file,
        private readonly array $facadePrefixes = [],
    ) {
        $this->nameContext = new NameContext();
    }

    public function symbols(): array
    {
        return $this->symbols;
    }

    public function references(): array
    {
        return $this->references;
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function imports(): array
    {
        return $this->imports;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();

            return null;
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->addImport($use->name->toString(), $use->getAlias()->toString(), $use->type ?: $node->type, $use);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $name = $node->prefix->toString() . '\\' . $use->name->toString();
                $this->addImport($name, $use->getAlias()->toString(), $use->type ?: $node->type, $use);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClass($node);

            return null;
        }

        if ($this->currentClass === null) {
            return null;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->enterLocalFunction($node);
        } elseif ($this->isUncertainControlFlow($node)) {
            $this->enterUncertainScope($node);
            if ($node instanceof Node\Stmt\Foreach_ && $node->byRef) {
                $this->taintWrittenTarget($node->valueVar);
            }
            if ($node instanceof Node\Expr\NullsafeMethodCall) {
                $this->collectMethodCall($node);
            }
        } elseif ($node instanceof Node\Stmt\ElseIf_ || $node instanceof Node\Stmt\Else_) {
            $this->localTypes = $this->conditionalScopes[array_key_last($this->conditionalScopes)]['types'];
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Node\Stmt\Property) {
            $this->collectProperty($node);
        } elseif ($node instanceof Node\Stmt\ClassConst) {
            $this->collectConstants($node);
        } elseif ($node instanceof Node\Stmt\Global_) {
            foreach ($node->vars as $variable) {
                $this->taintWrittenTarget($variable);
            }
        } elseif ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addReference($this->resolvedName($trait), null, DependencyType::TRAIT, Confidence::EXACT, $node);
            }
        } elseif ($node instanceof Node\Expr\New_) {
            $target = $node->class instanceof Node\Name ? $this->resolvedName($node->class) : null;
            if ($target !== null || $node->class instanceof Node\Expr) {
                $this->addReference(
                    $target,
                    null,
                    DependencyType::INSTANTIATION,
                    $target === null ? Confidence::UNKNOWN : Confidence::EXACT,
                    $node,
                );
            }
        } elseif ($node instanceof Node\Expr\Assign) {
            $this->collectAssignment($node);
        } elseif ($node instanceof Node\Expr\AssignRef) {
            $this->taintWrittenTarget($node->var);
            $this->taintWrittenTarget($node->expr);
        } elseif ($node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PreDec
            || $node instanceof Node\Expr\PostDec) {
            $this->invalidateWrittenTarget($node->var);
        } elseif ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $variable) {
                $this->invalidateWrittenTarget($variable);
            }
        } elseif ($node instanceof Node\Expr\MethodCall) {
            $this->collectMethodCall($node);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->collectStaticCall($node);
        } elseif ($node instanceof Node\Expr\ClassConstFetch) {
            $this->collectClassConstant($node);
        } elseif ($node instanceof Node\Expr\FuncCall) {
            $this->collectFunctionCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            if ($this->currentClass !== null && $this->symbol !== null) {
                $this->symbols[] = new SymbolDefinition(
                    $this->currentClass,
                    $this->symbol['kind'],
                    $this->file,
                    $this->symbol['line'],
                    $this->symbol['methods'],
                    $this->symbol['properties'],
                    $this->symbol['constants'],
                    array_values(array_unique($this->symbol['attributes'])),
                );
            }

            [$this->currentClass, $this->currentParent, $this->currentMethod, $this->symbol, $this->propertyTypes, $this->localTypes, $this->localScopeStack, $this->conditionalScopes, $this->taintedLocals, $this->allLocalsTainted]
                = array_pop($this->classStack);
            $this->nameContext->set($this->currentClass, $this->currentParent);

            return null;
        }

        // enterNode() skips every node outside a named class (procedural files, top-level
        // functions, anonymous class bodies), so nothing was pushed for them and popping
        // here would underflow the scope stacks. Mirror that guard exactly.
        if ($this->currentClass === null) {
            return null;
        }

        if ($this->isUncertainControlFlow($node)) {
            $this->leaveUncertainScope();
        }

        if ($node instanceof Node\Expr\CallLike) {
            $this->taintCallArguments($node);
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            [$this->localTypes, $this->conditionalScopes, $this->taintedLocals, $this->allLocalsTainted, $byReference]
                = array_pop($this->localScopeStack);
            $this->taintVariables($byReference);
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = null;
            $this->localTypes = [];
            $this->localScopeStack = [];
            $this->conditionalScopes = [];
            $this->taintedLocals = [];
            $this->allLocalsTainted = false;
        }

        return null;
    }

    private function enterClass(Node\Stmt\ClassLike $node): void
    {
        $this->classStack[] = [
            $this->currentClass,
            $this->currentParent,
            $this->currentMethod,
            $this->symbol,
            $this->propertyTypes,
            $this->localTypes,
            $this->localScopeStack,
            $this->conditionalScopes,
            $this->taintedLocals,
            $this->allLocalsTainted,
        ];

        $namespacedName = $node->namespacedName;
        $this->currentClass = $namespacedName instanceof Node\Name
            ? ltrim($namespacedName->toString(), '\\')
            : null;
        $this->currentMethod = null;
        $this->propertyTypes = [];
        $this->localTypes = [];
        $this->localScopeStack = [];
        $this->conditionalScopes = [];
        $this->taintedLocals = [];
        $this->allLocalsTainted = false;

        $parent = $node instanceof Node\Stmt\Class_ ? $node->extends : null;
        $this->currentParent = $parent instanceof Node\Name ? $this->resolvedName($parent) : null;
        $this->nameContext->set($this->currentClass, $this->currentParent);

        if ($this->currentClass === null) {
            $this->symbol = null;

            return;
        }

        $this->symbol = [
            'kind' => match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            },
            'line' => $node->getStartLine(),
            'methods' => [],
            'properties' => [],
            'constants' => [],
            'attributes' => [],
        ];
        $this->primePropertyTypes($node);

        if ($this->currentParent !== null) {
            $this->addReference($this->currentParent, null, DependencyType::EXTENDS, Confidence::EXACT, $node);
        }

        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addReference($this->resolvedName($interface), null, DependencyType::EXTENDS, Confidence::EXACT, $interface);
            }
        } elseif ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addReference($this->resolvedName($interface), null, DependencyType::IMPLEMENTS, Confidence::EXACT, $interface);
            }
        }

        $this->collectAttributes($node);
    }

    private function primePropertyTypes(Node\Stmt\ClassLike $node): void
    {
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Property) {
                $types = $this->classTypes($statement->type);
                if (count($types) !== 1) {
                    continue;
                }
                foreach ($statement->props as $property) {
                    $this->propertyTypes[$property->name->toString()] = $types[0];
                }
            }

            if ($statement instanceof Node\Stmt\ClassMethod
                && $statement->name->toString() === '__construct') {
                foreach ($statement->params as $param) {
                    if ($param->flags === 0
                        || !$param->var instanceof Node\Expr\Variable
                        || !is_string($param->var->name)) {
                        continue;
                    }
                    $types = $this->classTypes($param->type);
                    if (count($types) === 1) {
                        $this->propertyTypes[$param->var->name] = $types[0];
                    }
                }
            }
        }
    }

    private function enterMethod(Node\Stmt\ClassMethod $node): void
    {
        $this->currentMethod = $node->name->toString();
        $this->localTypes = [];
        $this->localScopeStack = [];
        $this->conditionalScopes = [];
        $this->taintedLocals = [];
        $this->allLocalsTainted = false;
        $parameters = [];

        foreach ($node->params as $param) {
            $types = $this->classTypes($param->type);
            $parameters[] = [
                'name' => $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    ? $param->var->name
                    : null,
                'types' => $types,
                'line' => $param->getStartLine(),
            ];
            foreach ($types as $type) {
                $dependencyType = $this->currentMethod === '__construct'
                    ? DependencyType::CONSTRUCTOR_INJECTION
                    : DependencyType::METHOD_PARAMETER;
                $this->addReference($type, null, $dependencyType, Confidence::EXACT, $param);
            }

            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name) && count($types) === 1) {
                $this->localTypes[$param->var->name] = $types[0];
            }

            if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $this->symbol['properties'][] = [
                    'name' => $param->var->name,
                    'types' => $types,
                    'line' => $param->getStartLine(),
                ];
                if (count($types) === 1) {
                    $this->propertyTypes[$param->var->name] = $types[0];
                }
                if ($types !== []) {
                    foreach ($types as $type) {
                        $this->addReference($type, null, DependencyType::PROPERTY_TYPE, Confidence::EXACT, $param);
                    }
                }
            }
        }

        $returnTypes = $this->classTypes($node->returnType);
        foreach ($returnTypes as $type) {
            $this->addReference($type, null, DependencyType::RETURN_TYPE, Confidence::EXACT, $node);
        }

        $this->symbol['methods'][] = [
            'name' => $this->currentMethod,
            'parameters' => $parameters,
            'return_types' => $returnTypes,
            'line' => $node->getStartLine(),
        ];

        $this->collectAttributes($node);
    }

    private function collectProperty(Node\Stmt\Property $node): void
    {
        $types = $this->classTypes($node->type);
        foreach ($node->props as $property) {
            $name = $property->name->toString();
            $this->symbol['properties'][] = [
                'name' => $name,
                'types' => $types,
                'line' => $property->getStartLine(),
            ];
            if (count($types) === 1) {
                $this->propertyTypes[$name] = $types[0];
            }
        }
        foreach ($types as $type) {
            $this->addReference($type, null, DependencyType::PROPERTY_TYPE, Confidence::EXACT, $node);
        }
        $this->collectAttributes($node);
    }

    private function collectConstants(Node\Stmt\ClassConst $node): void
    {
        foreach ($node->consts as $constant) {
            $this->symbol['constants'][] = [
                'name' => $constant->name->toString(),
                'line' => $constant->getStartLine(),
            ];
        }
        $this->collectAttributes($node);
    }

    private function collectAssignment(Node\Expr\Assign $node): void
    {
        $variables = $this->writtenVariables($node->var);
        if ($this->hasUnresolvableWriteTarget($node->var)) {
            $this->invalidateAllLocalTypes();
        }
        if ($variables === []) {
            return;
        }

        $this->invalidateVariables($variables);
        if (!$node->var instanceof Node\Expr\Variable || !is_string($node->var->name)) {
            return;
        }

        $target = null;
        if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
            $target = $this->resolvedName($node->expr->class);
        } else {
            $target = $this->containerClassArgument($node->expr);
        }

        if ($target !== null
            && !$this->allLocalsTainted
            && !isset($this->taintedLocals[$node->var->name])
            && !$this->isConditionallyWritten($node->var->name)) {
            $this->localTypes[$node->var->name] = $target;
        }
    }

    private function enterLocalFunction(Node\Expr\Closure|Node\Expr\ArrowFunction $node): void
    {
        $outerTypes = $this->localTypes;
        $outerTainted = $this->taintedLocals;
        $outerAllTainted = $this->allLocalsTainted;
        $byReference = [];
        if ($node instanceof Node\Expr\Closure) {
            foreach ($node->uses as $use) {
                if ($use->byRef && is_string($use->var->name)) {
                    $byReference[] = $use->var->name;
                }
            }
        }
        $this->localScopeStack[] = [$outerTypes, $this->conditionalScopes, $outerTainted, $outerAllTainted, $byReference];
        $this->conditionalScopes = [];
        $this->localTypes = $node instanceof Node\Expr\ArrowFunction ? $outerTypes : [];
        $this->taintedLocals = $node instanceof Node\Expr\ArrowFunction ? $outerTainted : [];
        $this->allLocalsTainted = false;

        if ($node instanceof Node\Expr\Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name) && isset($outerTypes[$use->var->name])) {
                    $this->localTypes[$use->var->name] = $outerTypes[$use->var->name];
                }
                if (is_string($use->var->name) && isset($outerTainted[$use->var->name])) {
                    $this->taintedLocals[$use->var->name] = true;
                    unset($this->localTypes[$use->var->name]);
                }
                if (is_string($use->var->name) && $outerAllTainted) {
                    $this->taintVariables([$use->var->name]);
                }
            }
            $this->taintVariables($byReference);
        }

        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }
            unset($this->localTypes[$param->var->name]);
            unset($this->taintedLocals[$param->var->name]);
            $types = $this->classTypes($param->type);
            if (count($types) === 1) {
                $this->localTypes[$param->var->name] = $types[0];
            }
        }
    }

    private function enterUncertainScope(Node $node): void
    {
        $this->conditionalScopes[] = [
            'types' => $this->localTypes,
            'assigned' => [],
        ];
        $hasUnresolvableWrite = false;
        $variables = $this->writtenVariablesIn($node, $hasUnresolvableWrite);
        if ($hasUnresolvableWrite) {
            $this->invalidateAllLocalTypes();
        }
        $this->invalidateVariables($variables);
    }

    private function leaveUncertainScope(): void
    {
        $scope = array_pop($this->conditionalScopes);
        $this->localTypes = $scope['types'];
        $this->invalidateVariables(array_keys($scope['assigned']));
    }

    private function invalidateWrittenTarget(Node\Expr $target): void
    {
        if ($this->hasUnresolvableWriteTarget($target)) {
            $this->invalidateAllLocalTypes();
        }
        $this->invalidateVariables($this->writtenVariables($target));
    }

    private function isConditionallyWritten(string $variable): bool
    {
        foreach ($this->conditionalScopes as $scope) {
            if (isset($scope['assigned'][$variable])) {
                return true;
            }
        }

        return false;
    }

    private function taintWrittenTarget(Node\Expr $target): void
    {
        if ($this->hasUnresolvableWriteTarget($target)) {
            $this->taintAllLocalTypes();
        }
        $this->taintVariables($this->writtenVariables($target));
    }

    private function invalidateAllLocalTypes(): void
    {
        $this->invalidateVariables(array_keys($this->localTypes));
    }

    private function taintCallArguments(Node\Expr\CallLike $call): void
    {
        if ($call instanceof Node\Expr\FuncCall
            && ($call->name instanceof Node\Expr\Closure || $call->name instanceof Node\Expr\ArrowFunction)
            && $this->taintVisibleCallableArguments($call->name->params, $call->getRawArgs())) {
            return;
        }

        $this->taintDirectArguments($call->getRawArgs());
    }

    /**
     * @param list<Node\Param> $parameters
     * @param list<Node\Arg|Node\VariadicPlaceholder> $arguments
     */
    private function taintVisibleCallableArguments(array $parameters, array $arguments): bool
    {
        $byName = [];
        $variadic = null;
        foreach ($parameters as $index => $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                return false;
            }
            $byName[$parameter->var->name] = $index;
            if ($parameter->variadic) {
                $variadic = $index;
            }
        }

        $position = 0;
        $namedArgumentSeen = false;
        $positionAmbiguous = false;
        foreach ($arguments as $argument) {
            if ($argument instanceof Node\VariadicPlaceholder) {
                continue;
            }
            if (!$argument instanceof Node\Arg) {
                return false;
            }
            if ($argument->unpack) {
                $positionAmbiguous = true;

                continue;
            }

            if ($argument->name !== null) {
                $namedArgumentSeen = true;
                $index = $byName[$argument->name->toString()] ?? $variadic;
            } else {
                if ($namedArgumentSeen || $positionAmbiguous) {
                    $this->taintDirectArgument($argument);

                    continue;
                }
                if ($position >= count($parameters) && $variadic === null) {
                    continue;
                }
                $index = $position < count($parameters) ? $position : $variadic;
                if ($index !== $variadic) {
                    $position++;
                }
            }

            if ($index === null) {
                $this->taintDirectArgument($argument);

                continue;
            }
            $parameter = $parameters[$index];
            if ($parameter->byRef) {
                $this->taintDirectArgument($argument);
            }
        }

        return true;
    }

    /** @param list<Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function taintDirectArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Node\Arg) {
                $this->taintDirectArgument($argument);
            }
        }
    }

    private function taintDirectArgument(Node\Arg $argument): void
    {
        if ($argument->unpack) {
            return;
        }
        if (!$argument->value instanceof Node\Expr\Variable) {
            return;
        }
        if (!is_string($argument->value->name)) {
            $this->taintAllLocalTypes();

            return;
        }
        $this->taintVariables([$argument->value->name]);
    }

    private function taintAllLocalTypes(): void
    {
        $this->allLocalsTainted = true;
        $this->invalidateAllLocalTypes();
    }

    /** @param list<string> $variables */
    private function taintVariables(array $variables): void
    {
        foreach ($variables as $variable) {
            $this->taintedLocals[$variable] = true;
        }
        $this->invalidateVariables($variables);
    }

    /** @param list<string> $variables */
    private function invalidateVariables(array $variables): void
    {
        foreach ($variables as $variable) {
            foreach (array_keys($this->conditionalScopes) as $index) {
                $this->conditionalScopes[$index]['assigned'][$variable] = true;
            }
            unset($this->localTypes[$variable]);
        }
    }

    /** @return list<string> */
    private function writtenVariables(Node\Expr $target): array
    {
        if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
            return [$target->name];
        }
        if ($target instanceof Node\Expr\ArrayDimFetch) {
            return $this->writtenVariables($target->var);
        }
        if ($target instanceof Node\Expr\Array_ || $target instanceof Node\Expr\List_) {
            $variables = [];
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $variables = array_merge($variables, $this->writtenVariables($item->value));
                }
            }

            return array_values(array_unique($variables));
        }

        return [];
    }

    private function hasUnresolvableWriteTarget(Node\Expr $target): bool
    {
        if ($target instanceof Node\Expr\Variable) {
            return !is_string($target->name);
        }
        if ($target instanceof Node\Expr\ArrayDimFetch) {
            return $this->hasUnresolvableWriteTarget($target->var);
        }
        if ($target instanceof Node\Expr\Array_ || $target instanceof Node\Expr\List_) {
            foreach ($target->items as $item) {
                if ($item !== null && $this->hasUnresolvableWriteTarget($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function writtenVariablesIn(Node $root, ?bool &$hasUnresolvableWrite = null): array
    {
        $hasUnresolvableWrite = false;
        $variables = [];
        $recordTarget = function (Node\Expr $target) use (&$variables, &$hasUnresolvableWrite): void {
            $variables = array_merge($variables, $this->writtenVariables($target));
            $hasUnresolvableWrite = $hasUnresolvableWrite || $this->hasUnresolvableWriteTarget($target);
        };
        $visit = function (Node $node, bool $isRoot = false) use (&$visit, $recordTarget): void {
            if (!$isRoot && ($node instanceof Node\Expr\Closure
                || $node instanceof Node\Expr\ArrowFunction
                || $node instanceof Node\Stmt\ClassLike)) {
                return;
            }

            if ($node instanceof Node\Expr\Assign
                || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PreInc
                || $node instanceof Node\Expr\PostInc
                || $node instanceof Node\Expr\PreDec
                || $node instanceof Node\Expr\PostDec) {
                $recordTarget($node->var);
            } elseif ($node instanceof Node\Expr\AssignRef) {
                $recordTarget($node->var);
                $recordTarget($node->expr);
            } elseif ($node instanceof Node\Stmt\Foreach_) {
                $recordTarget($node->valueVar);
                if ($node->keyVar !== null) {
                    $recordTarget($node->keyVar);
                }
            } elseif ($node instanceof Node\Stmt\Catch_ && $node->var !== null) {
                $recordTarget($node->var);
            } elseif ($node instanceof Node\Stmt\Global_) {
                foreach ($node->vars as $variable) {
                    $recordTarget($variable);
                }
            } elseif ($node instanceof Node\Stmt\Unset_) {
                foreach ($node->vars as $variable) {
                    $recordTarget($variable);
                }
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $visit($child);
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $visit($item);
                        }
                    }
                }
            }
        };
        $visit($root, true);

        return array_values(array_unique($variables));
    }

    private function isUncertainControlFlow(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\TryCatch
            || $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\Match_
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce
            || $node instanceof Node\Expr\NullsafeMethodCall
            || $node instanceof Node\Expr\NullsafePropertyFetch;
    }

    private function collectMethodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            $this->addReference(null, null, DependencyType::METHOD_CALL, Confidence::UNKNOWN, $node);

            return;
        }

        $method = $node->name->toString();
        if (strcasecmp($method, 'make') === 0 && $this->isAppCall($node->var)) {
            $target = $this->classNameArgument($node->args[0]->value ?? null);
            $this->addReference(
                $target,
                null,
                DependencyType::INSTANTIATION,
                $target === null ? Confidence::UNKNOWN : Confidence::INFERRED,
                $node,
                ['resolution' => 'app_make'],
            );

            return;
        }

        [$target, $confidence] = $this->receiverType($node->var);
        $this->addReference($target, $method, DependencyType::METHOD_CALL, $confidence, $node);
    }

    private function collectStaticCall(Node\Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            $this->addReference(null, null, DependencyType::STATIC_CALL, Confidence::UNKNOWN, $node);

            return;
        }

        $target = $this->resolvedName($node->class);
        $method = $node->name->toString();

        if (strcasecmp($method, 'dispatch') === 0) {
            $dispatched = $this->newClassArgument($node->args[0]->value ?? null);
            if ($dispatched !== null) {
                $kind = strcasecmp((string) $target, 'Illuminate\\Support\\Facades\\Event') === 0 ? 'event' : 'job';
                $this->addReference($dispatched, null, DependencyType::EVENT, Confidence::EXACT, $node, ['dispatch_kind' => $kind]);
            } elseif ($this->isFacade($target)) {
                $kind = strcasecmp((string) $target, 'Illuminate\\Support\\Facades\\Event') === 0 ? 'event' : 'job';
                $this->addReference(null, null, DependencyType::EVENT, Confidence::UNKNOWN, $node, ['dispatch_kind' => $kind]);
            } elseif (!$this->isFacade($target)) {
                $this->addReference($target, null, DependencyType::EVENT, Confidence::EXACT, $node, ['dispatch_kind' => 'job']);
            }
        }

        $type = $this->isFacade($target) ? DependencyType::FACADE : DependencyType::STATIC_CALL;
        $this->addReference($target, $method, $type, Confidence::EXACT, $node);
    }

    private function collectClassConstant(Node\Expr\ClassConstFetch $node): void
    {
        if (!$node->class instanceof Node\Name && !$node->class instanceof Node\Expr) {
            return;
        }

        $constant = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        $target = $node->class instanceof Node\Name ? $this->resolvedName($node->class) : null;
        $this->addReference(
            $target,
            null,
            DependencyType::CLASS_CONSTANT,
            $target === null ? Confidence::UNKNOWN : Confidence::EXACT,
            $node,
            $constant !== null
                ? ['constant' => $constant]
                : ['constant_name_confidence' => 'unknown'],
        );
    }

    private function collectFunctionCall(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name || count($node->name->getParts()) !== 1) {
            return;
        }

        $function = strtolower($node->name->getLast());
        if (in_array($function, ['event', 'dispatch'], true)) {
            $target = $this->newClassArgument($node->args[0]->value ?? null);
            $this->addReference(
                $target,
                null,
                DependencyType::EVENT,
                $target === null ? Confidence::UNKNOWN : Confidence::EXACT,
                $node,
                ['dispatch_kind' => $function === 'event' ? 'event' : 'job'],
            );

            return;
        }

        if (in_array($function, ['app', 'resolve'], true)) {
            if ($node->args === []) {
                return;
            }
            $target = $this->classNameArgument($node->args[0]->value ?? null);
            $this->addReference(
                $target,
                null,
                DependencyType::INSTANTIATION,
                $target === null ? Confidence::UNKNOWN : Confidence::INFERRED,
                $node,
                ['resolution' => $function],
            );
        }
    }

    private function receiverType(Node\Expr $receiver): array
    {
        if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'this') {
            return [$this->currentClass, Confidence::EXACT];
        }

        if ($receiver instanceof Node\Expr\PropertyFetch
            && $receiver->var instanceof Node\Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier) {
            return [$this->propertyTypes[$receiver->name->toString()] ?? null, Confidence::INFERRED];
        }

        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            return [$this->localTypes[$receiver->name] ?? null, Confidence::INFERRED];
        }

        $containerType = $this->containerClassArgument($receiver);
        if ($containerType !== null) {
            return [$containerType, Confidence::INFERRED];
        }

        return [null, Confidence::UNKNOWN];
    }

    private function containerClassArgument(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\FuncCall
            && $expression->name instanceof Node\Name
            && count($expression->name->getParts()) === 1
            && in_array(strtolower($expression->name->getLast()), ['app', 'resolve'], true)) {
            return $this->classNameArgument($expression->args[0]->value ?? null);
        }

        if ($expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'make') === 0
            && $this->isAppCall($expression->var)) {
            return $this->classNameArgument($expression->args[0]->value ?? null);
        }

        return null;
    }

    private function classNameArgument(?Node\Expr $expression): ?string
    {
        if (!$expression instanceof Node\Expr\ClassConstFetch
            || !$expression->class instanceof Node\Name
            || !$expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'class') {
            return null;
        }

        return $this->resolvedName($expression->class);
    }

    private function newClassArgument(?Node\Expr $expression): ?string
    {
        return $expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name
            ? $this->resolvedName($expression->class)
            : null;
    }

    private function isAppCall(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\FuncCall
            && $expression->name instanceof Node\Name
            && count($expression->name->getParts()) === 1
            && strtolower($expression->name->getLast()) === 'app';
    }

    private function isFacade(?string $fqcn): bool
    {
        if ($fqcn === null) {
            return false;
        }
        foreach ($this->facadePrefixes as $prefix) {
            if (str_starts_with(strtolower($fqcn), strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    private function collectAttributes(Node $node): void
    {
        if (!property_exists($node, 'attrGroups')) {
            return;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $target = $this->resolvedName($attribute->name);
                if ($target !== null) {
                    $this->symbol['attributes'][] = $target;
                    $this->addReference($target, null, DependencyType::ATTRIBUTE, Confidence::EXACT, $attribute);
                }
            }
        }
    }

    private function addImport(string $name, string $alias, int $type, Node $node): void
    {
        $this->imports[] = [
            'name' => ltrim($name, '\\'),
            'alias' => $alias,
            'type' => match ($type) {
                Node\Stmt\Use_::TYPE_FUNCTION => 'function',
                Node\Stmt\Use_::TYPE_CONSTANT => 'constant',
                default => 'class',
            },
            'line' => $node->getStartLine(),
        ];
    }

    private function classTypes(Node|string|null $type): array
    {
        if ($type instanceof Node\NullableType) {
            return $this->classTypes($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $types = [];
            foreach ($type->types as $member) {
                $types = array_merge($types, $this->classTypes($member));
            }

            return array_values(array_unique($types));
        }
        if ($type instanceof Node\Name) {
            $resolved = $this->resolvedName($type);

            return $resolved === null ? [] : [$resolved];
        }

        return [];
    }

    private function resolvedName(Node\Name $name): ?string
    {
        return $this->nameContext->resolve($name->toString());
    }

    private function addReference(
        ?string $target,
        ?string $targetMethod,
        DependencyType $type,
        Confidence $confidence,
        Node $node,
        array $metadata = [],
    ): void {
        if ($this->currentClass === null) {
            return;
        }
        $this->references[] = new Reference(
            $this->currentClass,
            $this->currentMethod,
            $target,
            $targetMethod,
            $type,
            $target === null ? Confidence::UNKNOWN : $confidence,
            $this->file,
            $node->getStartLine(),
            $metadata,
        );
    }
}
