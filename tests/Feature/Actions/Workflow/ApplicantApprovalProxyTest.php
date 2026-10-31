<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 申請者承認防止の代理の項(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節・14.3 節の「代理 × 申請者承認防止」)。申請者 A = u0001・
 * A の代理先 B = u0002・第三者 C = u0003(A は C の代理先)。防止オンのステップ「営業」の処理対象者 = 東京営業課(A・B)と C。
 * 表の 5 行 × 承認・承認終了・否認: 拒む 4 行は 422 case(処理者か実行者が申請者なら申請者本人の文、ほかは申請者の代理先の文)、C 本人は通す。
 * 差戻しは拒まない。申請の代理先も申請者の代理先に当たる。到達の展開が申請者と申請者の代理先だけなら 422 case で何も変わらない。
 * 代理の項は今日を時計から読むので、時計を操作の基準日に合わせる。
 */
final class ApplicantApprovalProxyTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use CatchesValidationErrors;
    use RegistersProxies;
    use RunsRoutePlans;

    private const APPLICANT = '申請者本人は、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    private const PROXY = '申請者の代理先に設定されているため、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    public function test_the_five_rows_of_the_table_for_approve_approve_early_and_reject(): void
    {
        [$a, $b, $c] = $this->seededUsers('u0001', 'u0002', 'u0003');
        $this->addSalesRoute();
        $this->addProxy($a, $b);
        $this->addProxy($c, $a);
        $case = $this->apply();
        $rows = [
            'A 本人' => [$a, null],
            'B が A として' => [$b, $a->id],
            'B が本人として' => [$b, null],
            'A が C として' => [$a, $c->id],
            'C 本人' => [$c, null],
        ];
        $operations = [ApproveCase::class, ApproveCaseEarly::class, RejectCase::class];
        $actual = [];

        foreach ($operations as $operation) {
            foreach ($rows as $label => [$user, $principalUserId]) {
                $nodeId = $this->salesNodeId($case);
                $arguments = [$this->contextOf($user), $case->id, $this->revisionOf($case), $nodeId, null];
                // 承認だけが指定を処理担当者の前に受ける(業務処理ごとの引数の並び)
                $actual[$operation][$label] = $this->outcome(fn () => match ($operation) {
                    ApproveCase::class => app(ApproveCase::class)->execute(...[...$arguments, [], $principalUserId]),
                    ApproveCaseEarly::class => app(ApproveCaseEarly::class)
                        ->execute(...[...$arguments, $principalUserId]),
                    RejectCase::class => app(RejectCase::class)->execute(...[...$arguments, $principalUserId]),
                });
            }
        }

        $expected = [
            'A 本人' => self::APPLICANT,
            'B が A として' => self::APPLICANT,
            'B が本人として' => self::PROXY,
            'A が C として' => self::APPLICANT,
            'C 本人' => 'success',
        ];
        $this->assertSame(array_fill_keys($operations, $expected), $actual);
    }

    /**
     * 申請者の代理先 B が本人として差戻すのは拒まない。申請の代理先(別が申請)も申請者の代理先として承認を拒まれる。
     */
    public function test_send_back_is_not_rejected_and_an_apply_proxy_is_also_a_proxy_of_the_applicant(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $this->addSalesRoute();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $case = $this->apply();
        $context = $this->contextOf($b);
        $nodeId = $this->salesNodeId($case);

        $approved = $this->outcome(fn () => app(ApproveCase::class)
            ->execute($context, $case->id, $this->revisionOf($case), $nodeId, null));
        $applyNodeId = $this->labelIds($case)['apply'];
        app(SendBackCase::class)->execute($context, $case->id, $this->revisionOf($case), $nodeId, $applyNodeId, null);

        $this->assertSame(self::PROXY, $approved);
        $this->assertSame('reapply[u0001]', $this->shape($case)['apply']);
    }

    /**
     * 到達するノードの展開の結果が申請者(u0001)と今日の申請者の代理先(u0005)だけなら、到達を起こす承認が 422 case
     * (代理先を含む文)で何も書かない。
     */
    public function test_reaching_a_node_only_of_the_applicant_and_its_proxy_is_rejected(): void
    {
        [$a, $proxy] = $this->seededUsers('u0001', 'u0005');
        $only = new RouteNodeInput(
            'only',
            '申請者と代理先',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0001'), $this->userCondition('u0005')],
            ['ja' => '申請者と代理先'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $first = $this->approveNode('first', 'u0002', ['apply']);
        $this->addVersion([[$this->applyNode(), $first, $only], $this->chain(['apply', 'first'], ['first', 'only'])]);
        $this->addProxy($a, $proxy, WorkflowProxyKind::Apply);
        $case = $this->apply();
        $snapshot = fn () => [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $before = $snapshot();
        $context = $this->contextOf($this->seededUser('u0002'));

        $errors = $this->validationErrors(fn () => app(ApproveCase::class)
            ->execute($context, $case->id, $this->revisionOf($case), $this->labelIds($case)['first'], null));

        $this->assertSame(['case' => '申請者と代理先の処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。'
            .'このステップには、申請者承認防止が設定されています。'], $errors);
        $this->assertSame($before, $snapshot());
    }

    /**
     * 防止オンの指定されるステップに今日の申請者の代理先を指定すると 422 assignments.<Y の id>(13 節の代理先の文)。
     * 申請者本人は申請者本人の文のまま。
     */
    public function test_designating_a_proxy_of_the_applicant_on_a_preventing_node_is_rejected(): void
    {
        [$a, $proxy] = $this->seededUsers('u0001', 'u0005');
        $pick = new RouteNodeInput(
            'pick',
            '指定',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Designated,
            designatorCode: 'chief',
            minAssignees: 1,
            maxAssignees: 1,
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $chief = $this->approveNode('chief', 'u0002', ['apply']);
        $this->addVersion([[$this->applyNode(), $chief, $pick], $this->chain(['apply', 'chief'], ['chief', 'pick'])]);
        $this->addProxy($a, $proxy);
        $case = $this->apply();
        $ids = $this->labelIds($case);
        $context = $this->contextOf($this->seededUser('u0002'));
        $designate = fn (User $user) => $this->validationErrors(fn () => app(ApproveCase::class)
            ->execute($context, $case->id, $this->revisionOf($case), $ids['chief'], null, [
                $ids['pick'] => [$user->id],
            ]));
        $key = "assignments.{$ids['pick']}";

        $this->assertSame([$key => '申請者の代理先は、このステップの承認者に選択できません'], $designate($proxy));
        $this->assertSame([$key => '申請者本人は、このステップの承認者に選択できません'], $designate($a));
    }

    /**
     * 操作を savepoint の中で行い、結果(success か 422 case の文言)を返して巻き戻す。
     *
     * @param  callable(): mixed  $operate
     */
    private function outcome(callable $operate): string
    {
        DB::beginTransaction();

        try {
            $operate();

            return 'success';
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            return array_keys($errors) === ['case']
                ? (string) $errors['case'][0]
                : (string) json_encode($errors, JSON_UNESCAPED_UNICODE);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * 申請 → 営業(東京営業課と u0003。防止オン)→ final(u0010)の版を足す。
     */
    private function addSalesRoute(): void
    {
        $sales = new RouteNodeInput(
            'sales',
            '営業',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [
                new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales')),
                $this->userCondition('u0003'),
            ],
            ['ja' => '営業'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'sales']);
        $this->addVersion([[$this->applyNode(), $sales, $final], $this->chain(['apply', 'sales'], ['sales', 'final'])]);
    }

    private function salesNodeId(WorkflowCase $case): int
    {
        return $this->labelIds($case)['sales'];
    }

    private function apply(): WorkflowCase
    {
        $context = $this->contextOf($this->seededUser('u0001'));
        $baseDate = $this->operationDay()->toDateString();
        $result = app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), $baseDate);

        return WorkflowCase::query()->findOrFail($result->caseId);
    }
}
