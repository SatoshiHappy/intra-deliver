<?php

namespace App\Http\Controllers\Web\Ai;

use App\Actions\Ai\Api\SaveAgentDefinition;

/**
 * 一覧に無い入口(定義の設定の業務処理を通らない)からの定義を書く口への依存
 */
final class SavesDefinition
{
    public function __construct(private SaveAgentDefinition $save) {}

    public function save(): SaveAgentDefinition
    {
        return $this->save;
    }
}
