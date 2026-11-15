<?php

declare(strict_types=1);

namespace Thesis\Formatter;

use Testo\Assert;
use Testo\Test;

#[Test]
final class FormatCodeTest
{
    public function formatsClass(): void
    {
        Assert::same(formatClass(Service::class), Service::class);
        Assert::same(formatClass(new Service()), Service::class);
    }

    public function formatsWhatDoesNotExistWithoutAutoloading(): void
    {
        $autoloaded = [];
        $autoload = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($autoload, prepend: true);

        try {
            Assert::same(formatClass('App\Missing'), 'App\Missing');
            Assert::same(formatFunction('App\Missing::run'), 'App\Missing::run()');
            Assert::same(formatFunction(['App\Missing', 'run']), 'App\Missing::run()');
            Assert::same(formatParameter('App\missing', 'id'), 'App\missing($id)');
            Assert::same(formatProperty('App\Missing', 'count'), 'App\Missing::$count');
        } finally {
            spl_autoload_unregister($autoload);
        }

        Assert::same($autoloaded, []);
    }

    public function formatsAnonymousClassByNameWhereItIsNotDeclared(): void
    {
        Assert::same(formatClass("class@anonymous\0/app/src/foo.php:12$0"), 'class@/app/src/foo.php:12');
        Assert::same(formatClass("App\\Base@anonymous\0/app/src/foo.php:12$1f"), 'App\Base@/app/src/foo.php:12');
        Assert::same(
            actual: formatClass("class@anonymous\0/app/src/foo.php(7) : eval()'d code:1$0"),
            expected: 'class@/app/src/foo.php:7',
        );
    }

    public function formatsWhatIsNotNameAsString(): void
    {
        Assert::same(formatClass("App\\Foo\nBar"), '"App\\\Foo\nBar"');
        Assert::same(formatClass("class@anonymous\0/app/\nfoo.php:12$0"), '"class@anonymous\x00/app/\nfoo.php:12\$0"');
        Assert::same(formatClass(''), "''");
        Assert::same(formatFunction('run me'), "'run me'()");
        Assert::same(formatProperty(Service::class, 'a b'), "Thesis\\Formatter\\Service::$'a b'");
    }

    public function formatsWhatIsNotCallable(): void
    {
        Assert::same(formatFunction(42), '42()'); // @phpstan-ignore argument.type (a caller may pass anything)
        Assert::same(formatFunction([]), 'array{}()'); // @phpstan-ignore argument.type (a caller may pass anything)
        Assert::same(formatFunction([1, 'run']), "list{1, 'run'}()"); // @phpstan-ignore argument.type (the same)
    }

    public function formatsAnonymousClassByItsLocation(): void
    {
        Assert::same(formatClass(new class {}), 'class@' . __FILE__ . ':' . __LINE__);
        Assert::same(formatClass(new class extends \ArrayObject {}), 'ArrayObject@' . __FILE__ . ':' . __LINE__);
    }

    public function formatsAnonymousClassOfInterfaceByInterface(): void
    {
        $object = new class implements \IteratorAggregate {
            public function getIterator(): \Iterator
            {
                return new \EmptyIterator();
            }
        };

        Assert::same(formatClass($object), 'IteratorAggregate@' . __FILE__ . ':' . (__LINE__ - 7));
    }

    public function formatsAnonymousClassOfEvalByLineOfEval(): void
    {
        $object = eval('return new class {};');
        \assert(\is_object($object));

        Assert::same(formatClass($object), 'class@' . __FILE__ . ':' . (__LINE__ - 3));
    }

    public function formatsFunction(): void
    {
        Assert::same(formatFunction('strlen'), 'strlen()');
        Assert::same(formatFunction(strlen(...)), 'strlen()');
        Assert::same(formatFunction(format(...)), 'Thesis\Formatter\format()');
    }

