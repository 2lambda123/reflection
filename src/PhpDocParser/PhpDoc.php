<?php

declare(strict_types=1);

namespace Typhoon\Reflection\PhpDocParser;

use PHPStan\PhpDocParser\Ast\PhpDoc\DeprecatedTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Typhoon\Reflection\TagPrioritizer;
use Typhoon\Reflection\Variance;

/**
 * @internal
 * @psalm-internal Typhoon\Reflection
 */
final class PhpDoc
{
    private static ?self $empty = null;

    private TypeNode|null|false $varType = false;

    /**
     * @var ?array<non-empty-string, TypeNode>
     */
    private ?array $paramTypes = null;

    private TypeNode|null|false $returnType = false;

    /**
     * @var ?list<TemplateTagValueNode>
     */
    private ?array $templates = null;

    /**
     * @var ?list<GenericTypeNode>
     */
    private ?array $extendedTypes = null;

    /**
     * @var ?list<GenericTypeNode>
     */
    private ?array $implementedTypes = null;

    /**
     * @param array<PhpDocTagNode> $tags
     */
    public function __construct(
        private readonly TagPrioritizer $tagPrioritizer,
        private array $tags,
    ) {}

    public static function empty(): self
    {
        return self::$empty ??= new self(
            tagPrioritizer: new TagPrioritizer\PHPStanOverPsalmOverOthersTagPrioritizer(),
            tags: [],
        );
    }

    public function isDeprecated(): bool
    {
        foreach ($this->tags as $tag) {
            if ($tag->value instanceof DeprecatedTagValueNode) {
                return true;
            }
        }

        return false;
    }

    public function isFinal(): bool
    {
        foreach ($this->tags as $tag) {
            if ($tag->name === '@final') {
                return true;
            }
        }

        return false;
    }

    public function isReadonly(): bool
    {
        foreach ($this->tags as $tag) {
            if (\in_array($tag->name, ['@readonly', '@psalm-readonly', '@phpstan-readonly'], true)) {
                return true;
            }
        }

        return false;
    }

    public function varType(): ?TypeNode
    {
        if ($this->varType !== false) {
            return $this->varType;
        }

        $varTag = null;

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof VarTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<VarTagValueNode> $tag */
            if ($this->shouldReplaceTag($varTag, $tag)) {
                $varTag = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->varType = $varTag?->value->type;
    }

    /**
     * @return array<non-empty-string, TypeNode>
     */
    public function paramTypes(): array
    {
        if ($this->paramTypes !== null) {
            return $this->paramTypes;
        }

        $paramTags = [];

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof ParamTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<ParamTagValueNode> $tag */
            $name = $tag->value->parameterName;
            \assert(($name[0] ?? '') === '$');
            $name = substr($name, 1);
            \assert($name !== '');

            if ($this->shouldReplaceTag($paramTags[$name] ?? null, $tag)) {
                $paramTags[$name] = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->paramTypes = array_map(
            static fn (PhpDocTagNode $tag): TypeNode => $tag->value->type,
            $paramTags,
        );
    }

    public function returnType(): ?TypeNode
    {
        if ($this->returnType !== false) {
            return $this->returnType;
        }

        $returnTag = null;

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof ReturnTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<ReturnTagValueNode> $tag */
            if ($this->shouldReplaceTag($returnTag, $tag)) {
                $returnTag = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->returnType = $returnTag?->value->type;
    }

    /**
     * @return list<TemplateTagValueNode>
     */
    public function templates(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $templateTags = [];

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof TemplateTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<TemplateTagValueNode> $tag */
            if ($this->shouldReplaceTag($templateTags[$tag->value->name] ?? null, $tag)) {
                $templateTags[$tag->value->name] = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->templates = array_map(
            static function (PhpDocTagNode $tag): TemplateTagValueNode {
                $tag->value->setAttribute('variance', match (true) {
                    str_ends_with($tag->name, 'covariant') => Variance::COVARIANT,
                    str_ends_with($tag->name, 'contravariant') => Variance::CONTRAVARIANT,
                    default => Variance::INVARIANT,
                });

                return $tag->value;
            },
            array_values($templateTags),
        );
    }

    /**
     * @return list<non-empty-string>
     */
    public function templateNames(): array
    {
        return array_column($this->templates(), 'name');
    }

    /**
     * @return list<GenericTypeNode>
     */
    public function extendedTypes(): array
    {
        if ($this->extendedTypes !== null) {
            return $this->extendedTypes;
        }

        $extendsTags = [];

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof ExtendsTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<ExtendsTagValueNode> $tag */
            $name = $tag->value->type->type->name;

            if ($this->shouldReplaceTag($extendsTags[$name] ?? null, $tag)) {
                $extendsTags[$name] = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->extendedTypes = array_map(
            static fn (PhpDocTagNode $tag): GenericTypeNode => $tag->value->type,
            array_values($extendsTags),
        );
    }

    /**
     * @return list<GenericTypeNode>
     */
    public function implementedTypes(): array
    {
        if ($this->implementedTypes !== null) {
            return $this->implementedTypes;
        }

        $implementsTags = [];

        foreach ($this->tags as $key => $tag) {
            if (!$tag->value instanceof ImplementsTagValueNode) {
                continue;
            }

            /** @var PhpDocTagNode<ImplementsTagValueNode> $tag */
            $name = $tag->value->type->type->name;

            if ($this->shouldReplaceTag($implementsTags[$name] ?? null, $tag)) {
                $implementsTags[$name] = $tag;
            }

            unset($this->tags[$key]);
        }

        return $this->implementedTypes = array_map(
            static fn (PhpDocTagNode $tag): GenericTypeNode => $tag->value->type,
            array_values($implementsTags),
        );
    }

    /**
     * @template TCurrentValueNode of PhpDocTagValueNode
     * @template TNewValueNode of PhpDocTagValueNode
     * @param PhpDocTagNode<TCurrentValueNode> $currentTag
     * @param PhpDocTagNode<TNewValueNode> $newTag
     */
    private function shouldReplaceTag(?PhpDocTagNode $currentTag, PhpDocTagNode $newTag): bool
    {
        return $currentTag === null || $this->priorityOf($newTag) >= $this->priorityOf($currentTag);
    }

    /**
     * @template TValueNode of PhpDocTagValueNode
     * @param PhpDocTagNode<TValueNode> $tag
     */
    private function priorityOf(PhpDocTagNode $tag): int
    {
        $priority = $tag->getAttribute('priority');

        if (!\is_int($priority)) {
            $priority = $this->tagPrioritizer->priorityFor($tag->name);
            $tag->setAttribute('priority', $priority);
        }

        return $priority;
    }
}
