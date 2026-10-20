<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * レコード 1 件と定義の読み取り(docs の 設計/業務データの入れ物/画面/README.md 1.2・設計/業務データの入れ物/画面/業務の画面.md 2.3、項目と規則/README.md 5 節の読むときの規則、13 節の
 * 「退職したユーザーを指す参照の名称が null」。判定は 認可とメニュー/README.md 2.2 の詳細 = read、順は 2.2 の「順」)。
 */
final class RecordQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    public function test_reads_values_references_details_and_authors(): void
    {
        $ja = Locale::query()->where('code', 'ja')->sole();
        $module = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $customerTitle = $this->field($customers, 'customer_name', attributes: ['is_title' => true]);
        $title = $this->field($module, 'title');
        $this->field($module, 'urgent', RecordFieldType::Boolean);
        $status = $this->choiceField($module, 'status', ['open']);
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $former = $this->field($module, 'former', RecordFieldType::User);
        $section = $this->field($module, 'section', RecordFieldType::Department);
        $customer = $this->referenceField($module, 'customer', $customers);
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $user = $this->namedUser($ja, '山田 太郎', [['2020-01-01', null]]);
        $retired = $this->namedUser($ja, '鈴木 花子', [['2020-01-01', '2026-04-01']]);
        $generalAffairs = Department::query()->where('code', 'general-affairs')->sole();
        $this->join($user, $generalAffairs, ['2024-04-01', null], isMain: true);
        $department = $this->department(DepartmentSet::factory()->create());
        DepartmentTermTranslation::factory()->create(['department_term_id' => DepartmentTerm::query()->where('department_id', $department->id)->sole()->id, 'locale_id' => $ja->id, 'name' => '営業部']);
        $customerRecord = Record::factory()->create(['record_module_id' => $customers->id, 'field_values' => [$customerTitle->id => '株式会社A']]);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '社内ポータル', $status->id => 'gone', 999 => '消した項目'], 'created_by_user_id' => $user->id, 'updated_by_user_id' => $retired->id]);
        $this->refer($record, $owner->id, ['user_id' => $user->id]);
        $this->refer($record, $former->id, ['user_id' => $retired->id]);
        $this->refer($record, $section->id, ['user_id' => null, 'department_id' => $department->id]);
        $this->refer($record, $customer->id, ['user_id' => null, 'referenced_record_module_id' => $customers->id, 'referenced_record_id' => $customerRecord->id]);
        $second = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'sort_key' => 1, 'field_values' => [$taskName->id => '実装']]);
        $first = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'sort_key' => 0, 'field_values' => [$taskName->id => '設計']]);

        $shown = $this->show('projects', $record->id);

        $this->assertSame([
            'title' => '社内ポータル',
            'urgent' => false,
            'status' => ['code' => 'gone', 'name' => null],
            'owner' => ['id' => $user->id, 'code' => $user->code, 'label' => '山田 太郎', 'affiliation' => '総務部'],
            'former' => ['id' => $retired->id, 'code' => $retired->code, 'label' => null, 'affiliation' => null],
            'section' => [
                'id' => $department->id,
                'code' => $department->code,
                'label' => '営業部',
                'affiliation' => null,
            ],
            'customer' => ['id' => $customerRecord->id, 'code' => null, 'label' => '株式会社A', 'affiliation' => null],
        ], $shown['values']);
        $this->assertSame(['tasks' => [['id' => $first->id, 'values' => ['task_name' => '設計']], ['id' => $second->id, 'values' => ['task_name' => '実装']]]], $shown['details']);
        $this->assertSame($shown['values']['owner'], $shown['createdBy']);
        $this->assertSame($shown['values']['former'], $shown['updatedBy']);
    }

    public function test_hides_the_title_of_a_referenced_record_without_read_on_its_module(): void
    {
        $module = $this->recordModule('projects');
        $secrets = $this->recordModule('secrets', [RecordModuleAction::Create]);
        $secretTitle = $this->field($secrets, 'secret_name', attributes: ['is_title' => true]);
        $secret = $this->referenceField($module, 'secret', $secrets);
        $secretRecord = Record::factory()->create(['record_module_id' => $secrets->id, 'field_values' => [$secretTitle->id => '秘密']]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $this->refer($record, $secret->id, ['user_id' => null, 'referenced_record_module_id' => $secrets->id, 'referenced_record_id' => $secretRecord->id]);

        $this->assertSame(['secret' => ['id' => $secretRecord->id, 'code' => null, 'label' => null, 'affiliation' => null]], $this->show('projects', $record->id)['values']);
    }

    public function test_judges_read_before_looking_for_the_record(): void
    {
        $this->recordModule('projects', [RecordModuleAction::Create]);
        $record = Record::factory()->create(['record_module_id' => $this->recordModule('open')->id]);

        foreach ([$record->id, 999_999] as $recordId) {
            try {
                $this->show('projects', $recordId);
                $this->fail('読めた');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ModelNotFoundException::class);
        $this->show('open', 999_999);
    }

    public function test_a_details_module_is_not_found_on_its_own(): void
    {
        $this->detailsField($this->recordModule('projects'), 'tasks');

        $this->expectException(ModelNotFoundException::class);

        $this->show('tasks_rows', 1);
    }

    public function test_builds_the_definition_with_options_child_modules_and_rules_by_code(): void
    {
        $module = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $this->field($module, 'title', attributes: ['is_title' => true, 'is_listed' => true]);
        $this->field($module, 'memo', attributes: ['is_multiline' => true]);
        $this->choiceField($module, 'status', ['open', 'done'], ['default_value' => 'open']);
        $this->referenceField($module, 'customer', $customers);
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'task_name', attributes: ['max_length' => 20]);
        $reason = $this->field($module, 'reason');
        $rule = RecordRule::factory()->create(['record_module_id' => $module->id, 'target_record_field_id' => $reason->id, 'condition' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]]]);

        $definition = app(RecordDefinitionQuery::class)->definition(app(RecordDefinitionQuery::class)->load(app(RecordModuleGate::class)->topLevelModule('projects')));

        $fields = array_column($definition['fields'], null, 'code');
        $this->assertSame(['title', 'memo', 'status', 'customer', 'tasks', 'reason'], array_keys($fields));
        $this->assertSame([1000, 4000, null], [$fields['title']['maxLength'], $fields['memo']['maxLength'], $fields['status']['maxLength']]);
        $this->assertSame([[['code' => 'open', 'name' => 'openの名称'], ['code' => 'done', 'name' => 'doneの名称']], 'open'], [$fields['status']['options'], $fields['status']['defaultValue']]);
        $this->assertSame(['code' => 'customers', 'name' => 'customers'], $fields['customer']['referencedModule']);
        $this->assertSame('tasks_rows', $fields['tasks']['childModule']['code'] ?? null);
        $this->assertEquals([['id' => $rule->id, 'kind' => 'required_when', 'target' => 'reason', 'condition' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]], 'message' => null]], $definition['rules']);
    }

    /**
     * d・ロケール ja の氏名を持つユーザー。
     *
     * @param  list<array{0: string, 1: string|null}>  $terms
     */
    private function namedUser(Locale $locale, string $name, array $terms): User
    {
        $user = $this->user($terms);
        UserTermTranslation::factory()->create(['user_term_id' => UserTerm::query()->where('user_id', $user->id)->sole()->id, 'locale_id' => $locale->id, 'name' => $name]);

        return $user;
    }

    /**
     * @param  array<string, int|null>  $target
     */
    private function refer(Record $record, int $fieldId, array $target): void
    {
        RecordReference::factory()->create(['record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'record_field_id' => $fieldId, ...$target]);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $moduleCode, int $recordId): array
    {
        $requester = new Requester(null, null);
        $date = CarbonImmutable::parse('2026-10-11');
        $opened = app(RecordPageQuery::class)->open($requester, $moduleCode, $date, RecordModuleAction::Read);

        return app(RecordQuery::class)->show($requester, $opened, $recordId, $date, 'ja');
    }
}