    public function formatsMethod(): void
    {
        $method = 'Thesis\Formatter\Service::create()';

        Assert::same(formatFunction('Thesis\Formatter\Service::create'), $method);
        Assert::same(formatFunction([Service::class, 'create']), $method);
        Assert::same(formatFunction(Service::create(...)), $method);
        Assert::same(formatFunction([new Service(), 'handle']), 'Thesis\Formatter\Service::handle()');
        Assert::same(formatFunction(new Service()->handle(...)), 'Thesis\Formatter\Service::handle()');
    }

    public function formatsMethodOfAnonymousClass(): void
    {
        $object = new class {
            public function run(): void {}
        };

        Assert::same(formatFunction($object->run(...)), 'class@' . __FILE__ . ':' . (__LINE__ - 4) . '::run()');
    }

    public function formatsInvokableObject(): void
    {
        Assert::same(formatFunction(new Service()), 'Thesis\Formatter\Service()');
    }

    public function formatsClosureByItsLocation(): void
    {
        Assert::same(formatFunction(static fn() => null), 'function@' . __FILE__ . ':' . __LINE__ . '()');
        [$closure, $line] = new Service()->closure();
        Assert::same(formatFunction($closure), 'function@' . __DIR__ . "/Service.php:{$line}()");
    }

    public function formatsClosureOfEvalByLineOfEval(): void
    {
        $closure = eval('return static fn() => null;');
        \assert($closure instanceof \Closure);

        Assert::same(formatFunction($closure), 'function@' . __FILE__ . ':' . (__LINE__ - 3) . '()');
    }

    public function formatsClosureOfNestedEvalByLineOfOutermostEval(): void
    {
        $closure = eval('return eval("return static fn() => null;");');
        \assert($closure instanceof \Closure);

        Assert::same(formatFunction($closure), 'function@' . __FILE__ . ':' . (__LINE__ - 3) . '()');
    }

    public function formatsParameter(): void
    {
        Assert::same(formatParameter('strlen', 'string'), 'strlen($string)');
        Assert::same(formatParameter([new Service(), 'handle'], 'id'), 'Thesis\Formatter\Service::handle($id)');
        Assert::same(
            actual: formatParameter(static fn(int $id) => $id, 'id'),
            expected: 'function@' . __FILE__ . ':' . (__LINE__ - 1) . '($id)',
        );
    }

    public function formatsProperty(): void
    {
        Assert::same(formatProperty(Service::class, 'count'), 'Thesis\Formatter\Service::$count');
        Assert::same(formatProperty(new Service(), 'count'), 'Thesis\Formatter\Service::$count');
    }

    public function formatsReflectedClass(): void
    {
        Assert::same(formatReflectedClass(new \ReflectionClass(Service::class)), Service::class);
        Assert::same(formatReflectedClass(new \ReflectionClass(new class {})), 'class@' . __FILE__ . ':' . __LINE__);
    }

    public function formatsReflectedFunction(): void
    {
        Assert::same(formatReflectedFunction(new \ReflectionFunction('strlen')), 'strlen()');
        Assert::same(
            actual: formatReflectedFunction(new \ReflectionMethod(Service::class, 'handle')),
            expected: 'Thesis\Formatter\Service::handle()',
        );
        Assert::same(
            actual: formatReflectedFunction(new \ReflectionFunction(static fn() => null)),
            expected: 'function@' . __FILE__ . ':' . (__LINE__ - 1) . '()',
        );
    }

    public function formatsReflectedParameter(): void
    {
        Assert::same(
            actual: formatReflectedParameter(new \ReflectionParameter([Service::class, 'handle'], 'id')),
            expected: 'Thesis\Formatter\Service::handle($id)',
        );
        Assert::same(
            actual: formatReflectedParameter(new \ReflectionParameter(static fn(int $id) => $id, 'id')),
            expected: 'function@' . __FILE__ . ':' . (__LINE__ - 1) . '($id)',
        );
    }

    public function formatsReflectedProperty(): void
    {
        Assert::same(
            actual: formatReflectedProperty(new \ReflectionProperty(Service::class, 'count')),
            expected: 'Thesis\Formatter\Service::$count',
        );
    }
}
