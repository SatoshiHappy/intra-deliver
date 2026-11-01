<?php

namespace App\Queries\Workflow;

use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Support\AccessContext;
use LogicException;

/**
 * 申請の詳細のサイドパネルの開いた欄(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.1 節)。要求の panel・before を
 * 読んでレコードの詳細と同じ口 {@see RecordShowPanelQuery::content()} を呼ぶ。
 */
class CaseRecordPanel
{
    public function __construct(private RecordShowPanelQuery $panels) {}

    /**
     * 開いた欄の中身(クエリ panel の値は口がキーの一覧で照らす)。before は部分の再読み込みのときだけ読む(全体の応答は新しいものから)。
     *
     * @return array<string, mixed>|null
     *
     * @throws LogicException 業務ツールの節の名札の誤り
     */
    public function content(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        CaseShowSelection $selection,
    ): ?array {
        $before = $selection->before;
        $beforeId = $selection->partial && is_string($before)
            && preg_match('/^[1-9][0-9]{0,17}\z/', $before) === 1 ? (int) $before : null;

        return is_string($selection->panel)
            ? $this->panels->content($context, $moduleCode, $recordId, $selection->panel, $beforeId)
            : null;
    }
}
