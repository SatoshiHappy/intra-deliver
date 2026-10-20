<?php

namespace App\Actions\Alpha;

use App\Models\Records\Record;

/**
 * 公開の口の外(モジュールの内部)の public メソッドはモデルを使える
 */
final class ReturnsModel
{
    public function find(int $recordId): Record
    {
        return Record::query()->findOrFail($recordId);
    }
}
