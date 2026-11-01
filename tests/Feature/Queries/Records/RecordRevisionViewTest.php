<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 版の写しの口 {@see RecordRevisionQuery::view()}(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の view、
 * 詳細の画面/申請の詳細.md の 4.2 節の「写しの描き方」): 描く項目と項目・選択肢の名前は版の labels、まとまりへの割り当てと並び・見出しの名前は今の定義、
 * 今の定義に無い項目(消した項目・コードを再利用した項目の古い方)は最後のまとまりに版の並びで、明細は版の名前と写しの行、参照制限は写しの時点の値
 * (記録の無い版は null)、参照の表示名は参照先の read と行の判定(見えなければ label が null)。無い版は 404。
 */
final class RecordRevisionViewTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private RecordModule $module;

    public function test_the_fields_take_the_names_of_the_revision_in_the_groups_of_the_current_definition(): void
    {
        $this->projects();
        $id = $this->create(['title' => '企画', 'amount' => 100, 'memo' => '前の補足']);
        $memo = $this->fieldNamed('memo');
        $this->fieldNamed('title')->update(['name' => '件名']);
        RecordFieldHeading::query()->where('name', '金額')->update(['name' => '予算']);
        $memo->delete();
        $this->field($this->module, 'memo', RecordFieldType::Integer, ['sort_key' => 6]);

        $view = $this->viewOf($this->firstRevision($id));

        $this->assertSame([
            [null, [['title', 'title', 'string']]],
            ['予算', [['amount', 'amount', 'integer']]],
            ['補足', [['memo', 'memo', 'string']]],
        ], array_map(fn (array $group) => [
            $group['name'],
            array_map(fn (array $field) => [$field['code'], $field['name'], $field['type']], $group['fields']),
        ], $view['groups']));
        $this->assertNull($view['groups'][0]['id']);
        $this->assertSame(['title' => '企画', 'amount' => 100, 'memo' => '前の補足'], $view['values']);
    }

    public function test_a_field_missing_in_the_current_definition_goes_to_the_last_group_in_the_revision_order(): void
    {
        $this->projects();
        $id = $this->create(['title' => '企画', 'amount' => 100, 'memo' => '補足']);
        $this->fieldNamed('title')->delete();
        $this->fieldNamed('amount')->delete();

        $view = $this->viewOf($this->firstRevision($id));

        $this->assertSame(
            [['補足', ['memo', 'title', 'amount']]],
            array_map(fn (array $group) => [$group['name'], array_column($group['fields'], 'code')], $view['groups']),
        );
    }

    /**
     * snapshot にあって labels に無いキーは描かない。明細は版の名前と写しの行。
     */
    public function test_a_key_without_a_label_is_not_drawn_and_the_details_carry_the_rows_of_the_revision(): void
    {
        $tasks = $this->detailsField($this->module = $this->recordModule('projects'), 'tasks');
        $this->field($this->module, 'title', attributes: ['sort_key' => 1]);
        $this->field($this->childOf($tasks), 'task_name');
        $input = new RecordInput(['title' => '企画'], ['tasks' => [
            new RecordDetailRowInput(null, ['task_name' => '設計']),
            new RecordDetailRowInput(null, ['task_name' => '構築']),
        ]]);
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);
        $revision = RecordRevision::query()->findOrFail($this->firstRevision($id));
        $snapshot = $revision->snapshot ?? [];
        // 整数のキーを付け直さないように、展開でなく添字で足す
        $snapshot['values'][999_999] = '残ったキー';
        $revision->update(['snapshot' => $snapshot]);
        $tasks->update(['name' => '作業']);

        $view = $this->viewOf($revision->id);
        $rows = Record::query()->where('parent_record_id', $id)->orderBy('sort_key')->pluck('id')->all();

        $this->assertSame(['title' => '企画'], $view['values']);
        $this->assertSame([[
            'code' => 'tasks',
            'name' => 'tasks',
            'fields' => [['code' => 'task_name', 'name' => 'task_name', 'type' => 'string', 'isMultiline' => false,
                'referencedModule' => null]],
            'rows' => [
                ['id' => $rows[0], 'values' => ['task_name' => '設計']],
                ['id' => $rows[1], 'values' => ['task_name' => '構築']],
            ],
        ]], $view['details']);
    }

    public function test_the_row_access_is_the_one_at_the_revision_and_null_without_the_record(): void
    {
        $this->projects();
        $viewer = User::query()->where('code', 'u0002')->sole();
        $viewers = [['type' => 'user', 'id' => $viewer->id]];
        $input = new RecordInput(['title' => '企画'], rowAccessRestricted: true, rowAccessViewers: $viewers);
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);
        $revision = RecordRevision::query()->findOrFail($this->firstRevision($id));

        $shown = $this->viewOf($revision->id)['rowAccess'];
        $snapshot = $revision->snapshot ?? [];
        unset($snapshot['row_access']);
        $revision->update(['snapshot' => $snapshot]);

        $this->assertSame([true, [['user', $viewer->id]]], [
            $shown['restricted'] ?? null,
            array_map(fn (array $party) => [$party['type'], $party['id']], $shown['viewers'] ?? []),
        ]);
        $this->assertNull($this->viewOf($revision->id)['rowAccess']);
    }

    /**
     * 参照の相手の表示名は、参照先の業務モジュールの read が無い人には出さない(label が null = 画面は #<id>)。
     */
    public function test_a_reference_label_is_hidden_without_the_read_of_the_referenced_module(): void
    {
        $this->projects();
        $secrets = $this->recordModule('secrets');
        $this->field($secrets, 'secret_name', attributes: ['is_title' => true]);
        $this->referenceField($this->module, 'secret', $secrets, ['sort_key' => 7]);
        $secret = Record::factory()->create(['record_module_id' => $secrets->id, 'field_values' => [
            RecordField::query()->where('code', 'secret_name')->sole()->id => '秘密',
        ]]);
        $id = $this->create(['title' => '企画', 'secret' => $secret->id]);
        $label = fn () => $this->viewOf($this->firstRevision($id))['values']['secret']['label'] ?? null;
        $readable = $label();
        $group = AuthzResourceGroup::query()->where('code', RecordModuleAction::resourceGroupCode('secrets'))->sole();
        AuthzPolicy::query()->where('authz_resource_group_id', $group->id)
            ->where('action', RecordModuleAction::Read->value)->delete();

        $this->assertSame(['秘密', null], [$readable, $label()]);
    }

    public function test_a_missing_revision_is_not_found(): void
    {
        $this->assertThrows(fn () => $this->viewOf(999_999_999), ModelNotFoundException::class);
    }

    /**
     * projects = 頭(title)・見出し「金額」(amount)・見出し「補足」(memo)。
     */
    private function projects(): void
    {
        $this->module = $this->recordModule('projects');
        $this->field($this->module, 'title', attributes: ['sort_key' => 1, 'is_title' => true]);
        $this->field($this->module, 'amount', RecordFieldType::Integer, ['sort_key' => 3]);
        $this->field($this->module, 'memo', attributes: ['sort_key' => 5]);
        $heading = RecordFieldHeading::factory()->state(['record_module_id' => $this->module->id]);
        $heading->create(['name' => '金額', 'sort_key' => 2]);
        $heading->create(['name' => '補足', 'sort_key' => 4]);
    }

    private function fieldNamed(string $code): RecordField
    {
        return RecordField::query()->where('record_module_id', $this->module->id)->where('code', $code)->sole();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput($values));
    }

    private function firstRevision(int $recordId): int
    {
        return (int) RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewOf(int $revisionId): array
    {
        $date = CarbonImmutable::parse('2026-10-11');

        return app(RecordRevisionQuery::class)->view(new Requester(null, null), $revisionId, $date, 'ja');
    }
}
