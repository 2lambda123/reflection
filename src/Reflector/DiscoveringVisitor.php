<?php

declare(strict_types=1);

namespace Typhoon\Reflection\Reflector;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Typhoon\Reflection\AnonymousClassName;
use Typhoon\Reflection\ClassReflection;
use Typhoon\Reflection\NameResolution\NameContext;
use Typhoon\Reflection\ParsingContext;
use Typhoon\Reflection\ReflectionContext;
use Typhoon\Reflection\ReflectionException;

/**
 * @internal
 * @psalm-internal Typhoon\Reflection
 */
final class DiscoveringVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly ParsingContext $parsingContext,
        private readonly NameContext $nameContext,
        private readonly Resource $resource,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            $name = $this->resolveClassName($node);
            $nameContext = clone $this->nameContext;
            $this->parsingContext->registerClassReflector(
                name: $name,
                reflector: fn (ReflectionContext $reflectionContext): ClassReflection => (new PhpParserReflector(
                    reflectionContext: $reflectionContext,
                    nameContext: $nameContext,
                    resource: $this->resource,
                ))->reflectClass($node, $name),
            );
        }

        return null;
    }

    /**
     * @return class-string
     */
    private function resolveClassName(Stmt\ClassLike $node): string
    {
        if ($node->name !== null) {
            return $this->nameContext->resolveNameAsClass($node->name->toString());
        }

        if (!$node instanceof Stmt\Class_) {
            throw new ReflectionException();
        }

        $name = AnonymousClassName::fromNode(
            file: $this->resource->file,
            node: $node,
            nameContext: $this->nameContext,
        );

        return $name->toStringWithoutRtdKeyCounter();
    }
}
