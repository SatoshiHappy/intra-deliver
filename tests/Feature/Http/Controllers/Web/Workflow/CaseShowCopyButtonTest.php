<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseCopyability;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の詳細の props canCopy(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 12.2):
 * 見本の案件の未完了・完了(承認・承認終了・否認・取止め)のそれぞれで、申請者が開いた詳細に判定({@see CaseCopyability})の値で載る。
 * recordAccess が偽の詳細にも載る。部分の再読み込みでは組まない(判定を呼ばない)。
 */
final class CaseShowCopyButtonTest extends TransactionalTestCase
{
    use UsesSeededCases;

    /** 判定を呼んだ回数 */
    private int $copyJudgments = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 未完了と、完了の理由のそれぞれの見本の案件を、その申請者が開くと canCopy が判定の値で載る(どれも申請できる人なので真)。
     */
    public function test_can_copy_follows_the_judgment_on_active_and_completed_cases(): void
    {
        $reasons = [null, ...WorkflowCompletionReason::cases()];

        foreach ($reasons as $reason) {
            $case = $this->seededCaseOf($reason);
            $applicant = User::query()->findOrFail($case->user_id);
            $this->actingAs($applicant);

            $props = $this->propsOf($this->get("/workflow/cases/{$case->id}"));

            $expected = app(CaseCopyability::class)->canCopy($this->contextOf($applicant), $case->workflow_flow_id);
            $this->assertSame([true, true], [$expected, $props['canCopy']], $reason->value ?? 'active');
        }
    }

    /**
     * 申請書のレコードを申請の経路だけで見る人(recordAccess が偽)の詳細にも載る。申請できない人には偽。
     */
    public function test_can_copy_is_given_even_when_the_record_is_masked(): void
    {
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(1));
        $this->actingAsUser('u0002');

        $props = $this->propsOf($this->get("/workflow/cases/{$case->id}"));

        $this->assertFalse($props['recordAccess']);
        $this->assertTrue($props['canCopy']);

        $this->forbidCopy();
        $this->assertFalse($this->propsOf($this->get("/workflow/cases/{$case->id}"))['canCopy']);
    }

    /**
     * 部分の再読み込み(詳細タブ・サイドパネル)では canCopy を返さず、判定も呼ばない。全体の応答は 1 回呼ぶ。
     */
    public function test_partial_reloads_do_not_build_can_copy(): void
    {
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $this->actingAsUser('u0002');
        $this->countCopyJudgments();

        foreach (['content', 'panelContent', 'related'] as $only) {
            $response = $this->get("/workflow/cases/{$case->id}?panel=comments", [
                Header::PARTIAL_COMPONENT => 'Workflow/CaseShow',
                Header::PARTIAL_ONLY => $only,
            ]);

            $this->assertArrayNotHasKey('canCopy', $this->propsOf($response), $only);
        }

        $this->assertSame(0, $this->copyJudgments);
        $this->get("/workflow/cases/{$case->id}")->assertOk();
        $this->assertSame(1, $this->copyJudgments);
    }

    /**
     * 見本の案件(一時保存でない)のうち、未完了(null)か渡した完了の理由の最初のもの。
     */
    private function seededCaseOf(?WorkflowCompletionReason $reason): WorkflowCase
    {
        $query = WorkflowCase::query()->where('state', '<>', WorkflowCaseState::Draft)->orderBy('case_number');
        $reason === null
            ? $query->where('state', WorkflowCaseState::Active)
            : $query->where('completion_reason', $reason);

        return $query->firstOrFail();
    }

    private function contextOf(User $user): AccessContext
    {
        return new AccessContext($user->id, null, null, CarbonImmutable::today(), 'ja');
    }

    /**
     * 判定を呼ぶたびに {@see self::$copyJudgments} を足す(判定の値は本物のまま)。
     */
    private function countCopyJudgments(): void
    {
        $real = app(CaseCopyability::class);
        $counted = function (): void {
            $this->copyJudgments++;
        };
        app()->instance(CaseCopyability::class, new class($real, $counted) extends CaseCopyability
        {
            public function __construct(private CaseCopyability $real, private Closure $counted) {}

            public function canCopy(AccessContext $context, int $flowId): bool
            {
                ($this->counted)();

                return $this->real->canCopy($context, $flowId);
            }
        });
    }

    /**
     * 新規の申請の画面のルートの許可を消す(ルートの認可が偽 = 12.2 節の表の最後の行)。
     */
    private function forbidCopy(): void
    {
        $route = 'http-services-unmanaged.workflow.applications.create';
        $groupId = AuthzResourceGroup::query()->where('code', $route)->value('id');
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->delete();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function propsOf(TestResponse $response): array
    {
        return $response->assertOk()->viewData('page')['props'];
    }
}
