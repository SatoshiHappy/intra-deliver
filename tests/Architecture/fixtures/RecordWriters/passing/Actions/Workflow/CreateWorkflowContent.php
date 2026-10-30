<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\HideRecordModuleUnchecked;

/**
 * 非表示の口の一覧の class(コンテンツを作る業務処理。そのクラスだけが通る)
 */
final class CreateWorkflowContent
{
    public function __construct(private HideRecordModuleUnchecked $hideModule) {}

    public function hideModule(): HideRecordModuleUnchecked
    {
        return $this->hideModule;
    }
}
