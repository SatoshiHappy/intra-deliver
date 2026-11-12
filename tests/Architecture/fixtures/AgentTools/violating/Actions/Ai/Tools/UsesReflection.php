<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Records\Api\UpdateRecord;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

/**
 * リフレクションの生成と、リフレクションと Closure の呼び直し・束ね替えは禁止(名前と受け手を字句で決められずに操作を呼べる)
 */
final class UsesReflection
{
    public function __construct(private UpdateRecord $update) {}

    public function method(): ReflectionMethod
    {
        return new ReflectionMethod($this->update, 'execute');
    }

    public function invoke(ReflectionMethod $method): mixed
    {
        return $method->invoke($this->update);
    }

    public function invokeArgs(ReflectionMethod $method): mixed
    {
        return $method->invokeArgs($this->update, []);
    }

    public function closure(ReflectionMethod $method): ?Closure
    {
        return $method->getClosure($this->update);
    }

    /** @param ReflectionClass<UpdateRecord> $class */
    public function closureOfClass(ReflectionClass $class): ?Closure
    {
        return $class->getMethod('execute')->getClosure($this->update);
    }

    public function invokeFunction(ReflectionFunction $function): mixed
    {
        return $function->invoke();
    }

    public function bind(Closure $closure): ?Closure
    {
        return Closure::bind($closure, $this->update);
    }

    public function bindTo(Closure $closure): ?Closure
    {
        return $closure->bindTo($this->update);
    }

    public function call(Closure $closure): mixed
    {
        return $closure->call($this->update);
    }

    public function fromCallable(): Closure
    {
        return Closure::fromCallable([$this->update, 'execute']);
    }

    public function replaceDependency(\ReflectionProperty $property, object $other): void
    {
        $property->setValue($this->update, $other);
    }
}
