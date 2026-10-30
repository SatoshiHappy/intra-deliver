<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordModuleVisibilityStops;
use Illuminate\Container\Attributes\Tag;

/**
 * 業務モジュールの表示の止めの全体 = タグ {@see RecordModuleVisibilityStops::TAG} で束ねた実装を聞く 1 か所(内部。業務データの入れ物が
 * 実装を直接呼ばないため。保証と束ね方 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表の RecordModuleVisibilityStops の行)。
 */
class RecordModuleVisibilityStopCatalog
{
    /**
     * @param  iterable<RecordModuleVisibilityStops>  $visibilityStops
     */
    public function __construct(#[Tag(RecordModuleVisibilityStops::TAG)] private iterable $visibilityStops) {}

    /**
     * その業務モジュール(親の無いもの)の表示の止めの理由: 実装を登録の順に聞いて最初の非 null。無ければ null。
     */
    public function stopReason(int $recordModuleId): ?string
    {
        foreach ($this->visibilityStops as $visibilityStop) {
            $reason = $visibilityStop->stopReason($recordModuleId);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
