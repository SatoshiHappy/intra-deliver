<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordRevisionDiff;
use App\Queries\Records\RecordRevisionLabels;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版 2 つの差分(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節): 後の版の定義の写しにある項目を
 * 後の版の型で読み比べ、名前は後の版の定義の写し、選択の名前は各版の写し。明細は行を id で突き合わせ、添付は id で突き合わせる。
 * 版は今の定義から作った写しを JSON に通して渡す(DB から読んだ形と同じ。数字のキーは整数に戻り、{} は [] になる)。
 */
final class RecordRevisionDiffTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    public function test_compares_the_fields_of_the_later_definition_by_its_types(): void
    {
        $customers = $this->recordModule('customers');
        $this->field($customers, 'customer_name', attributes: ['is_title' => true]);
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $urgent = $this->field($module, 'urgent', RecordFieldType::Boolean);
        $status = $this->choiceField($module, 'status', ['open', 'done']);
        $budget = $this->field($module, 'budget', RecordFieldType::Integer);
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $customer = $this->referenceField($module, 'customer', $customers);
        $added = $this->field($module, 'added_later');
        [$user, $other] = [$this->user(), $this->user()];
        $kept = Record::factory()->create(['record_module_id' => $customers->id]);
        $customerTarget = ['type' => 'record', 'id' => $kept->id];
        $before = $this->copy($module, [
            'values' => [
                $title->id => '同じ', $urgent->id => false, $status->id => 'open', $budget->id => 100, 999_999 => '前',
            ],
            'references' => [$owner->id => ['type' => 'user', 'id' => $user->id], $customer->id => $customerTarget],
        ]);
        $after = $this->copy($module, [
            'values' => [$title->id => '同じ', $status->id => 'done', $added->id => '足した項目の値', 999_999 => '後'],
            'references' => [$owner->id => ['type' => 'user', 'id' => $other->id], $customer->id => $customerTarget],
        ]);

        $diff = $this->diff($before, $after);

        $this->assertSame([
            [
                'code' => 'status', 'name' => 'status',
                'before' => ['code' => 'open', 'name' => 'openの名称'], 'after' => ['code' => 'done', 'name' => 'doneの名称'],
            ],
            ['code' => 'budget', 'name' => 'budget', 'before' => 100, 'after' => null],
            [
                'code' => 'owner', 'name' => 'owner',
                'before' => $this->shownUser($user->id, $user->code),
                'after' => $this->shownUser($other->id, $other->code),
            ],
            ['code' => 'added_later', 'name' => 'added_later', 'before' => null, 'after' => '足した項目の値'],
        ], $diff['fields']);
        $this->assertSame([[], []], [$diff['details'], $diff['attachments']]);
    }

    public function test_names_come_from_the_definition_copies_of_each_revision(): void
    {
        $module = $this->recordModule('projects');
        $status = $this->choiceField($module, 'status', ['open', 'done']);
        $before = $this->copy($module, ['values' => [$status->id => 'open']]);
        $status->update(['name' => '状況']);
        RecordFieldOption::query()->where('record_field_id', $status->id)->update(['name' => '新しい名前']);
        $after = $this->copy($module, ['values' => [$status->id => 'done']]);
        $status->delete();

        $this->assertSame([[
            'code' => 'status', 'name' => '状況',
            'before' => ['code' => 'open', 'name' => 'openの名称'], 'after' => ['code' => 'done', 'name' => '新しい名前'],
        ]], $this->diff($before, $after)['fields']);
    }

    public function test_a_reference_whose_target_is_gone_or_unreadable_has_no_label(): void
    {
        $secrets = $this->recordModule('secrets', [RecordModuleAction::Create]);
        $secretTitle = $this->field($secrets, 'secret_name', attributes: ['is_title' => true]);
        $module = $this->recordModule('projects');
        $secret = $this->referenceField($module, 'secret', $secrets);
        $target = Record::factory()->create([
            'record_module_id' => $secrets->id, 'field_values' => [$secretTitle->id => '秘密'],
        ]);
        $before = $this->copy($module, ['references' => [$secret->id => ['type' => 'record', 'id' => 999_999]]]);
        $after = $this->copy($module, ['references' => [$secret->id => ['type' => 'record', 'id' => $target->id]]]);

        $this->assertSame([[
            'code' => 'secret', 'name' => 'secret',
            'before' => ['id' => 999_999, 'code' => null, 'label' => null, 'affiliation' => null],
            'after' => ['id' => $target->id, 'code' => null, 'label' => null, 'affiliation' => null],
        ]], $this->diff($before, $after)['fields']);
    }

    public function test_splits_detail_rows_into_added_removed_and_changed_and_ignores_reordering(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $done = $this->field($this->childOf($tasks), 'done', RecordFieldType::Boolean);
        $notes = $this->detailsField($module, 'notes');
        $noteText = $this->field($this->childOf($notes), 'note_text');
        $row = fn (int $id, array $values) => ['id' => $id, 'values' => $values, 'references' => []];
        $before = $this->copy($module, ['details' => [
            $tasks->id => [
                $row(1, [$taskName->id => '設計']),
                $row(2, [$taskName->id => '実装', $done->id => false]),
                $row(3, [$taskName->id => '試験']),
            ],
            $notes->id => [$row(7, [$noteText->id => '並べ替えだけ']), $row(8, [$noteText->id => 'もう 1 行'])],
        ]]);
        $after = $this->copy($module, ['details' => [
            $tasks->id => [
                $row(4, [$taskName->id => '移行']),
                $row(2, [$taskName->id => '実装']),
                $row(1, [$taskName->id => '基本設計', $done->id => true]),
            ],
            $notes->id => [$row(8, [$noteText->id => 'もう 1 行']), $row(7, [$noteText->id => '並べ替えだけ'])],
        ]]);

        $this->assertSame([[
            'code' => 'tasks',
            'name' => 'tasks',
            'rows' => [
                [
                    'change' => 'added', 'rowId' => 4, 'before' => null,
                    'after' => ['task_name' => '移行', 'done' => false], 'changedCodes' => [],
                ],
                [
                    'change' => 'removed', 'rowId' => 3, 'before' => ['task_name' => '試験', 'done' => false],
                    'after' => null, 'changedCodes' => [],
                ],
                [
                    'change' => 'changed', 'rowId' => 1, 'before' => ['task_name' => '設計', 'done' => false],
                    'after' => ['task_name' => '基本設計', 'done' => true], 'changedCodes' => ['task_name', 'done'],
                ],
            ],
        ]], $this->diff($before, $after)['details']);
    }

    public function test_a_missing_details_key_equals_no_rows_and_malformed_keys_are_ignored(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $tasks = $this->detailsField($module, 'tasks');
        $empty = ['fields' => [], 'details' => [], 'attachments' => [], 'rowAccess' => null];
        $malformed = [
            'values' => 'not an object',
            'references' => [$owner->id => ['type' => 'department', 'id' => 1]],
            'details' => [$tasks->id => ['not a row', ['id' => 'x', 'values' => []]]],
            'attachments' => ['not an attachment', ['id' => 'x']],
        ];

        $withEmptyRows = $this->copy($module, ['details' => [$tasks->id => []]]);
        $this->assertSame($empty, $this->diff($withEmptyRows, $this->copy($module, [])));
        $this->assertSame($empty, $this->diff(
            ['snapshot' => $malformed, 'labels' => $this->copy($module, [])['labels']],
            $this->copy($module, ['values' => [$title->id => null]]),
        ));
    }

    public function test_attachments_are_matched_by_id(): void
    {
        $module = $this->recordModule('projects');
        $attachment = fn (int $id, string $name) => [
            'id' => $id, 'upload_id' => $id + 100, 'name' => $name, 'size' => 1, 'mime' => 'application/pdf',
        ];
        $before = $this->copy($module, ['attachments' => [$attachment(1, '残す.pdf'), $attachment(2, '外す.pdf')]]);
        $after = $this->copy($module, ['attachments' => [$attachment(1, '残す.pdf'), $attachment(3, '足す.pdf')]]);

        $this->assertSame([
            ['change' => 'added', 'id' => 3, 'uploadId' => 103, 'name' => '足す.pdf'],
            ['change' => 'removed', 'id' => 2, 'uploadId' => 102, 'name' => '外す.pdf'],
        ], $this->diff($before, $after)['attachments']);
    }

    /**
     * 今の定義で作った版の写し(写しは渡した部分に既定の空を足す。定義の写しは今の定義から)を JSON に通したもの。
     *
     * @param  array<string, mixed>  $parts
     * @return array{snapshot: array<array-key, mixed>, labels: array<array-key, mixed>}
     */
    private function copy(RecordModule $module, array $parts): array
    {
        $snapshot = [...['values' => [], 'references' => [], 'details' => [], 'attachments' => []], ...$parts];
        $definition = app(RecordDefinitionQuery::class)->load($module);
        $selected = is_array($snapshot['values']) ? $snapshot['values'] : [];
        $labels = app(RecordRevisionLabels::class)
            ->of($definition, ['values' => $selected, 'references' => [], 'details' => [], 'attachments' => []]);
        $roundTrip = fn (array $value) => json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return ['snapshot' => $roundTrip($snapshot), 'labels' => $roundTrip($labels)];
    }

    /**
     * @param  array{snapshot: array<array-key, mixed>, labels: array<array-key, mixed>}  $before
     * @param  array{snapshot: array<array-key, mixed>, labels: array<array-key, mixed>}  $after
     * @return array<string, mixed>
     */
    private function diff(array $before, array $after): array
    {
        $date = CarbonImmutable::parse(self::TODAY);

        return app(RecordRevisionDiff::class)->diff($before, $after, new Requester(null, null), $date, 'ja');
    }

    /**
     * @return array{id: int, code: string, label: null, affiliation: null}
     */
    private function shownUser(int $id, string $code): array
    {
        return ['id' => $id, 'code' => $code, 'label' => null, 'affiliation' => null];
    }
}
