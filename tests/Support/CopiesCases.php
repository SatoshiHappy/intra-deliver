<?php

namespace Tests\Support;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordRowAccessViewer;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 案件からの複製のテストの手助け(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節)。
 * 元の申請は写しのフロー({@see BuildsApplicationTargetCopies})で本物の業務処理を通して作り、開く画面と送る値は HTTP で通す。
 * {@see AppliesToTargets}・{@see RunsWorkflowOperations}・{@see RegistersProxies} と使う。
 */
trait CopiesCases
{
    /** 案件からの複製の暗号化した値の入力のキー */
    private const COPY_KEY = 'copy_row_access';

    /** 新規の申請・一時保存の新規の入口 */
    private const APPLY_URL = '/workflow/flows/'.self::COPY_FLOW.'/apply';

    private const DRAFT_URL = '/workflow/flows/'.self::COPY_FLOW.'/drafts';

    /**
     * 申請の画面の URL(クエリ copy と足すクエリ)。
     *
     * @param  array<string, string>  $query
     */
    protected function copyUrl(int|string $caseId, array $query = []): string
    {
        return self::APPLY_URL.'?'.http_build_query(['copy' => (string) $caseId, ...$query]);
    }

    /**
     * 渡した人が開いた案件からの複製の画面の props(200 を確かめる)。
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    protected function copyPropsAs(User $user, int $caseId, array $query = []): array
    {
        $this->actingAs($user);

        return $this->pagePropsOf($this->get($this->copyUrl($caseId, $query))->assertOk());
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function copyStatusAs(User $user, int|string $caseId, array $query = []): int
    {
        $this->actingAs($user);

        return $this->get($this->copyUrl($caseId, $query))->status();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    protected function pagePropsOf(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    /**
     * 元の申請(写しのフローで新規に申請した案件)。参照制限は申請の版の写しの row_access を書き換えて作る(null = 記録の無い版)。
     *
     * @param  array<string, mixed>  $values
     * @param  array{restricted: bool, viewers: list<array{type: string, id: int}>}|null  $rowAccess
     */
    protected function sourceCase(
        User $applicant,
        array $values = [],
        ?array $rowAccess = ['restricted' => false, 'viewers' => []],
    ): WorkflowCase {
        $case = $this->applyNew($applicant, null, $values);
        $this->setRevisionRowAccess($this->submittedRevisionOf($case), $rowAccess);

        return $case;
    }

    /**
     * 最後の申請・再申請の履歴の行が指す版。
     */
    protected function submittedRevisionOf(WorkflowCase $case): int
    {
        return (int) WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)
            ->whereIn('action', [WorkflowAction::Apply, WorkflowAction::Reapply])->orderByDesc('id')
            ->value('record_revision_id');
    }

    /**
     * 版の写しの row_access を書き換える(テストの前提を作るだけ。null = キーを消す)。
     *
     * @param  array{restricted: bool, viewers: list<array{type: string, id: int}>}|null  $rowAccess
     */
    protected function setRevisionRowAccess(int $revisionId, ?array $rowAccess): void
    {
        $rowAccess === null
            ? DB::update("update record_revisions set snapshot = snapshot - 'row_access' where id = ?", [$revisionId])
            : DB::update(
                "update record_revisions set snapshot = jsonb_set(snapshot, '{row_access}', ?::jsonb) where id = ?",
                [json_encode($rowAccess, JSON_THROW_ON_ERROR), $revisionId],
            );
    }

    /**
     * 開いた画面の値をそのまま送る申請の入力(申請名・写した値・暗号化した値。明細は写した行)。
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sentFrom(array $props, array $overrides = []): array
    {
        $values = [];

        foreach ($props['initial']['values'] as $code => $value) {
            $values[$code] = is_array($value) && array_key_exists('id', $value) ? $value['id'] : $value;
        }

        return [
            'name' => $props['initial']['name'],
            'comment' => null,
            'values' => ['title' => '写した後の題', ...array_intersect_key($values, ['title' => 0, 'amount' => 0])],
            'details' => [],
            'base_date' => $props['baseDate'],
            self::COPY_KEY => $props['copy']['rowAccess'],
            ...$overrides,
        ];
    }

    /**
     * 新規の申請の POST(申請者の代理元を渡せる)。作った案件。
     *
     * @param  array<string, mixed>  $input
     */
    protected function postApply(User $actor, array $input, ?User $principal = null): WorkflowCase
    {
        $this->actingAs($actor);
        $before = (int) WorkflowCase::query()->max('id');
        $this->post(self::APPLY_URL, [...$input, 'applicant_user_id' => $principal?->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        return WorkflowCase::query()->where('id', '>', $before)->sole();
    }

    /**
     * 一時保存の新規の POST。作った一時保存。
     *
     * @param  array<string, mixed>  $input
     */
    protected function postDraft(User $actor, array $input, ?User $principal = null): WorkflowCase
    {
        $this->actingAs($actor);
        $before = (int) WorkflowCase::query()->max('id');
        $this->post(self::DRAFT_URL, [...$input, 'applicant_user_id' => $principal?->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        return WorkflowCase::query()->where('id', '>', $before)->sole();
    }

    /**
     * 一時保存からの申請の POST(一時保存の今の改訂番号で)。
     *
     * @param  array<string, mixed>  $input
     */
    protected function postDraftApply(User $actor, WorkflowCase $draft, array $input): WorkflowCase
    {
        $this->actingAs($actor);
        $revision = $this->revisionOf($draft);
        $this->post("/workflow/drafts/{$draft->id}/apply", [...$input, 'revision' => $revision])
            ->assertSessionHasNoErrors()->assertRedirect();

        return WorkflowCase::query()->findOrFail($draft->id);
    }

    /**
     * 一時保存の入力のサーバーだけが書くキーの値(キーの並びをそろえる。キーが無ければ「無い」の印 = false)。
     */
    protected function draftCopied(WorkflowCase $draft): mixed
    {
        $input = WorkflowCase::query()->findOrFail($draft->id)->draft_input ?? [];

        return array_key_exists('copied_row_access', $input) ? $this->sortedKeys($input['copied_row_access']) : false;
    }

    /**
     * 直接の申請か、一時保存してからの申請。
     *
     * @param  array<string, mixed>  $sent
     */
    protected function sendCopy(string $route, User $actor, array $sent, ?User $principal = null): WorkflowCase
    {
        if ($route === 'new') {
            return $this->postApply($actor, $sent, $principal);
        }

        $draft = $this->postDraft($actor, $sent, $principal);

        return $this->postDraftApply($actor, $draft, [...$sent, self::COPY_KEY => null]);
    }

    /**
     * レコードのチェックと参照者(並びのまま)。
     *
     * @return array{bool, list<array{type: string, id: int}>}
     */
    protected function viewersOf(int $recordId): array
    {
        $record = Record::query()->findOrFail($recordId);
        $party = fn (RecordRowAccessViewer $row) => $row->user_id === null
            ? ['type' => 'department', 'id' => (int) $row->department_id]
            : ['type' => 'user', 'id' => (int) $row->user_id];
        $viewers = $record->rowAccessViewers()->orderBy('sort_key')->get()->map($party);

        return [$record->row_access_restricted, array_values($viewers->all())];
    }
}
