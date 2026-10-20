<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordRevisionDiff;
use App\Queries\Records\RecordRevisionLabels;
use App\Queries\Records\RecordSnapshots;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の写しと差分(写しの形 = docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md 2.1.1 の references の値、
 * 差分 = 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節): 写しは相手の配列(sort_key の順。相手が無ければキーが無い)、
 * 差分は相手の集合で比べ、違えば前と後の全体を画面の値の形で返す。
 */
final class MultipleReferenceSnapshotsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    public function test_a_snapshot_holds_the_targets_of_a_multiple_field_in_the_order_of_sort_key(): void
    {
        $module = $this->recordModule('projects');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $members = $this->multipleField($module, 'members');
        $this->multipleField($module, 'reviewers');
        $tasks = $this->detailsField($module, 'tasks');
        $assignees = $this->multipleField($this->childOf($tasks), 'assignees');
        [$a, $b] = [$this->user(), $this->user()];
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create([
            'record_module_id' => $tasks->child_record_module_id,
            'parent_record_module_id' => $module->id,
            'parent_record_id' => $record->id,
        ]);
        $this->refer($record, $owner, $a, 0);
        $this->refer($record, $members, $b, 1);
        $this->refer($record, $members, $a, 0);
        $this->refer($row, $assignees, $b, 0);

        $definition = app(RecordDefinitionQuery::class)->load($module);
        $snapshot = app(RecordSnapshots::class)->snapshot($record, $definition);

        $this->assertSame([
            $owner->id => ['type' => 'user', 'id' => $a->id],
            $members->id => [['type' => 'user', 'id' => $a->id], ['type' => 'user', 'id' => $b->id]],
        ], $snapshot['references']);
        $rowReferences = $snapshot['details'][$tasks->id][0]['references'];
        $this->assertSame([$assignees->id => [['type' => 'user', 'id' => $b->id]]], $rowReferences);
    }

    public function test_the_diff_compares_the_set_of_targets(): void
    {
        $module = $this->recordModule('projects');
        $members = $this->multipleField($module, 'members');
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];
        $snapshot = fn (User ...$users) => ['references' => [
            $members->id => array_map(fn (User $user) => ['type' => 'user', 'id' => $user->id], $users),
        ]];
        $shown = fn (User ...$users) => array_map(
            fn (User $user) => ['id' => $user->id, 'code' => $user->code, 'label' => null, 'affiliation' => null],
            $users,
        );

        $reordered = $this->diff($module, $snapshot($a, $b), $snapshot($b, $a));
        $added = $this->diff($module, $snapshot($a, $b), $snapshot($b, $a, $c));
        $cleared = $this->diff($module, $snapshot($a), []);

        $this->assertSame([], $reordered['fields']);
        $this->assertSame(
            [['code' => 'members', 'name' => 'members', 'before' => $shown($a, $b), 'after' => $shown($b, $a, $c)]],
            $added['fields'],
        );
        $this->assertSame(
            [['code' => 'members', 'name' => 'members', 'before' => $shown($a), 'after' => null]],
            $cleared['fields'],
        );
    }

    private function multipleField(RecordModule $module, string $code): RecordField
    {
        return $this->field($module, $code, RecordFieldType::User, ['is_multiple' => true]);
    }

    private function refer(Record $record, RecordField $field, User $user, int $sortKey): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => $user->id,
            'sort_key' => $sortKey,
            'is_multiple' => $field->refresh()->is_multiple,
        ]);
    }

    /**
     * 今の定義の写しを持つ版の写し 2 つ(写しを保存形の JSON に通す。キーは 10 進の文字列になり、読むと整数のキーに戻る)の差分。
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return array<string, mixed>
     */
    private function diff(RecordModule $module, array $before, array $after): array
    {
        $roundTrip = fn (array $value) => json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $empty = ['values' => [], 'references' => [], 'details' => [], 'attachments' => []];
        $definition = app(RecordDefinitionQuery::class)->load($module);
        $labels = $roundTrip(app(RecordRevisionLabels::class)->of($definition, $empty));
        $copy = fn (array $snapshot) => ['snapshot' => $roundTrip($snapshot), 'labels' => $labels];
        $date = CarbonImmutable::parse(self::TODAY);
        $guest = new Requester(null, null);

        return app(RecordRevisionDiff::class)->diff($copy($before), $copy($after), $guest, $date, 'ja');
    }
}
