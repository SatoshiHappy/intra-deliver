<?php

namespace Tests\Unit\Actions\Ai\Core;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 中核のテストの基底の自己証明: 型で止まらない形が、基底の中では解けずに落ちる(前のテストがコンテナに登録した物が残っていても)。
 */
final class EmptyContainerTestCaseTest extends EmptyContainerTestCase
{
    /**
     * @return array<string, array{Closure(): mixed}>
     */
    public static function unresolvable(): array
    {
        return [
            'event()' => [static fn (): mixed => event('x')],
            'cache()' => [static fn (): mixed => cache('k')],
            'config()' => [static fn (): mixed => config('x')],
            'session()' => [static fn (): mixed => session('x')],
            '名前の文字列の app()' => [static fn (): mixed => app('d'.'b')],
        ];
    }

    #[DataProvider('unresolvable')]
    public function test_a_container_call_fails_in_the_core_test_base(Closure $call): void
    {
        $this->expectException(BindingResolutionException::class);

        $call();
    }

    public function test_the_base_replaces_a_container_left_by_an_earlier_test(): void
    {
        $left = new Container;
        $left->instance('config', 'leftover');
        Container::setInstance($left);

        $this->setUp();

        $this->assertNotSame($left, Container::getInstance());
        $this->assertFalse(Container::getInstance()->bound('config'));
    }
}
