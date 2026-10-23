<?php

namespace Database\Seeders;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\DraftInput;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * {@see WorkflowSeeder} の 2 段目の案件の見本 9(docs の 設計/ワークフロー/初期データ.md 4 節)と、見本の申請・差戻しの手助け(版 3 と案件の運用の見本も使う)。
 * 使う側は申請・承認・承認終了・否認・差戻し・再申請・取止め・一時保存の業務処理と、DATES・planInput()・projectIdOf()・approveAs()・contextAt()・
 * currentNodeIdOf()・revisionOf()・recordIdOf()・userId() を持つ。
 */
trait SeedsWorkflowSampleCases
{
    /**
     * 案件の見本 9(見せるもの = 設計/ワークフロー/初期データ.md 4 節。申請の順に案件番号が付く)。計画の申請基準日 = 版 2 の日、課題 = 申請の操作の日(版 1)。
     * 操作の時刻 = 設計/ワークフロー/案件の運用/初期データ.md 4.2(基準の日に書いた値)。
     */
    private function createCases(): void
    {
        $first = $this->applyPlan('2026-09-05 09:00', 'u0001', 1, '営業支援ツールの導入計画を申請します。');
        $this->approveAs('2026-09-06 10:00', 'u0002', $first, '確認しました。');
        $this->approveAs('2026-09-07 11:00', 'u0003', $first, '承認します。');

        $issue = $this->issueInput('導入先の部署が決定していない', $this->recordIdOf($first), 'u0001', 'medium', '2026-11-13');
        $this->applyIssue('2026-10-09 09:30', 'u0001', $issue);

        $third = $this->applyPlan('2026-10-05 09:00', 'u0001', 3);
        $this->approveAs('2026-10-06 10:30', 'u0002', $third, '部長の確認をお願いします。');

        $fourth = $this->applyPlan('2026-10-10 09:00', 'u0001', 4);
        $this->sendBackToApply('2026-10-11 14:00', $fourth, '対象の資料の一覧を添えてください。');

        $fifth = $this->applyPlan('2026-10-03 09:00', 'u0001', 5, '代理店向け説明会の計画を申請します。');
        $this->sendBackToApply('2026-10-04 15:00', $fifth, '予算と終了日を再検討してください。');
        $end = SeedingDates::day('2027-04-30');
        $changed = $this->planInput(5, ['budget' => 350, 'end_date' => $end]);
        $reapplied = new ApplicationInput(null, "予算を 350 万円に、終了日を {$end} に変更しました。", $changed);
        $context = $this->contextAt('2026-10-12 10:00', 'u0001');
        $this->reapply->execute($context, $fifth, $this->revisionOf($fifth), $reapplied);

        $issue = $this->issueInput('経費の入力画面が分かりにくい', $this->projectIdOf('経費精算の電子化'), 'u0010', 'low', null);
        $sixth = $this->applyIssue('2026-09-15 09:00', 'u0010', $issue);
        $context = $this->contextAt('2026-09-16 10:00', 'u0002');
        $nodeId = $this->currentNodeIdOf($sixth);
        $this->reject->execute($context, $sixth, $this->revisionOf($sixth), $nodeId, '運用マニュアルで対応します。');

        $issue = $this->issueInput('人事の申請の入口が分かれている', $this->projectIdOf('社内ポータル刷新'), 'u0011', 'high', '2026-11-30');
        $seventh = $this->applyIssue('2026-09-20 09:00', 'u0009', $issue);
        $context = $this->contextAt('2026-09-21 10:00', 'u0002');
        $this->approveEarly->execute(
            $context, $seventh, $this->revisionOf($seventh), $this->currentNodeIdOf($seventh), '急ぎのため、課長の承認で終了します。',
        );

        $eighth = $this->applyPlan('2026-09-25 09:00', 'u0001', 8);
        $this->sendBackToApply('2026-09-26 10:00', $eighth, '開催の目的を追加してください。');
        $context = $this->contextAt('2026-09-27 11:00', 'u0001');
        $this->withdraw->execute($context, $eighth, $this->revisionOf($eighth), '今期は見送ります。');

        $draft = new DraftInput('課題の対応の承認 請求書の様式の変更', null, [
            'title' => '請求書の様式の変更', 'project' => $this->projectIdOf('経費精算の電子化'),
            'priority' => 'medium', 'status' => 'open',
        ], []);
        $this->createDraft->execute($this->contextAt('2026-10-14 17:00', 'u0001'), 'issue_resolution', $draft);
    }

    /**
     * 計画の案件を申請する(案件名は空 = 既定)。申請基準日の既定 = 2 段目の見本の日(版 2)を流す日からの相対にした日。
     */
    private function applyPlan(
        string $at,
        string $applicant,
        int $plan,
        ?string $comment = null,
        ?string $baseDate = null,
        ?CaseContact $contact = null,
    ): int {
        $baseDate ??= SeedingDates::day(self::DATES['stage2_plan_base_date']);
        $input = new ApplicationInput(null, $comment, $this->planInput($plan));
        $context = $this->contextAt($at, $applicant);

        return $this->apply->execute($context, 'project_plan', $input, $baseDate, contact: $contact)->caseId;
    }

    /**
     * 課題の案件を申請する(案件名は空 = 既定。申請基準日 = 申請の操作の日 = 設計/ワークフロー/案件の運用/初期データ.md 4.2)。
     *
     * @param  string|null  $applicant  申請の代理の代理元(null = 操作する人が申請者)
     */
    private function applyIssue(
        string $at,
        string $userCode,
        RecordInput $issue,
        ?string $comment = null,
        ?string $applicant = null,
    ): int {
        $context = $this->contextAt($at, $userCode);
        $applicantUserId = $applicant === null ? null : $this->userId($applicant);
        $input = new ApplicationInput(null, $comment, $issue);
        $baseDate = $context->date->toDateString();

        return $this->apply->execute($context, 'issue_resolution', $input, $baseDate, $applicantUserId)->caseId;
    }

    /**
     * 課題のレコードの入力(状態は未着手。期限は基準の日の値で受ける)。
     */
    private function issueInput(
        string $title,
        int $project,
        string $assignee,
        string $priority,
        ?string $due,
    ): RecordInput {
        return new RecordInput([
            'title' => $title, 'project' => $project, 'assignee' => $this->userId($assignee), 'priority' => $priority,
            'status' => 'open', 'due_date' => SeedingDates::day($due),
        ]);
    }

    /**
     * u0002(課長の承認の処理対象者)が申請のノードへ差戻す。
     */
    private function sendBackToApply(string $at, int $caseId, string $comment): void
    {
        $applyNode = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', 'apply')->sole();
        $context = $this->contextAt($at, 'u0002');
        $nodeId = $this->currentNodeIdOf($caseId);
        $this->sendBack->execute($context, $caseId, $this->revisionOf($caseId), $nodeId, $applyNode->id, $comment);
    }
}
