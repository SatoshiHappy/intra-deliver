<?php

namespace Database\Seeders;

use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\ProxyInput;
use App\Models\Workflow\WorkflowCase;

/**
 * {@see WorkflowSeeder} の案件の運用の見本(docs の 設計/ワークフロー/案件の運用/初期データ.md 4.1・4.3): 代理 4、案件 4(保留・連絡・申請の代理・担当変更)、
 * u0010 の確認。時刻は基準の日に書いた値(操作ごとに時計を合わせる = 同じ文書の 1.2)。使う側は代理の登録・保留・振替・確認の業務処理と、
 * applyPlan()・applyIssue()・issueInput()・approveAs()・contextAt()・targetNodeIdOf()・revisionOf()・projectIdOf()・
 * seedingDay()・userId() を持つ。
 */
trait SeedsWorkflowOperationSamples
{
    /** 代理(4.1。登録の時刻の順): [登録の時刻, 登録した人, 代理元, 代理先, 別, 対象のフロー(null = すべて), 開始日, 最後の有効な日(null = 終了日なし)] */
    private const PROXIES = [
        ['2026-08-30 09:00', 'u0010', 'u0010', 'u0002', 'approve', 'issue_resolution', '2026-08-30', '2026-09-28'],
        ['2026-09-29 10:00', 'u0010', 'u0010', 'u0009', 'apply', null, '2026-09-29', null],
        ['2026-10-08 11:00', 'u0004', 'u0016', 'u0003', 'approve', 'project_plan', '2026-10-08', '2026-10-18'],
        ['2026-10-13 09:05', 'u0003', 'u0003', 'u0009', 'approve', null, '2026-10-13', null],
    ];

    /**
     * 代理 4 行を登録の時刻の順に登録する(登録した人の判定の入力で。代理の管理の行は代理元と登録した人が違う)。
     */
    private function createProxies(): void
    {
        foreach (self::PROXIES as [$at, $registeredBy, $principal, $proxy, $kind, $flowCode, $start, $end]) {
            $period = [SeedingDates::day($start), SeedingDates::day($end)];
            $input = new ProxyInput($this->userId($proxy), $kind, $flowCode, ...$period);
            $this->registerProxy->execute($this->contextAt($at, $registeredBy), $this->userId($principal), $input);
        }
    }

    /**
     * 案件の見本 4(4.3。番号は申請の順に続く = WF-000013〜16)。計画の申請基準日 = 流す日(版 3)、課題 = 申請の操作の日(版 1)。
     */
    private function createOperationCases(): void
    {
        $base = $this->seedingDay()->toDateString();
        $thirteenth = $this->applyPlan('2026-10-06 09:20', 'u0001', 13, '新製品の発売計画を申請します。', $base);
        $this->approveAs('2026-10-06 10:30', 'u0002', $thirteenth, '内容を確認しました。', ['u0009', 'u0010', 'u0016']);
        $managerNodeId = $this->targetNodeIdOf($thirteenth, 'u0003');
        $this->hold->execute(
            $this->contextAt('2026-10-14 16:20', 'u0009'), $thirteenth, $this->revisionOf($thirteenth), $managerNodeId,
            '取引先の見積りが届くまで保留します。', $this->userId('u0003'),
        );

        $contact = new CaseContact([$this->userId('u0012')], '来週から販促の準備を始めます。ご確認ください。');
        $fourteenth = $this->applyPlan('2026-10-12 09:00', 'u0001', 14, null, $base, $contact);
        $this->approveAs('2026-10-13 10:00', 'u0002', $fourteenth, null, ['u0009', 'u0010']);

        $issue = $this->issueInput('在庫システムの表示不具合', $this->projectIdOf('経費精算の電子化'), 'u0010', 'high', '2026-10-31');
        $this->applyIssue('2026-10-10 09:00', 'u0009', $issue, '在庫システムの不具合の対応を、山田 恵さんの代理で申請します。', 'u0010');

        $issue = $this->issueInput('ログイン画面の文言修正', $this->projectIdOf('社内ポータル刷新'), 'u0011', 'medium', null);
        $sixteenth = $this->applyIssue('2026-10-09 09:00', 'u0001', $issue);
        $chiefNodeId = $this->targetNodeIdOf($sixteenth, 'u0002');
        $this->transfer->execute(
            $this->contextAt('2026-10-10 15:20', 'u0002'), $sixteenth, $this->revisionOf($sixteenth), $chiefNodeId,
            $this->userId('u0009'), '総務部の確認が必要な内容のため、担当を変更します。',
        );
    }

    /**
     * u0010 が WF-000009 を確認する(4.3 の下の箇条。自分の開いている確認をすべて確認するので、課長と部長の両方が確認済になる)。
     */
    private function confirmSamples(): void
    {
        $ninth = WorkflowCase::query()->where('case_number', 9)->sole()->id;
        $this->confirm->execute($this->contextAt('2026-10-14 10:20', 'u0010'), $ninth);
    }
}
