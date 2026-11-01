<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 権限の組み合わせごとの、申請の詳細の GET とサイドパネルの書き込み(コメントの投稿)→ 移り先の GET(docs の
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節の判定の順・3 節の総表、詳細の画面/README.md の 3 節の戻り先):
 * 業務モジュールの read だけ(申請の関係者でない)・申請の関係者(未完了・完了の後)・システム管理者・ゲスト。見本の WF-000005(未完了)と
 * WF-000001(完了)。申請書のレコードは参照制限があり、課長 u0002 は申請の経路だけで見る。投稿は申請の詳細の URL を戻り先に持つ。
 */
final class CaseShowRoleCombinationTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 未完了の申請の関係者は read と行の判定を通らなくても、詳細でレコードを見て、コメントを投稿でき、戻った詳細の欄に出る。
     */
    public function test_a_participant_of_an_active_case_posts_a_comment_and_sees_it_back_on_the_case(): void
    {
        $case = $this->caseOf(5);
        $this->actingAsUser('u0002');

        $this->assertTrue($this->recordAccessOf($case));
        $this->postComment($case, '未完了の間のコメント')->assertStatus(303)->assertRedirect($this->url($case));
        $this->assertContains('未完了の間のコメント', $this->commentBodiesOf($case));
    }

    /**
     * 完了の後の関係者は写し(recordAccess = 偽)。投稿は、read があれば 404(行の判定を通らない)、read が無ければ 403。
     */
    public function test_a_participant_of_a_completed_case_cannot_post_a_comment(): void
    {
        $case = $this->caseOf(1);
        $this->actingAsUser('u0002');

        $this->assertFalse($this->recordAccessOf($case));
        $this->postComment($case, '完了の後のコメント')->assertNotFound();
        $this->deny($this->moduleOf($case)->code, RecordModuleAction::Read);
        $this->postComment($case, '完了の後のコメント')->assertForbidden();
        $this->assertFalse($this->recordAccessOf($case));
    }

    /**
     * システム管理者は完了の後も今の内容を見て(全件の参照)、投稿でき、戻った詳細の欄に出る。
     */
    public function test_the_system_administrator_posts_a_comment_on_a_completed_case(): void
    {
        $case = $this->caseOf(1);
        $this->actingAsUser('u0004');

        $this->assertTrue($this->recordAccessOf($case));
        $this->postComment($case, '管理者のコメント')->assertStatus(303)->assertRedirect($this->url($case));
        $this->assertContains('管理者のコメント', $this->commentBodiesOf($case));
    }

    /**
     * 業務モジュールの read だけの人(申請の関係者でない)は、参照制限の無いレコードにはコメントを投稿できるが、戻り先の申請の詳細は 404。
     */
    public function test_a_reader_who_is_not_a_participant_cannot_open_the_case(): void
    {
        $case = $this->caseOf(5);
        Record::query()->whereKey($case->record_id)->update(['row_access_restricted' => false]);
        $this->actingAsUser('u0006');

        $this->get($this->url($case))->assertNotFound();
        $this->postComment($case, '業務の画面からのコメント')->assertStatus(303)->assertRedirect($this->url($case));
        $this->get($this->url($case))->assertNotFound();
    }

    /**
     * ゲストは申請を閲覧できず(404)、参照制限のあるレコードのコメントは 404(read はあるが行の判定を通らない)。
     */
    public function test_a_guest_cannot_open_the_case_nor_post_a_comment(): void
    {
        $case = $this->caseOf(5);

        $this->get($this->url($case))->assertNotFound();
        $this->postComment($case, 'ゲストのコメント')->assertNotFound();
    }

    private function caseOf(int $number): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->caseNumbered($number));
    }

    private function url(WorkflowCase $case): string
    {
        return "/workflow/cases/{$case->id}";
    }

    private function moduleOf(WorkflowCase $case): RecordModule
    {
        return RecordModule::query()->findOrFail(Record::query()->findOrFail($case->record_id)->record_module_id);
    }

    /**
     * 申請の詳細の画面からコメントを投稿する(戻り先 = 申請の詳細の URL)。
     *
     * @return TestResponse<Response>
     */
    private function postComment(WorkflowCase $case, string $body): TestResponse
    {
        $url = "/records/data/{$this->moduleOf($case)->code}/{$case->record_id}/comments";

        return $this->from($this->url($case))->post($url, ['body' => $body]);
    }

    private function recordAccessOf(WorkflowCase $case): bool
    {
        return $this->get($this->url($case))->assertOk()->viewData('page')['props']['recordAccess'];
    }

    /**
     * 戻った申請の詳細でコメントの欄を開いた中身の本文(新しい順)。
     *
     * @return list<string|null>
     */
    private function commentBodiesOf(WorkflowCase $case): array
    {
        $response = $this->get($this->url($case).'?panel=comments', [
            Header::PARTIAL_COMPONENT => 'Workflow/CaseShow',
            Header::PARTIAL_ONLY => 'panelContent',
        ])->assertOk();

        return array_column($response->viewData('page')['props']['panelContent']['rows'] ?? [], 'body');
    }
}
