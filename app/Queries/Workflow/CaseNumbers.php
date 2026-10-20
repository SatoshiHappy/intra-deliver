<?php

namespace App\Queries\Workflow;

/**
 * 案件番号の表示の書式を決める 1 か所(docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 5 節): WF- と 6 桁に 0 で埋めた数字。
 * 6 桁を超えたらそのまま伸ばす(年・フローごとの振り直しはしない)。一覧・詳細・関連する案件はこれを通した文字列を返す。
 */
class CaseNumbers
{
    /**
     * 例 1 → WF-000001、1234567 → WF-1234567。
     */
    public static function format(int $caseNumber): string
    {
        return sprintf('WF-%06d', $caseNumber);
    }
}
