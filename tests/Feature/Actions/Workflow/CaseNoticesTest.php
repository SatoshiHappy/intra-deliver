<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\TransferCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowCase;
use Closure;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の操作が作る通知(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節の「通知」と 11 節、設計/ワークフロー/案件の運用/通知.md の 3 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.4 節)と操作の結果の 2 項目。ルートの見本 R1(予算 100)の課長に確認する人 u0010 を付けた版で、申請者 u0001、課長 u0002、
 * 合議 u0005・u0009・u0011(ステップ名は親の「関係部署の合議」)、責任者 u0007。期待は 設計/ワークフロー/案件の運用/通知.md の 3 節の表から書いた。
 */
final class CaseNoticesTest extends TransactionalTestCase
{
    use OperatesOnRoutes;
    use ReadsCaseNotices;
    use RegistersProxies;

    private const PROCESS = WorkflowNoticeType::ProcessRequest;

    private const RESULT = WorkflowNoticeType::Result;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
        $this->addVersion($this->r1($this->confirmGroup('u0010')));
    }

    /**
     * 申請 → 課長への処理依頼。宛先 = 増えた人と、その人を代理元とする今日に有効で承認の代理の代理先(別・期間・有効性の合わない代理先は入らない)。
     */
    public function test_applying_requests_the_first_step_with_its_proxies(): void
    {
        $chief = $this->seededUser('u0002');
        $this->addProxy($chief, $this->seededUser('u0016'));
        $this->addProxy($chief, $this->seededUser('u0014'));
        $this->addProxy($chief, $this->seededUser('u0017'), WorkflowProxyKind::Apply);
        $this->addProxy($chief, $this->seededUser('u0012'), start: '2026-10-14');
        $lastId = $this->lastNoticeId();

        [$case, $result] = $this->applyOnRoute(['budget' => 100]);

        $request = ['step_name' => '課長', 'step_kind' => 'approve'];
        $this->assertSame([
            $this->caseNotice(self::PROCESS, 'u0002', 'u0001', $case, $request),
            $this->caseNotice(self::PROCESS, 'u0016', 'u0001', $case, $request),
        ], $this->noticesSince($lastId));
        $this->assertSame([$this->labelIds($case)['section_chief'] => [$chief->id]], $result->targetAdditions);
    }

    /**
     * 課長の承認 → 合議の子ごとの処理依頼(ステップ名は親の名前)と、確認依頼(確認対象者だけ。確認対象者の代理先は入らない)。
     */
    public function test_approving_requests_each_council_child_and_the_confirmation(): void
    {
        $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0016'));
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $lastId = $this->lastNoticeId();

        $result = $this->approveChief($case);

        $council = ['step_name' => '関係部署の合議', 'step_kind' => 'approve'];
        $this->assertSame(self::sortedLines([
            $this->caseNotice(WorkflowNoticeType::ConfirmRequest, 'u0010', 'u0002', $case, ['step_name' => '課長']),
            $this->caseNotice(self::PROCESS, 'u0005', 'u0002', $case, $council),
            $this->caseNotice(self::PROCESS, 'u0009', 'u0002', $case, $council),
            $this->caseNotice(self::PROCESS, 'u0011', 'u0002', $case, $council),
        ]), $this->noticesSince($lastId));
        $ids = $this->labelIds($case);
        $people = fn (string $code) => [$this->seededUser($code)->id];
        $expected = [
            $ids['council.1'] => $people('u0005'),
            $ids['council.2'] => $people('u0009'),
            $ids['council.3'] => $people('u0011'),
        ];
        $this->assertSame($expected, $result->targetAdditions);
    }

    /**
     * 合議の票: 1 票目は通知なし、可決の票で責任者へ処理依頼。最後の承認で申請者へ処理結果(承認)。
     */
    public function test_council_votes_and_the_last_approval(): void
    {
        $case = $this->caseAtCouncil();

        $this->assertSame([], $this->noticesOf(fn () => $this->approve($case, 'council.1', 'u0005')));
        $this->assertSame(
            [$this->caseNotice(self::PROCESS, 'u0007', 'u0009', $case, self::step('責任者'))],
            $this->noticesOf(fn () => $this->approve($case, 'council.2', 'u0009')),
        );
        $this->assertSame(
            [$this->caseNotice(self::RESULT, 'u0001', 'u0007', $case, ['completion_reason' => 'approved'])],
            $this->noticesOf(fn () => $this->approve($case, 'owner', 'u0007')),
        );
    }

    /**
     * 差戻し → 戻した先への処理依頼。差戻しの引戻し → 写しから戻した合議の子のうち、処理者でない人への処理依頼。
     */
    public function test_send_back_and_pull_back_request_the_returned_steps(): void
    {
        $case = $this->caseAtCouncil();

        $this->assertSame(
            [$this->caseNotice(self::PROCESS, 'u0002', 'u0005', $case, self::step('課長'))],
            $this->noticesOf(fn () => $this->sendBackOn($case, 'council.1', 'u0005', 'section_chief')),
        );

        $council = ['step_name' => '関係部署の合議', 'step_kind' => 'approve'];
        $this->assertSame([
            $this->caseNotice(self::PROCESS, 'u0009', 'u0005', $case, $council),
            $this->caseNotice(self::PROCESS, 'u0011', 'u0005', $case, $council),
        ], $this->noticesOf(fn () => $this->pullBackOn($case, 'council.1', 'u0005')));
    }

    /**
     * 申請へ差戻す → 申請者への処理依頼(再申請)。再申請 → 課長への処理依頼。
     */
    public function test_send_back_to_the_application_and_reapply(): void
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $this->assertSame(
            [$this->caseNotice(self::PROCESS, 'u0001', 'u0002', $case, ['step_name' => '申請', 'step_kind' => 'apply'])],
            $this->noticesOf(fn () => $this->sendBackOn($case, 'section_chief', 'u0002', 'apply')),
        );
        $reapply = fn () => app(ReapplyCase::class)->execute(
            $this->contextOf($this->seededUser('u0001')),
            $case->id,
            $this->revisionOf($case),
            $this->projectInput(['budget' => 100]),
        );
        $this->assertSame(
            [$this->caseNotice(self::PROCESS, 'u0002', 'u0001', $case, self::step('課長'))],
            $this->noticesOf($reapply),
        );
    }

    /**
     * 振替 → 振替先とその代理先への担当変更(コメントを持つ)。結果の処理対象者が増えたノード = 振替先だけ。
     */
    public function test_transfer_notifies_the_new_target_and_its_proxies(): void
    {
        $this->addProxy($this->seededUser('u0006'), $this->seededUser('u0017'));
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $chiefId = $this->labelIds($case)['section_chief'];
        $result = null;
        $transfer = function () use ($case, $chiefId, &$result): void {
            $context = $this->contextOf($this->seededUser('u0002'));
            $to = $this->seededUser('u0006')->id;
            $revision = $this->revisionOf($case);
            $result = app(TransferCase::class)->execute($context, $case->id, $revision, $chiefId, $to, '不在のため');
        };

        $params = ['step_name' => '課長', 'step_kind' => 'approve', 'comment' => '不在のため'];
        $this->assertSame([
            $this->caseNotice(WorkflowNoticeType::Transfer, 'u0006', 'u0002', $case, $params),
            $this->caseNotice(WorkflowNoticeType::Transfer, 'u0017', 'u0002', $case, $params),
        ], $this->noticesOf($transfer));
        $this->assertInstanceOf(CaseOperationResult::class, $result);
        $this->assertSame([$chiefId => [$this->seededUser('u0006')->id]], $result->targetAdditions);
    }

    /**
     * 処理結果は申請者だけ(完了の理由ごと)。完了した案件では確認は開かない(確認依頼も無い)。取止めは申請者が処理者なので通知が無い。
     */
    public function test_results_go_to_the_applicant_for_each_completion_reason(): void
    {
        [$early] = $this->applyOnRoute(['budget' => 100]);
        $result = null;
        $lines = $this->noticesOf(function () use ($early, &$result): void {
            $result = $this->operateOn(ApproveCaseEarly::class, $early, 'section_chief', 'u0002');
        });
        $reason = ['completion_reason' => 'approved_early'];
        $expected = [$this->caseNotice(self::RESULT, 'u0001', 'u0002', $early, $reason)];
        $this->assertSame($expected, $lines);
        $this->assertInstanceOf(CaseOperationResult::class, $result);
        $this->assertSame([[], []], [$result->openedConfirmations, $result->targetAdditions]);

        [$rejected] = $this->applyOnRoute(['budget' => 100]);
        $this->assertSame(
            [$this->caseNotice(self::RESULT, 'u0001', 'u0002', $rejected, ['completion_reason' => 'rejected'])],
            $this->noticesOf(fn () => $this->reject($rejected, 'section_chief', 'u0002')),
        );

        $council = $this->caseAtCouncil();
        $this->reject($council, 'council.1', 'u0005');
        $this->assertSame(
            [$this->caseNotice(self::RESULT, 'u0001', 'u0009', $council, ['completion_reason' => 'rejected'])],
            $this->noticesOf(fn () => $this->reject($council, 'council.2', 'u0009')),
        );

        [$withdrawn] = $this->applyOnRoute(['budget' => 100]);
        $this->sendBackOn($withdrawn, 'section_chief', 'u0002', 'apply');
        $withdraw = fn () => $this->operate(WithdrawCase::class, $this->seededUser('u0001'), $withdrawn);
        $this->assertSame([], $this->noticesOf($withdraw));
    }

    /**
     * R1 を申請し、課長が合議と責任者を指定して承認した案件。
     */
    private function caseAtCouncil(): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveChief($case);

        return $case;
    }

    private function approveChief(WorkflowCase $case): CaseOperationResult
    {
        $designation = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];

        return $this->approve($case, 'section_chief', 'u0002', $designation);
    }

    /**
     * 承認のステップの処理依頼の文の値。
     *
     * @return array{step_name: string, step_kind: string}
     */
    private static function step(string $name): array
    {
        return ['step_name' => $name, 'step_kind' => 'approve'];
    }

    /**
     * @param  Closure(): mixed  $operate
     * @return list<array{string, string, string|null, string|null, array<string, mixed>}> 操作でできた通知の行
     */
    private function noticesOf(Closure $operate): array
    {
        $lastId = $this->lastNoticeId();
        $operate();

        return $this->noticesSince($lastId);
    }
}
