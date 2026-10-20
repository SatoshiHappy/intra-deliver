<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\HoldCase;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節、同時実行と検査.md の 14.1 節)のうち、ルートの見本の
 * 合議の子の 1 つが保留(R1)と、同期の片方の枝が保留(R2)の可否の全組。期待・記号・入力は {@see ChecksRouteOperationTable} に足して:
 * E・F = 保留した人の承認の代理先(u0016・u0017)。保留した人の本人として保留し、保留者側 = 本人と E・F(本人として)。
 * 代理元として操作する役割の再申請・取止めは承認の代理が合わないので 422 principal_user_id(13 節)。成功の組は不変の条件だけ。
 */
final class CaseOperationTableHoldOnRoutesTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;
    use RegistersProxies;

    /** 保留中に保留者側ができる操作 */
    private const HOLD_SIDE_OPERATIONS = ['approve', 'approve_early', 'reject', 'send_back', 'transfer', 'release'];

    /** 申請の系統の操作を代理元(承認の代理)として送ると 422 principal_user_id */
    private const APPLY_KIND = ['reapply' => '422:principal_user_id', 'withdraw' => '422:principal_user_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return [...self::PEOPLE, ...self::ROUTE_PEOPLE, 'E' => 'u0016', 'F' => 'u0017'];
    }

    /**
     * R1 の合議の子 1(X)を X が保留(注目 = 子 1。ほかの子 = Y・Z は承認待ち)。B = 合議を展開した課長の承認(保留の行が子孫を変えたので引戻せない)。
     */
    public function test_one_council_child_held(): void
    {
        $this->addTableVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveSectionChief($case, 'XYZ');
        $this->holdAs($case, 'council.1', 'X');

        $checked = $this->assertOperationTable($case, [
            'roles' => [
                'holder' => 'X', 'holderProxy' => 'E', 'otherProxy' => 'F', 'proxySelf' => 'E',
                'otherChild' => 'Y', 'previousActor' => 'B',
            ],
            ...$this->holdSideSpec('X'),
            'visible' => 'ABEFRXYZ',
            'inputs' => $this->tableInputs($case, 'council.1', 'apply', 'council.1'),
        ]);

        $this->assertSame(12 * self::OPERATIONS, $checked);
    }

    /**
     * R2 の枝 A の a1(B)を B が保留(注目 = a1。枝 B の b1 = D は承認待ち)。申請者 A は申請を引戻せない(保留の行が子孫を変えた)。
     */
    public function test_one_sync_branch_held(): void
    {
        $this->addTableVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->holdAs($case, 'a1', 'B');

        $checked = $this->assertOperationTable($case, [
            'roles' => [
                'holder' => 'B', 'holderProxy' => 'E', 'otherProxy' => 'F', 'proxySelf' => 'E', 'otherBranch' => 'D',
            ],
            ...$this->holdSideSpec('B'),
            'visible' => 'ABDEFR',
            'inputs' => $this->tableInputs($case, 'a1', 'apply', 'a1'),
        ]);

        $this->assertSame(11 * self::OPERATIONS, $checked);
    }

    /**
     * 保留者側の 3 つの役割の成功・代理元・申請の系統の拒否(保留した人 = $holder)。
     *
     * @return array{
     *     success: array<string, array{list<string>, self::ONLY_INVARIANTS}>,
     *     principals: array<string, string>, rejections: array<string, array<string, string>>,
     * }
     */
    private function holdSideSpec(string $holder): array
    {
        return [
            'success' => array_fill_keys(
                self::HOLD_SIDE_OPERATIONS,
                [['holder', 'holderProxy', 'otherProxy'], self::ONLY_INVARIANTS],
            ),
            'principals' => ['holderProxy' => $holder, 'otherProxy' => $holder],
            'rejections' => ['holderProxy' => self::APPLY_KIND, 'otherProxy' => self::APPLY_KIND],
        ];
    }

    /**
     * $letter の人の承認の代理先を E・F にして、その人が本人として保留する。
     */
    private function holdAs(WorkflowCase $case, string $label, string $letter): void
    {
        $holder = $this->seededUser($this->codeOf($letter));
        $this->addProxy($holder, $this->seededUser('u0016'));
        $this->addProxy($holder, $this->seededUser('u0017'));
        $nodeId = $this->labelIds($case)[$label];
        app(HoldCase::class)->execute($this->contextOf($holder), $case->id, $this->revisionOf($case), $nodeId, null);
        $this->assertRouteInvariants($case);
    }
}
