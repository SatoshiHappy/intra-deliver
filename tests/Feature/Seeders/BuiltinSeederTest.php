<?php

namespace Tests\Feature\Seeders;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\UpdateRecordField;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Builtin\Meeting;
use App\Enums\Records\RowAccessScope;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RelativeDefault;
use Carbon\CarbonImmutable;
use Database\Seeders\BuiltinSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 組み込みの業務モジュールの投入(docs の 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」、会議 = 設計/業務ツール/会議/README.md の
 * 1 節、削除予定日の既定 = 設計/業務ツール/会議/通知と削除予定日.md の 7.1)。テスト DB の初期データ(DatabaseSeeder)が入れた定義を確かめ、
 * もう一度流したときの振る舞いはテストの中で流して確かめる。
 */
final class BuiltinSeederTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    /** 項目の旗(定義に無ければ偽) */
    private const FLAGS = [
        'is_required', 'is_multiline', 'is_multiple', 'is_listed', 'is_searchable', 'is_title', 'grants_row_access',
    ];

    public function test_the_meeting_module_matches_the_enum(): void
    {
        $module = $this->meeting();

        $this->assertSame(
            ['会議', '会議の記録と議事録', 'users', RowAccessScope::Related, true, true],
            [
                $module->name, $module->description, $module->icon, $module->row_access_scope,
                $module->is_attachment_enabled, $module->is_builtin,
            ],
        );
        $this->assertNull($module->parent_record_module_id);
        $items = ['global-nav-main-records-meeting', 'sitemap-main-records-meeting'];
        $icons = Menu::query()->whereIn('code', $items)->orderBy('code')->pluck('icon_32', 'code')->all();
        $this->assertSame(array_fill_keys($items, 'users'), $icons);
        $this->assertSame(
            array_map(fn (Meeting $field) => $this->expectedField($field, 'P6M'), Meeting::cases()),
            $this->meetingFields()->map(fn (RecordField $field) => $this->actualField($field))->all(),
        );
    }

    public function test_a_second_run_changes_nothing_even_after_the_administrator_renamed_them(): void
    {
        app(UpdateRecordModule::class)->execute(Meeting::CODE, ['name' => '打ち合わせ']);
        app(UpdateRecordField::class)->execute(Meeting::CODE, Meeting::Title->value, ['name' => '題名']);
        $before = $this->definitionRows();

        $this->seedBuiltins();

        $this->assertSame($before, $this->definitionRows());
        $this->assertSame('打ち合わせ', $this->meeting()->name);
    }

    public function test_recreates_only_a_missing_builtin_field_at_the_end(): void
    {
        $others = $this->meetingFields()->reject(fn (RecordField $field) => $field->code === Meeting::Summary->value)
            ->map(fn (RecordField $field) => $field->only(['id', 'sort_key']))->values()->all();
        $this->meetingField(Meeting::Summary)->delete();

        $this->seedBuiltins();

        $fields = $this->meetingFields();
        $kept = $fields->slice(0, -1)->map(fn (RecordField $field) => $field->only(['id', 'sort_key']));
        $this->assertSame($others, $kept->values()->all());
        $this->assertSame($this->expectedField(Meeting::Summary, 'P6M'), $this->actualField($fields->last()));
    }

    #[TestWith(['P30D'])]
    #[TestWith(['P0D'])]
    public function test_the_purge_due_on_default_comes_from_the_setting(string $duration): void
    {
        $this->meetingField(Meeting::PurgeDueOn)->delete();
        config(['meeting.purge_after' => $duration]);

        $this->seedBuiltins();

        $actual = $this->actualField($this->meetingField(Meeting::PurgeDueOn)->first());
        $this->assertSame($this->expectedField(Meeting::PurgeDueOn, $duration), $actual);
    }

    #[TestWith(['6M'])]
    #[TestWith(['P06M'])]
    #[TestWith(['P1W'])]
    #[TestWith([''])]
    public function test_rejects_a_setting_that_is_not_a_relative_duration(string $duration): void
    {
        config(['meeting.purge_after' => $duration]);

        $this->expectExceptionObject(new LogicException('設定 meeting.purge_after は相対の既定値の期間(例 P6M)にする'));

        $this->seedBuiltins();
    }

    public function test_rejects_a_module_with_the_meeting_code_that_is_not_builtin(): void
    {
        RecordModule::query()->whereKey($this->meeting()->id)->update(['is_builtin' => false]);

        $this->assertSeedingFails('組み込みの定義 meeting と同じコードの、組み込みでない定義がある');
    }

    public function test_rejects_a_field_with_a_builtin_code_that_is_not_builtin(): void
    {
        $this->meetingField(Meeting::Title)->update(['is_builtin' => false]);

        $this->assertSeedingFails('組み込みの定義 meeting.title と同じコードの、組み込みでない定義がある');
    }

    public function test_rejects_a_details_module_with_the_meeting_code(): void
    {
        RecordModule::query()->whereKey($this->meeting()->id)->update(['code' => 'meeting_before']);
        $this->detailsField($this->recordModule('reports'), 'meeting', ['name' => '会議の明細']);
        RecordModule::query()->where('code', 'meeting_rows')->update(['code' => Meeting::CODE]);

        $this->assertSeedingFails('組み込みの定義 meeting と同じコードの、組み込みでない定義がある');
    }

    public function test_requires_the_project_module(): void
    {
        RecordModule::query()->where('code', Meeting::PROJECT_MODULE)->update(['code' => 'project_before']);

        $this->assertSeedingFails('組み込みの会議は業務モジュール project を要する');
    }

    public function test_a_new_meeting_gets_the_registration_time_and_the_purge_day_from_the_relative_defaults(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 15:30:45 UTC');
        $userId = User::query()->where('code', 'u0004')->sole()->id;

        $input = new RecordInput(['title' => '定例会議']);

        $id = app(CreateRecord::class)->execute($this->contextFor($userId), Meeting::CODE, $input);

        $values = Record::query()->findOrFail($id)->field_values ?? [];
        $this->assertSame('2026-10-10T15:30:00Z', $values[$this->meetingField(Meeting::HeldAt)->sole()->id] ?? null);
        $this->assertSame('2027-04-11', $values[$this->meetingField(Meeting::PurgeDueOn)->sole()->id] ?? null);
    }

    private function seedBuiltins(): void
    {
        app(BuiltinSeeder::class)->run();
    }

    private function assertSeedingFails(string $message): void
    {
        try {
            $this->seedBuiltins();
        } catch (LogicException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('投入が止まらなかった');
    }

    private function meeting(): RecordModule
    {
        return RecordModule::query()->where('code', Meeting::CODE)->sole();
    }

    /**
     * 会議の項目 1 つを選ぶ問い合わせ。
     *
     * @return Builder<RecordField>
     */
    private function meetingField(Meeting $field): Builder
    {
        return RecordField::query()->with('referencedRecordModule')
            ->where('record_module_id', $this->meeting()->id)->where('code', $field->value);
    }

    /**
     * 会議の項目(並びの順)。
     *
     * @return Collection<int, RecordField>
     */
    private function meetingFields(): Collection
    {
        return RecordField::query()->with('referencedRecordModule')->where('record_module_id', $this->meeting()->id)
            ->orderBy('sort_key')->orderBy('id')->get();
    }

    /**
     * 列挙の定義から期待する項目(定義に無い属性は項目を足す業務処理の既定)。
     *
     * @return array<string, mixed>
     */
    private function expectedField(Meeting $field, string $purgeAfter): array
    {
        $definition = $field->definition($purgeAfter);
        $flags = self::FLAGS;

        return [
            'code' => $field->value,
            'name' => $definition['name'],
            'type' => $definition['type'],
            ...array_combine($flags, array_map(fn (string $flag) => $definition[$flag] ?? false, $flags)),
            'max_length' => $definition['max_length'] ?? null,
            'default_value' => $definition['default_value'] ?? null,
            'referenced_module' => $definition['referenced_module'] ?? null,
            'is_builtin' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actualField(?RecordField $field): array
    {
        $this->assertNotNull($field);
        $default = $field->default_value;

        return [
            'code' => $field->code,
            'name' => $field->name,
            'type' => $field->type->value,
            ...$field->only(self::FLAGS),
            'max_length' => $field->max_length,
            'default_value' => $default instanceof RelativeDefault ? $default->toObject() : $default,
            'referenced_module' => $field->referencedRecordModule?->code,
            'is_builtin' => $field->is_builtin,
        ];
    }

    /**
     * 業務モジュールと項目の行の全体(id の順)。
     *
     * @return list<array<string, mixed>>
     */
    private function definitionRows(): array
    {
        $modules = RecordModule::query()->orderBy('id')->get()->map(fn (RecordModule $row) => $row->getAttributes());
        $fields = RecordField::query()->orderBy('id')->get()->map(fn (RecordField $row) => $row->getAttributes());

        return [...$modules->values()->all(), ...$fields->values()->all()];
    }
}
