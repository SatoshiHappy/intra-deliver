<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * フローを、版 1(開始日から上限なし)と一本道のルートつきで作る(docs の 設計/ワークフロー/データモデル/表.md 2.4、ルートの深さ/データモデル/定義の表.md 2.1・2.6 の
 * 「フローを作る業務処理は、版 1 を同じトランザクションで作る」。呼び手は初期データ = 初期データ.md 3 節)。コンテンツはコードで指す。
 * ルート = ノードの並び(ノードごとに処理対象者の専用の対象者を作る。辺・差戻しできる先は {@see LinearRouteWriter} が段 2 の振る舞いの形にする)か、
 * 既存のフローのコード(そのフローの版 1 のルートの複製 = {@see RouteVersionWriter::copy()}。同じ対象者を指す)。どちらも定義の検査を通す。
 * 参照者 = 条件が null なら無し(authz_subject_group_id = NULL)、あれば認可の公開の口 {@see CreateSelectionSubjectGroup} で専用の対象者を作って指す
 * (その口の 422 は下位のキーのまま)。1 つのトランザクションで書き、どこで失敗しても何も残らない。自分のロックは取らない。作った定義は変えない。
 */
class CreateWorkflowFlow
{
    use ValidatesWorkflowDefinition;

    public function __construct(
        private CreateSelectionSubjectGroup $createSelection,
        private LinearRouteWriter $linearRoutes,
        private RouteVersionWriter $routes,
    ) {}

    /**
     * @param  string|null  $description  説明(前後の空白を除いて空なら NULL)
     * @param  list<WorkflowRouteNodeInput>|string  $route  ノードの並び(位置の順)か、同じノードを使う既存のフローのコード
     * @param  CarbonImmutable  $startDate  版 1 の開始日
     * @param  list<SelectionCondition>|null  $referrerConditions  参照者の条件(null = 参照者なし)
     * @param  array<string, string>  $referrerTranslations  参照者の表示名(ロケールのコード → 名前。参照者があるときだけ使う)
     * @param  bool  $isFirstVersionDeleted  版 1 をユーザー無効で始めるか(初期データの過去の無効の版を再現する = {@see AddRouteVersion} の同じ入力)
     * @return int 作ったフローの id
     *
     * @throws ModelNotFoundException<WorkflowContent|WorkflowFlow|WorkflowFlowVersion> コンテンツ・ルートの写し元のフロー(とその版 1)が無いとき
     * @throws ValidationException コード(code)・名前(name)・説明(description)・並び順(sort_key)・ノードの並び(nodes = 空・先頭が申請でない・承認が無い)・
     *                             ノードの誤り(nodes.<添字>.code・name・kind、処理対象者の表示名の長さなど = nodes.<添字>.target_translations.<ロケール>)・
     *                             参照者の表示名の長さなど(referrer_translations.<ロケール>。参照者があるときだけ)の誤り、処理対象者・参照者のほかの誤り(対象者の口のキーのまま)
     */
    public function execute(string $code, string $name, ?string $description, string $contentCode, array|string $route, CarbonImmutable $startDate, ?array $referrerConditions, array $referrerTranslations, int $sortKey = 0, bool $isFirstVersionDeleted = false): int
    {
        $content = WorkflowContent::query()->where('code', $contentCode)->firstOrFail();
        [$nodes, $sourceVersion] = is_string($route) ? [null, $this->firstVersionOf($route)] : [$route, null];
        $code = $this->normalized($code);
        $name = $this->normalized($name);
        $description = $this->normalized($description);
        $this->throwIfAny([
            'code' => $this->codeError($code, fn (string $code) => WorkflowFlow::query()->where('code', $code)->exists()),
            'name' => $this->textError($name, '名前'),
            'description' => $this->descriptionError($description),
            'sort_key' => $this->sortKeyError($sortKey),
            ...($nodes === null ? [] : $this->linearRoutes->errors($nodes)),
            ...($referrerConditions === null ? [] : $this->translationErrors($referrerTranslations, 'referrer_translations', '参照者の表示名')),
        ]);

        return DB::transaction(function () use ($code, $name, $description, $content, $nodes, $sourceVersion, $startDate, $referrerConditions, $referrerTranslations, $sortKey, $isFirstVersionDeleted) {
            $flow = WorkflowFlow::query()->create([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'workflow_content_id' => $content->id,
                'authz_subject_group_id' => $referrerConditions === null ? null : $this->createSelection->execute($referrerConditions, $referrerTranslations),
                'sort_key' => $sortKey,
            ]);
            $version = WorkflowFlowVersion::query()->create(['workflow_flow_id' => $flow->id, 'number' => 1, 'start_date' => $startDate->toDateString(), 'end_date' => null, 'is_deleted' => $isFirstVersionDeleted]);

            if ($sourceVersion !== null) {
                $this->routes->copy($version->id, $sourceVersion->id);
            } elseif ($nodes !== null) {
                $this->linearRoutes->write($version->id, $content->record_module_id, $nodes);
            }

            return $flow->id;
        });
    }

    /**
     * ルートの写し元のフローの版 1。
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowFlowVersion> フロー・版 1 が無いとき
     */
    private function firstVersionOf(string $flowCode): WorkflowFlowVersion
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail();

        return WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->where('number', 1)->firstOrFail();
    }
}
