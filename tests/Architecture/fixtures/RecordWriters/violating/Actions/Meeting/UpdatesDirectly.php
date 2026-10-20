<?php

namespace App\Actions\Meeting;

use App\Actions\Records\Api\UpdateRecord;

/**
 * 業務ツールのモジュール(一覧の外)がレコードを直接更新するのは禁止
 */
final class UpdatesDirectly
{
    public function __construct(private UpdateRecord $updateRecord) {}

    public function updateRecord(): UpdateRecord
    {
        return $this->updateRecord;
    }
}
