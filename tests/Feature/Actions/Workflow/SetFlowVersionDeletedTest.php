<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\SetFlowVersionDeleted;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 版の無効・有効(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 1 節・8 節の版のテスト)。期待の文言は 6 節の表から書いた。
 * テストのフローの版: 版 1 = 2025-04-01〜2026-01-01、版 2 = R3(2026-01-01〜)。操作の基準日(今日)= 2026-10-13(版 2 の中)。
 */
final class SetFlowVersionDeletedTest extends TransactionalTestCase
{
    use BuildsRouteVersions;
    use CatchesValidationErrors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addVersion($this->r3());
    }

    /**
     * 無効・有効にし(期間は変えない)、無効の版を無効にする・有効の版を有効にするのは 422(定義の管理.md の 1 節・6 節)。
     */
    public function test_disables_and_enables_a_version_and_rejects_the_same_state(): void
    {
        $this->assertSame(['version' => 'この版は既に有効です'], $this->errors(1, false));
        $this->assertSame([[false, '2026-01-01'], [false, null]], $this->states());

        $this->set(1, true);
        $this->assertSame([[true, '2026-01-01'], [false, null]], $this->states());
        $this->assertSame(['version' => 'この版は既に無効です'], $this->errors(1, true));
        $this->assertSame([[true, '2026-01-01'], [false, null]], $this->states());

        $this->set(1, false);
        $this->assertSame([[false, '2026-01-01'], [false, null]], $this->states());
    }

    /**
     * フローが無い・フローに無い番号(別のフローにはある番号を含む)は見つからない(定義の管理.md の 8 節)。
     */
    public function test_a_missing_flow_or_number_is_not_found(): void
    {
        $this->createFlow('other_flow', '別のフロー');
        $this->addVersion($this->r3(), '2026-02-01');
        $this->versionIdOf(3);

        foreach ([['no_such_flow', 1], [self::FLOW_CODE, 4], ['other_flow', 3]] as [$flowCode, $number]) {
            foreach ([true, false] as $isDeleted) {
                $action = fn () => app(SetFlowVersionDeleted::class)->execute($flowCode, $number, $isDeleted);
                $this->assertThrows($action, ModelNotFoundException::class);
            }
        }
    }

    /**
     * 無効にした版の期間の申請基準日では申請が 422 base_date、有効に戻すとその版で申請できる。
     */
    public function test_a_disabled_version_cannot_be_applied_on_its_period_until_enabled(): void
    {
        $this->set(1, true);
        $counts = WorkflowCase::query()->count();

        $errors = $this->validationErrors(fn () => $this->apply());
        $this->assertSame(['base_date' => '申請基準日 2025-12-01 に申請できる版がありません'], $errors);
        $this->assertSame($counts, WorkflowCase::query()->count());

        $this->set(1, false);
        $case = WorkflowCase::query()->findOrFail($this->apply());
        $this->assertSame($this->versionIdOf(1), $case->workflow_flow_version_id);
    }

    private function set(int $number, bool $isDeleted): void
    {
        app(SetFlowVersionDeleted::class)->execute(self::FLOW_CODE, $number, $isDeleted);
    }

    /**
     * @return array<string, string>
     */
    private function errors(int $number, bool $isDeleted): array
    {
        return $this->validationErrors(fn () => $this->set($number, $isDeleted));
    }

    /**
     * u0001 が申請基準日 2025-12-01(版 1 の期間)で申請し、案件の id を返す。
     */
    private function apply(): int
    {
        $context = $this->contextOf($this->seededUser('u0001'));

        return app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), '2025-12-01')->caseId;
    }

    private function versionIdOf(int $number): int
    {
        return $this->versions()->where('number', $number)->sole()->id;
    }

    /**
     * テストのフローの版: [無効か, 終了日](番号の順)。
     *
     * @return list<array{bool, string|null}>
     */
    private function states(): array
    {
        return array_values($this->versions()->orderBy('number')->get()->map(fn (WorkflowFlowVersion $version) => [
            $version->is_deleted, $version->end_date?->toDateString(),
        ])->all());
    }

    /**
     * @return Builder<WorkflowFlowVersion>
     */
    private function versions(): Builder
    {
        return WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', self::FLOW_CODE);
    }
}
