<?php

namespace Tests\Support;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateWorkflowContent;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Master\User;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowFlowResources;

/**
 * 申請対象の値の写しのテストの手助け(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1 節・11 節)。
 * 申請対象の業務モジュール m_hub と申請書の業務モジュール m_copy の項目を、写る組と写らない組(型・参照先・複数の指定が違う)で並べ、
 * フローは本物の業務処理で作る(申請 = 渡した人、承認 = u0002)。{@see RunsWorkflowOperations}・{@see RegistersProxies} と使う。
 */
trait BuildsApplicationTargetCopies
{
    use BuildsRecordModules;

    /** 写しのフローのコード */
    private const COPY_FLOW = 'wf_copy';

    /** 申請書の業務モジュール */
    private const COPY_FORM = 'm_copy';

    /** 申請対象の業務モジュール */
    private const HUB = 'm_hub';

    /** 申請書の業務モジュールの申請対象の項目 */
    private const TARGET_FIELD = 'target';

    /**
     * 業務モジュール(m_hub・m_copy・参照先 m_ref_a・m_ref_b。どれも全員に read・create・update)とフロー。
     *
     * @param  list<User>  $applicants  申請のノードの対象者
     */
    protected function copyFlow(array $applicants): int
    {
        $actions = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];
        [$refA, $refB] = [$this->recordModule('m_ref_a', $actions), $this->recordModule('m_ref_b', $actions)];
        $this->field($refA, 'label', RecordFieldType::String, ['is_title' => true]);
        $hub = $this->recordModule(self::HUB, $actions);
        $form = $this->recordModule(self::COPY_FORM, $actions);
        $this->referenceField($form, self::TARGET_FIELD, $hub);
        [$string, $integer] = [RecordFieldType::String, RecordFieldType::Integer];
        $this->copyFields($hub, $refA, $refA, ['note' => $string, 'multiple' => true, 'memo' => $string]);
        $this->copyFields($form, $refA, $refB, ['note' => $integer, 'multiple' => false, 'memo' => $integer]);
        $this->field($form, 'only_form');
        app(CreateWorkflowContent::class)->execute(self::COPY_FORM, 'content_copy', '写しの申請書', self::TARGET_FIELD);
        $condition = fn (User $user) => new SelectionCondition(SelectionConditionKind::User, $user->id);
        $conditions = array_map($condition, $applicants);
        $approver = [$condition(User::query()->where('code', 'u0002')->sole())];
        $nodes = [
            new WorkflowRouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, $conditions, ['ja' => '申請']),
            new WorkflowRouteNodeInput('approval1', '承認1', WorkflowNodeKind::Approve, $approver, ['ja' => '承認1']),
        ];

        $flowId = app(CreateWorkflowFlow::class)->execute(
            self::COPY_FLOW, '写しの申請', null, 'content_copy', $nodes, CarbonImmutable::parse('2025-04-01'), null, [], 30,
        );
        app(WorkflowFlowResources::class)->createFor(self::COPY_FLOW);

        return $flowId;
    }

    /**
     * 申請対象(m_hub)のレコード。参照 ref・ref_other は m_ref_a の、$referenceCreator が作ったレコード(作った後に参照制限を付けると読めなくなる)。
     *
     * @return array{hub: int, ref: int} 申請対象と参照先の id
     */
    protected function hubRecord(User $creator, ?User $referenceCreator = null, string $title = 'プロジェクトX'): array
    {
        $create = app(CreateRecord::class);
        $referenceContext = $this->contextFor(($referenceCreator ?? $creator)->id);
        $ref = $create->execute($referenceContext, 'm_ref_a', new RecordInput(['label' => '参照先']));
        $members = array_values(array_unique([$creator->id, User::query()->where('code', 'u0003')->sole()->id]));
        $values = [
            'title' => $title, 'amount' => 120, 'kind' => 'a', 'note' => 'メモ',
            'ref' => $ref, 'ref_other' => $ref, 'members' => $members,
        ];
        $rows = [
            new RecordDetailRowInput(values: ['item' => 'A', 'qty' => 1, 'memo' => 'x']),
            new RecordDetailRowInput(values: ['item' => 'B', 'qty' => 2, 'memo' => 'y']),
        ];
        $input = new RecordInput($values, ['lines' => $rows]);
        $hub = $create->execute($this->contextFor($creator->id), self::HUB, $input);

        return ['hub' => $hub, 'ref' => $ref];
    }

    /**
     * 申請書(m_copy)のレコード(申請対象と参照 ref を入れる)。
     */
    protected function copyFormRecord(User $creator, ?int $hubId, ?int $refId = null): int
    {
        $values = ['title' => '申請書の題', self::TARGET_FIELD => $hubId, 'ref' => $refId];

        $context = $this->contextFor($creator->id);

        return app(CreateRecord::class)->execute($context, self::COPY_FORM, new RecordInput($values));
    }

    /**
     * 写しのフローで新規に申請した案件(本物の業務処理。申請基準日 = 操作の基準日)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function applyCopy(User $applicant, array $values = []): WorkflowCase
    {
        $input = new ApplicationInput(null, null, new RecordInput(['title' => '申請した題', ...$values]));
        $context = $this->contextFor($applicant->id);
        $result = app(ApplyCase::class)->execute($context, self::COPY_FLOW, $input, $context->date->toDateString());

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * 渡したレコードの、u0001 から見た写しの値(10.1 節の規則の結果。並びは比べない = {@see self::sorted()} で比べる)。
     *
     * @param  array{hub: int, ref: int}  $ids
     * @return array<string, mixed>
     */
    protected function expectedCopy(array $ids, string $title = 'プロジェクトX', ?string $refLabel = null): array
    {
        $reference = fn (int $id, ?string $label) => [
            'id' => $id, 'code' => null, 'label' => $label, 'affiliation' => null,
        ];

        return self::sorted([
            'values' => [
                self::TARGET_FIELD => $reference($ids['hub'], $title),
                'title' => $title,
                'amount' => 120,
                'kind' => ['code' => 'a', 'name' => '申請書のa'],
                'ref' => $reference($ids['ref'], $refLabel),
                'empty_text' => null,
            ],
            'details' => [
                'lines' => [['values' => ['item' => 'A', 'qty' => 1]], ['values' => ['item' => 'B', 'qty' => 2]]],
            ],
        ]);
    }

    /**
     * 業務モジュールのレコードを参照制限ありにする(関係者の印の項目が無ければ作成者だけが見える = {@see RowAccessRestriction})。
     */
    protected function onlyRelated(string $moduleCode): void
    {
        RowAccessRestriction::restrictCode($moduleCode);
    }

    /**
     * キーの並びをそろえた配列(写しの値の並びは定義の並びで決まり、規則の外のため)。
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => is_array($item) ? self::sorted($item) : $item, $value);
    }

    /**
     * 写す組と写らない組の項目。選択の名前は業務モジュールごとに違える(写した後に申請書の名前で引き直すため)。
     *
     * @param  array{note: RecordFieldType, multiple: bool, memo: RecordFieldType}  $differ  業務モジュールで違える所
     */
    private function copyFields(RecordModule $module, RecordModule $ref, RecordModule $refOther, array $differ): void
    {
        $this->field($module, 'title', RecordFieldType::String, ['is_title' => $module->code === self::HUB]);
        $this->field($module, 'amount', RecordFieldType::Integer);
        $kind = $this->field($module, 'kind', RecordFieldType::Choice);
        $prefix = $module->code === self::HUB ? '元の' : '申請書の';

        foreach (['a', 'b'] as $index => $code) {
            RecordFieldOption::factory()->create([
                'record_field_id' => $kind->id, 'code' => $code, 'name' => "{$prefix}{$code}", 'sort_key' => $index,
            ]);
        }

        $this->field($module, 'note', $differ['note']);
        $this->referenceField($module, 'ref', $ref);
        $this->referenceField($module, 'ref_other', $refOther);
        $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => $differ['multiple']]);
        $this->field($module, 'empty_text');
        $child = RecordModule::factory()->create([
            'code' => "{$module->code}_lines",
            'name' => "{$module->code}_lines",
            'parent_record_module_id' => $module->id,
        ]);
        $this->field($module, 'lines', RecordFieldType::Details, ['child_record_module_id' => $child->id]);
        $this->field($child, 'item');
        $this->field($child, 'qty', RecordFieldType::Integer);
        $this->field($child, 'memo', $differ['memo']);
    }

    /**
     * 初期データの利用者(渡したコードの順)。
     *
     * @return list<User>
     */
    protected function usersOf(string ...$codes): array
    {
        return array_values(array_map(fn (string $code) => User::query()->where('code', $code)->sole(), $codes));
    }
}
