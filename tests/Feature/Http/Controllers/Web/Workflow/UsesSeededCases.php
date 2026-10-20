<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;

/**
 * ワークフローの初期データ(docs の 設計/ワークフロー/初期データ.md 4 節の案件の見本)を使う HTTP のテストの手助け: 番号で案件を引く、一時保存を引く、利用者として開く。
 */
trait UsesSeededCases
{
    /**
     * 案件番号 WF-00000N の案件の id。
     */
    private function caseNumbered(int $number): int
    {
        return WorkflowCase::query()->where('case_number', $number)->sole()->id;
    }

    /**
     * 初期データの一時保存(u0001 の 1 件)の id。
     */
    private function seededDraft(): int
    {
        return WorkflowCase::query()->where('state', WorkflowCaseState::Draft)->sole()->id;
    }

    private function actingAsUser(string $code): void
    {
        $this->actingAs(User::query()->where('code', $code)->sole());
    }
}
