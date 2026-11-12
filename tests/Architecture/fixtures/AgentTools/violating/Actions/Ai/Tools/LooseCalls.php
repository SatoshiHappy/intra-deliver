<?php

namespace App\Actions\Ai\Tools;

use App\Queries\Records\Api\RecordDisplayQuery;
use Illuminate\Support\Facades\App;

/**
 * 届く操作と鍵の引数を決められない呼び出しは、受け手によらず禁止(受け手が mixed・名前が字句でない・引数の展開・callable を受ける呼び出し)
 */
final class LooseCalls
{
    public function __construct(private RecordDisplayQuery $display) {}

    public function onMixed(mixed $value): mixed
    {
        return $value->titleOf(1);
    }

    public function byName(string $name): mixed
    {
        return $this->display->$name(1);
    }

    /** @param list<mixed> $arguments */
    public function spread(array $arguments): mixed
    {
        return $this->display->show(...$arguments);
    }

    public function throughFunction(callable $callable): mixed
    {
        return call_user_func($callable);
    }

    public function throughContainer(callable $callable): mixed
    {
        return app()->call($callable);
    }

    public function throughFacade(callable $callable): mixed
    {
        return App::call($callable);
    }

    public function invoke(callable $callable): mixed
    {
        return $callable();
    }
}
