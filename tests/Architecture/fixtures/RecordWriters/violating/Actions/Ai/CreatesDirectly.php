<?php

namespace App\Actions\Ai;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Support\AccessContext;

/**
 * AI の処理(一覧の外)が決めた口を通らずにレコードを作るのは禁止(コンテナから引いて呼ぶ)
 */
final class CreatesDirectly
{
    public function createFromOutput(AccessContext $context): int
    {
        return app(CreateRecord::class)->execute($context, 'tasks', new RecordInput(['title' => 'AI の出力']));
    }
}
