<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\UpdateRecordUnchecked;

/**
 * 登録・更新の一覧の場所(ワークフロー)も、判定しない更新の一覧の外
 */
final class UpdatesUnchecked
{
    public function __construct(private UpdateRecordUnchecked $updateRecordUnchecked) {}

    public function updateRecordUnchecked(): UpdateRecordUnchecked
    {
        return $this->updateRecordUnchecked;
    }
}
