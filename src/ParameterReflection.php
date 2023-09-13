<?php

declare(strict_types=1);

namespace Typhoon\Reflection;

use Typhoon\Reflection\Reflector\FriendlyReflection;
use Typhoon\TypeVisitor;

/**
 * @api
 */
final class ParameterReflection extends FriendlyReflection
{
    /**
     * @internal
     * @psalm-internal Typhoon\Reflection
     * @param callable-string|array{class-string, non-empty-string} $function
     * @param int<0, max> $position
     * @param non-empty-string $name
     * @param ?positive-int $startLine
     * @param ?positive-int $endLine
     */
    public function __construct(
        public readonly string|array $function,
        private readonly int $position,
        public readonly string $name,
        private readonly bool $passedByReference,
        private readonly bool $defaultValueAvailable,
        private readonly bool $optional,
        private readonly bool $variadic,
        private readonly bool $promoted,
        private readonly TypeReflection $type,
        private readonly ?int $startLine,
        private readonly ?int $endLine,
        private ?\ReflectionParameter $reflectionParameter = null,
    ) {}

    public function canBePassedByValue(): bool
    {
        return !$this->passedByReference;
    }

    public function getDefaultValue(): mixed
    {
        return $this->reflectionParameter()->getDefaultValue();
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int<0, max>
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    public function getType(): TypeReflection
    {
        return $this->type;
    }

    public function isDefaultValueAvailable(): bool
    {
        return $this->defaultValueAvailable;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function isPassedByReference(): bool
    {
        return $this->passedByReference;
    }

    public function isPromoted(): bool
    {
        return $this->promoted;
    }

    public function isVariadic(): bool
    {
        return $this->variadic;
    }

    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['reflectionParameter']);

        return $data;
    }

    /**
     * @return ?positive-int
     */
    public function getStartLine(): ?int
    {
        return $this->startLine;
    }

    /**
     * @return ?positive-int
     */
    public function getEndLine(): ?int
    {
        return $this->endLine;
    }

    protected function withResolvedTypes(TypeVisitor $typeResolver): static
    {
        $data = get_object_vars($this);
        $data['type'] = $this->type->withResolvedTypes($typeResolver);

        return new self(...$data);
    }

    protected function toChildOf(FriendlyReflection $parent): static
    {
        $data = get_object_vars($this);
        $data['type'] = $this->type->toChildOf($parent->type);

        return new self(...$data);
    }

    private function reflectionParameter(): \ReflectionParameter
    {
        return $this->reflectionParameter ??= new \ReflectionParameter($this->function, $this->name);
    }

    private function __clone() {}
}