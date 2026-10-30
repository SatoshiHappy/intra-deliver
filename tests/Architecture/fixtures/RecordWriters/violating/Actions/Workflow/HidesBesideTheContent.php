<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\HideRecordModuleUnchecked;

/**
 * コンテンツを作る業務処理と同じ名前空間のほかのクラス(登録・更新と判定しない削除の一覧の場所)も、非表示の口の一覧の外
 */
final class HidesBesideTheContent
{
    public function __construct(private HideRecordModuleUnchecked $hideModule) {}

    public function hideModule(): HideRecordModuleUnchecked
    {
        return $this->hideModule;
    }
}
