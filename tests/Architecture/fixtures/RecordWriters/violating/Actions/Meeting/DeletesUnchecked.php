<?php

namespace App\Actions\Meeting;

use App\Actions\Records\Api\DeleteRecordUnchecked;

/**
 * 業務ツールのモジュール(一覧の外)が主体の判定をしないでレコードを削除するのは禁止
 */
final class DeletesUnchecked
{
    public function __construct(private DeleteRecordUnchecked $deleteRecord) {}

    public function deleteRecord(): DeleteRecordUnchecked
    {
        return $this->deleteRecord;
    }
}
