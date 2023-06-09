<?php

declare(strict_types=1);

namespace ExtendedTypeSystem\Reflection\TypeReflector;

use ExtendedTypeSystem\Reflection\PHPDocParser\PHPDocParser;
use ExtendedTypeSystem\Reflection\Scope;
use ExtendedTypeSystem\Reflection\Scope\ClassLikeScope;
use ExtendedTypeSystem\Type;
use ExtendedTypeSystem\types;
use N\A;
use N\B;
use N\X;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\Lexer\Emulative;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FirstFindingVisitor;
use PhpParser\Parser\Php7;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TypeParser::class)]
final class TypeParserTest extends TestCase
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @return \Generator<int, array{string, Type}>
     */
    public static function nativeTypes(): \Generator
    {
        yield ['bool', types::bool];
        yield ['int', types::int];
        yield ['float', types::float];
        yield ['string', types::string];
        yield ['array', types::array()];
        yield ['iterable', types::iterable()];
        yield ['object', types::object];
        yield ['mixed', types::mixed];
        yield ['\Closure', types::object(\Closure::class)];
        yield ['callable', types::callable()];
        yield ['void', types::void];
        yield ['never', types::never];
        yield ['string|int|null', types::union(types::string, types::int, types::null)];
        yield ['string|false', types::union(types::string, types::false)];
        yield ['\Countable&\Traversable', types::intersection(types::object(\Countable::class), types::object(\Traversable::class))];
        yield ['?int', types::nullable(types::int)];
        yield ['self', types::object(X::class)];
        yield ['parent', types::object(B::class)];
        yield ['static', types::static(X::class)];
        yield ['namespace\\A', types::object(A::class)];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @return \Generator<int, array{string, Type}>
     */
    public static function php82NativeTypes(): \Generator
    {
        yield ['null', types::null];
        yield ['true', types::true];
        yield ['false', types::false];
        yield ['(\Countable&\Traversable)|string', types::union(
            types::intersection(types::object(\Countable::class), types::object(\Traversable::class)),
            types::string,
        )];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @return \Generator<int, array{string, Type}>
     */
    public static function phpDocTypes(): \Generator
    {
        yield ['literal-int', types::literalInt];
        yield ['literal-string', types::literalString];
        yield ['numeric-string', types::numericString];
        yield ['class-string', types::classString];
        yield ['callable-string', types::callableString];
        yield ['interface-string', types::interfaceString];
        yield ['enum-string', types::enumString];
        yield ['trait-string', types::traitString];
        yield ['non-empty-string', types::nonEmptyString];
        yield ['numeric', types::numeric];
        yield ['scalar', types::scalar];
        yield ['callable-array', types::callableArray];
        yield ['resource', types::resource];
        yield ['closed-resource', types::closedResource];
        yield ['array-key', types::arrayKey];
        yield ['negative-int', types::negativeInt()];
        yield ['non-positive-int', types::nonPositiveInt()];
        yield ['non-negative-int', types::nonNegativeInt()];
        yield ['positive-int', types::positiveInt()];
        yield ['int<10, max>', types::int(10)];
        yield ['int<-10, max>', types::int(-10)];
        yield ['int<min, 10>', types::int(max: 10)];
        yield ['int<min, -10>', types::int(max: -10)];
        yield ['int<min, max>', types::int()];
        yield ['123', types::intLiteral(123)];
        yield ['1.5', types::floatLiteral(1.5)];
        yield ["''", types::stringLiteral('')];
        yield ["'abc'", types::stringLiteral('abc')];
        yield ['string[]', types::array(valueType: types::string)];
        yield ['list<string>', types::list(types::string)];
        yield ['non-empty-list<string>', types::nonEmptyList(types::string)];
        yield ['array<string>', types::array(valueType: types::string)];
        yield ['array<int, string>', types::array(types::int, types::string)];
        yield ['list{true, false}', types::shape([types::true, types::false])];
        yield ['list{int, float, ...}', types::unsealedShape([types::int, types::float])];
        yield ['array{int, float}', types::shape([types::int, types::float])];
        yield ['array{int, float, ...}', types::unsealedShape([types::int, types::float])];
        yield ['array{a: int, b?: float}', types::shape(['a' => types::int, 'b' => types::optional(types::float)])];
        yield ["array{'a': int, 0: float}", types::shape(['a' => types::int, 0 => types::float])];
        yield ['non-empty-array<int, string>', types::nonEmptyArray(types::int, types::string)];
        yield ['iterable<string>', types::iterable(valueType: types::string)];
        yield ['iterable<int, string>', types::iterable(types::int, types::string)];
        yield ['\\Iterator<int, string>', types::object(\Iterator::class, types::int, types::string)];
        yield ['static<int, string>', types::static(X::class, types::int, types::string)];
        yield ['X::C', types::classConstant(X::class, 'C')];
        yield ['T1', types::classTemplate('T1', X::class)];
        yield ['callable(bool, int=, string...): void', types::callable([types::bool, types::defaultParam(types::int), types::variadicParam(types::string)], types::void)];
        yield ['\\Closure(bool, int=, string...): void', types::closure([types::bool, types::defaultParam(types::int), types::variadicParam(types::string)], types::void)];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @return \Generator<int, array{string, \Exception}>
     */
    public static function invalidPHPDocTypes(): \Generator
    {
        yield ["int<'a', 1>", new \LogicException('"a" cannot be used as int range limit.')];
        yield ['array(): void', new \LogicException('"array" cannot be used as callable type.')];
    }

    #[DataProvider('nativeTypes')]
    #[DataProvider('php82NativeTypes')]
    public function testItParsesNativeTypes(string $type, Type $expectedType): void
    {
        $phpParser = new Php7(new Emulative());
        $nodes = $phpParser->parse("<?php class A { public function method(): {$type} {} }") ?? [];
        $traverser = new NodeTraverser();
        $finder = new FirstFindingVisitor(static fn (Node $node): bool => $node instanceof ClassMethod);
        $traverser->addVisitor($finder);
        $traverser->traverse($nodes);
        /** @var ClassMethod */
        $methodNode = $finder->getFoundNode();

        $parsedType = TypeParser::parseNativeType($this->createScope(), $methodNode->returnType);

        self::assertEquals($expectedType, $parsedType);
    }

    public function testItReturnsNullNativeTypeForNullNode(): void
    {
        $type = TypeParser::parseNativeType($this->createScope(), null);

        self::assertNull($type);
    }

    public function testItThrowsIfUnknownNativeTypePassed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/[\w\\\]+ is not supported\./');

        TypeParser::parseNativeType($this->createScope(), $this->createMock(Node::class));
    }

    #[DataProvider('nativeTypes')]
    #[DataProvider('php82NativeTypes')]
    #[DataProvider('phpDocTypes')]
    public function testItParsesPHPDocTypes(string $type, Type $expectedType): void
    {
        $phpDocParser = new PHPDocParser();
        $typeNode = $phpDocParser->parseTypeFromString($type);

        $parsedType = TypeParser::parsePHPDocType($this->createScope(), $typeNode);

        self::assertEquals($expectedType, $parsedType);
    }

    public function testItReturnsNullPHPDocTypeForNullNode(): void
    {
        $type = TypeParser::parsePHPDocType($this->createScope(), null);

        self::assertNull($type);
    }

    public function testItThrowsIfUnknownPHPDocTypePassed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/[\w\\\]+ is not supported\./');

        TypeParser::parsePHPDocType($this->createScope(), $this->createMock(TypeNode::class));
    }

    #[DataProvider('invalidPHPDocTypes')]
    public function testItThrowsIfInvalidPHPDocTypePassed(string $type, \Exception $exception): void
    {
        $phpDocParser = new PHPDocParser();
        $typeNode = $phpDocParser->parseTypeFromString($type);

        $this->expectExceptionObject($exception);

        TypeParser::parsePHPDocType($this->createScope(), $typeNode);
    }

    private function createScope(): Scope
    {
        $nameContext = new NameContext(new Throwing());
        $nameContext->startNamespace(new Node\Name('N'));

        return new ClassLikeScope(
            name: X::class,
            parent: B::class,
            templateNames: ['T1'],
            parentScope: new Scope\NameContextScope($nameContext),
        );
    }
}