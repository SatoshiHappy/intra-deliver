<?php

namespace Tests\Unit\Actions\Ai\Core;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * 会話の中核のテストの基底(docs の 設計/AI/検査で守る結果.md の (f))。テストごとに空のコンテナを置き直し、ファサードの解いた物を消す
 * (テストの順に依らない)。そのため、名前の文字列でコンテナから引く形・event()・cache()・config()・session() は、テストが通した道で
 * 解けずに落ちる。
 */
abstract class EmptyContainerTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(new Container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
