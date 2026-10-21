<?php

namespace Tests\Feature\Seeders;

use App\Enums\Builtin\Meeting;
use App\Enums\Records\RecordFieldType;
use App\Models\Authz\AuthzPolicy;
use App\Models\Menu\Menu;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの入れ物の初期データ(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 7 節・8 節の「初期データ」の件数): 業務モジュール 3 つ(明細 1)、
 * 項目 21・規則 2、レコード(プロジェクト 3・マイルストーン 7・課題 5)。型は 11 種すべてが 1 つ以上ある。
 * レコードの件数・参照・作成者は、ワークフローの初期データの案件の見本が申請で作る分(プロジェクト 5・マイルストーン 3・課題 3 = 設計/ワークフロー/初期データ.md 4 節の見本)を含む。
 * ポリシーの件数は AuthorizationSeedersTest。定義は組み込みでないもの(この seeder が作るもの)に絞って確かめる(組み込みの会議は BuiltinSeederTest)。
 */
final class RecordsSeederTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
    }

    public function test_seeds_the_modules_fields_and_rules_of_the_design(): void
    {
        $modules = RecordModule::query()->with('parentRecordModule')->where('is_builtin', false)->orderBy('id')->get()
            ->map(fn (RecordModule $module) => [$module->code, $module->name, $module->parentRecordModule?->code])->all();
        $fieldCounts = RecordField::query()->join('record_modules', 'record_modules.id', '=', 'record_fields.record_module_id')
            ->where('record_fields.is_builtin', false)->groupBy('record_modules.code')->selectRaw('record_modules.code, count(*) as fields')->pluck('fields', 'code')->all();

        $this->assertSame([['project', 'プロジェクト', null], ['project_issue', '課題', null], ['project_milestone', 'マイルストーン', 'project']], $modules);
        $this->assertEquals(['project' => 9, 'project_milestone' => 4, 'project_issue' => 8], $fieldCounts);
        $this->assertSame(2, RecordRule::query()->count());
        $this->assertEqualsCanonicalizing(
            array_map(fn (RecordFieldType $type) => $type->value, RecordFieldType::cases()),
            RecordField::query()->where('is_builtin', false)->distinct()->pluck('type')->map(fn (RecordFieldType $type) => $type->value)->all(),
        );
    }

    public function test_no_field_name_is_duplicated_within_a_module_and_its_details_modules(): void
    {
        $modules = RecordModule::query()->where('is_builtin', false)->get();
        $names = [];

        foreach (RecordField::query()->where('is_builtin', false)->get(['record_module_id', 'name']) as $field) {
            $module = $modules->firstOrFail('id', $field->record_module_id);
            $names[$module->parent_record_module_id ?? $module->id][] = $field->name;
        }

        $this->assertNotEmpty($names);

        foreach ($names as $topNames) {
            $this->assertSame($topNames, array_values(array_unique($topNames)));
        }
    }

    public function test_makes_every_field_except_details_a_search_condition(): void
    {
        $fields = fn () => RecordField::query()->where('is_builtin', false);

        $this->assertSame([], $fields()->where('type', '<>', RecordFieldType::Details)->where('is_searchable', false)->pluck('code')->all());
        $this->assertSame(0, $fields()->where('type', RecordFieldType::Details)->where('is_searchable', true)->count());
    }

    public function test_gives_the_work_menu_items_the_icon_of_each_module(): void
    {
        $icons = Menu::query()->where('code', 'like', '%-main-records-%')->where('code', 'not like', '%-main-records-'.Meeting::CODE)
            ->orderBy('code')->pluck('icon_32', 'code')->all();

        $this->assertSame([
            'global-nav-main-records-project' => 'folder-kanban',
            'global-nav-main-records-project_issue' => 'list-todo',
            'sitemap-main-records-project' => 'folder-kanban',
            'sitemap-main-records-project_issue' => 'list-todo',
        ], $icons);
    }

    public function test_seeds_the_records_of_the_design(): void
    {
        $counts = Record::query()->join('record_modules', 'record_modules.id', '=', 'records.record_module_id')
            ->groupBy('record_modules.code')->selectRaw('record_modules.code, count(*) as records')->pluck('records', 'code')->all();

        // 業務データの入れ物の分 + ワークフローの案件の見本の分(段 2 の見本 = プロジェクト 5・マイルストーン 3・課題 3、版 3 の見本 = プロジェクト 4・マイルストーン 2
        // = ルートの深さ/初期データ.md 4 節)
        $this->assertEquals(['project' => 3 + 5 + 4, 'project_milestone' => 7 + 3 + 2, 'project_issue' => 5 + 3], $counts);
        // 参照の値 = プロジェクトの担当部署と責任者 12 × 2、課題のプロジェクト 8 と担当者 7(業務データの入れ物の 1 件は担当者なし)
        $this->assertSame(12 * 2 + 8 + 7, RecordReference::query()->count());
    }

    /**
     * 業務データの入れ物の初期データ(システム管理者が作ったもの)のプロジェクトは、どれも課題から参照される(ワークフローの見本のプロジェクトは見ない)。
     */
    public function test_every_project_is_referenced_by_an_issue(): void
    {
        $projects = RecordModule::query()->where('code', 'project')->sole();

        $this->assertSame(
            Record::query()->where('record_module_id', $projects->id)->whereRelation('createdByUser', 'code', 'u0004')->orderBy('id')->pluck('id')->all(),
            RecordReference::query()->where('referenced_record_module_id', $projects->id)->whereRelation('record.createdByUser', 'code', 'u0004')->distinct()->orderBy('referenced_record_id')->pluck('referenced_record_id')->all(),
        );
    }

    /**
     * 作成者 = 業務データの入れ物の分はシステム管理者(u0004)、ワークフローの見本の分は申請者(u0001・u0010・u0009。作った順)。
     */
    public function test_the_records_are_written_by_the_system_administrator_or_the_applicants_and_the_seeded_policies_are_permits(): void
    {
        $this->assertSame(['u0004', 'u0001', 'u0010', 'u0009'], Record::query()->with('createdByUser')->orderBy('id')->get()->map(fn (Record $record) => $record->createdByUser?->code)->unique()->values()->all());
        $this->assertSame(0, AuthzPolicy::query()->where('effect', '<>', 'permit')->count());
    }

    /**
     * 見本のコメント(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 4 節): 「プロジェクト」の最初の見本のレコードに
     * 3 件(宛先つき 2 件)。通知は宛先から書いた人を除いた 3 行で、対象はそのレコード。時刻は時計を合わせた過去の日時(Asia/Tokyo)。
     */
    public function test_seeds_three_comments_on_the_first_project_two_with_mentions_and_their_notices(): void
    {
        $projects = RecordModule::query()->where('code', 'project')->sole();
        $first = Record::query()->where('record_module_id', $projects->id)->orderBy('id')->firstOrFail();
        $comments = RecordComment::query()->with(['user', 'recordCommentMentions.user'])->orderBy('id')->get();

        $this->assertSame([$first->id, $first->id, $first->id], $comments->pluck('record_id')->all());
        $this->assertSame(
            ['u0011', 'u0004', 'u0012'],
            $comments->map(fn (RecordComment $comment) => $comment->user?->code)->all(),
        );
        $this->assertSame([[], ['u0012', 'u0011'], ['u0004']], $comments->map(
            fn (RecordComment $comment) => $comment->recordCommentMentions
                ->map(fn ($mention) => $mention->user?->code)->all(),
        )->all());
        $this->assertSame(
            ['2026-10-12 10:00', '2026-10-13 15:30', '2026-10-14 09:45'],
            $comments->map(
                fn (RecordComment $comment) => $comment->created_at?->setTimezone('Asia/Tokyo')->format('Y-m-d H:i'),
            )->all(),
        );
        $this->assertSame(3, $comments->whereNull('deleted_at')->count());
        $this->assertNotes($first->id);
    }

    /**
     * 見本のコメントの通知(宛先 ← 書いた人。対象は record-comment、本文を写さない params は空のオブジェクト)。
     */
    private function assertNotes(int $recordId): void
    {
        $notices = Notice::query()->with(['user', 'actor'])->where('type', 'records.comment')->orderBy('id')->get();

        $this->assertSame(
            [['u0012', 'u0004'], ['u0011', 'u0004'], ['u0004', 'u0012']],
            $notices->map(fn (Notice $notice) => [$notice->user?->code, $notice->actor?->code])->all(),
        );
        $this->assertSame(['record-comment'], $notices->pluck('target_type')->unique()->values()->all());
        $this->assertSame([$recordId], $notices->pluck('target_id')->unique()->values()->all());
        $this->assertSame([[], [], []], $notices->pluck('params')->all());
        $this->assertSame([null], $notices->pluck('read_at')->unique()->values()->all());
    }
}
