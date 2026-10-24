<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\DeleteRecordUnchecked;

/**
 * 判定しない削除の呼び手の一覧の namespace(案件の削除で申請書のレコードを一緒に消す)
 */
final class DeletesCaseRecord
{
    public function __construct(private DeleteRecordUnchecked $deleteRecord) {}

    public function deleteRecord(): DeleteRecordUnchecked
    {
        return $this->deleteRecord;
    }
}
