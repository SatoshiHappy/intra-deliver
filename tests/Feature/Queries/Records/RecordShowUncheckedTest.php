<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTermTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordShowUnchecked;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 判定しない読み取りの公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表の行): 判定する者を受けず、業務モジュールの read を
 * 持たない業務モジュールのレコードも読み、形は表示の口の show と同じで、他モジュール参照の表示名だけが null(画面の #<id>)。
 * ユーザー・組織の名前は渡した基準日のもの。
 */
final class RecordShowUncheckedTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /** 名前が替わる日(前の期間の終わりの翌日) */
    private const RENAMED_ON = '2026-11-01';

    /**
     * 表示の口(参照先を読める判定する者)と同じ形で、他モジュール参照の表示名だけが null。
     */
    public function test_shows_the_same_shape_as_the_display_query_without_the_reference_labels(): void
    {
        ['record' => $record, 'customer' => $customer] = $this->project();

        $shown = $this->show($record->id, self::TODAY);
        $displayed = app(RecordDisplayQuery::class)
            ->show(new Requester(null, null), $record->id, self::day(self::TODAY), 'ja');

        $this->assertSame('株式会社A', $displayed['record']['values']['customer']['label']);
        $displayed['record']['values']['customer']['label'] = null;
        $this->assertSame($displayed, $shown);
        $this->assertSame(
            ['id' => $customer->id, 'code' => null, 'label' => null, 'affiliation' => null],
            $shown['record']['values']['customer'],
        );
        $rows = $shown['record']['details']['tasks'];
        $this->assertSame(['設計'], array_column(array_column($rows, 'values'), 'task_name'));
    }

    /**
     * ユーザー(参照の項目・作成者・更新者)と組織の名前は、渡した基準日の期間のもの。
     */
    public function test_user_and_department_names_are_of_the_given_date(): void
    {
        ['record' => $record] = $this->project();

        $names = [self::TODAY => ['山田 太郎', '営業部'], self::RENAMED_ON => ['佐藤 太郎', '営業本部']];

        foreach ($names as $date => [$userName, $departmentName]) {
            $shown = $this->show($record->id, $date)['record'];

            $this->assertSame([$userName, $userName, $userName, $departmentName], [
                $shown['values']['owner']['label'],
                $shown['createdBy']['label'] ?? null,
                $shown['updatedBy']['label'] ?? null,
                $shown['values']['section']['label'],
            ], $date);
        }
    }

    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        ['record' => $record] = $this->project();
        $row = Record::query()->where('parent_record_id', $record->id)->firstOrFail();

        foreach ([999_999_999, $row->id] as $recordId) {
            $this->assertThrows(fn () => $this->show($recordId, self::TODAY), ModelNotFoundException::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $recordId, string $date): array
    {
        return app(RecordShowUnchecked::class)->show($recordId, self::day($date), 'ja');
    }

    /**
     * read を持たない業務モジュール projects のレコード(表示の項目・ユーザー・組織・読める業務モジュール customers への参照・明細の行)。
     * ユーザーと組織は RENAMED_ON に名前が替わる。作成者・更新者は参照の項目と同じユーザー。
     *
     * @return array{record: Record, customer: Record}
     */
    private function project(): array
    {
        $module = $this->recordModule('projects', [RecordModuleAction::Create]);
        $customers = $this->recordModule('customers');
        $title = $this->field($module, 'title', attributes: ['is_title' => true]);
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $section = $this->field($module, 'section', RecordFieldType::Department);
        $customerField = $this->referenceField($module, 'customer', $customers);
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $customerName = $this->field($customers, 'customer_name', attributes: ['is_title' => true]);
        $customer = Record::factory()->create([
            'record_module_id' => $customers->id,
            'field_values' => [$customerName->id => '株式会社A'],
        ]);
        $user = $this->renamedUser();
        $department = $this->renamedDepartment();
        $record = Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [$title->id => '社内ポータル'],
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $base = ['record_module_id' => $module->id, 'record_id' => $record->id, 'user_id' => null];
        RecordReference::factory()->create([...$base, 'record_field_id' => $owner->id, 'user_id' => $user->id]);
        RecordReference::factory()
            ->create([...$base, 'record_field_id' => $section->id, 'department_id' => $department->id]);
        RecordReference::factory()->create([
            ...$base,
            'record_field_id' => $customerField->id,
            'referenced_record_module_id' => $customers->id,
            'referenced_record_id' => $customer->id,
        ]);
        Record::factory()->create([
            'record_module_id' => $tasks->child_record_module_id,
            'parent_record_module_id' => $module->id,
            'parent_record_id' => $record->id,
            'field_values' => [$taskName->id => '設計'],
        ]);

        return ['record' => $record, 'customer' => $customer];
    }

    private function renamedUser(): User
    {
        $user = $this->user([['2020-01-01', '2026-10-31'], [self::RENAMED_ON, null]]);

        foreach ($user->userTerms()->orderBy('start_date')->get() as $index => $term) {
            UserTermTranslation::factory()->create([
                'user_term_id' => $term->id,
                'locale_id' => $this->jaId(),
                'name' => ['山田 太郎', '佐藤 太郎'][$index],
            ]);
        }

        return $user;
    }

    private function renamedDepartment(): Department
    {
        $terms = [['2020-01-01', '2026-10-31'], [self::RENAMED_ON, null]];
        $department = $this->department(DepartmentSet::factory()->create(), $terms);

        foreach ($department->departmentTerms()->orderBy('start_date')->get() as $index => $term) {
            DepartmentTermTranslation::factory()->create([
                'department_term_id' => $term->id,
                'locale_id' => $this->jaId(),
                'name' => ['営業部', '営業本部'][$index],
            ]);
        }

        return $department;
    }

    private function jaId(): int
    {
        return Locale::query()->where('code', 'ja')->sole()->id;
    }
}
