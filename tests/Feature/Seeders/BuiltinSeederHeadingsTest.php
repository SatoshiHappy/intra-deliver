<?php

namespace Tests\Feature\Seeders;

use App\Actions\Records\DeleteRecordFieldHeading;
use App\Actions\Records\UpdateRecordFieldHeading;
use App\Enums\Builtin\Meeting;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Database\Seeders\BuiltinSeeder;
use Tests\Support\TransactionalTestCase;

/**
 * 組み込みの会議の見出し(docs の 設計/業務ツール/会議/README.md の 1.2)。見出しは業務モジュールを作った回だけ足し、
 * もう一度流しても管理者が消した・名前を変えた見出しを戻さない。テスト DB の初期データ(DatabaseSeeder)が入れた定義を確かめる。
 */
final class BuiltinSeederHeadingsTest extends TransactionalTestCase
{
    /** 項目と見出しの 1 本の並び(見出しは「# 名前」、項目はコード) */
    private const LAYOUT = [
        'title', 'held_at', 'related_parties', 'project', 'purge_due_on',
        '# 議事録', 'summary', 'topics', 'decisions', 'open_issues', 'next_actions',
        '# 材料', 'agenda', 'notes',
    ];

    /** 見出し「議事録」は要約の前、「材料」はアジェンダの前。頭の 5 項目は見出しの前 = 固定の「基本情報」 */
    public function test_the_headings_split_the_fields_into_minutes_and_materials(): void
    {
        $this->assertSame(self::LAYOUT, $this->layout());
    }

    public function test_a_second_run_keeps_two_headings_in_the_same_order(): void
    {
        $this->seedBuiltins();

        $this->assertSame(self::LAYOUT, $this->layout());
        $this->assertSame(2, RecordFieldHeading::query()->where('record_module_id', $this->meeting()->id)->count());
    }

    /** 管理者が消した見出しは戻さず、ほかの並びも動かさない */
    public function test_a_second_run_does_not_restore_a_deleted_heading(): void
    {
        app(DeleteRecordFieldHeading::class)->execute(Meeting::CODE, $this->headingNamed('材料')->id);
        $before = $this->layout();

        $this->seedBuiltins();

        $this->assertSame($before, $this->layout());
        $this->assertNotContains('# 材料', $this->layout());
    }

    /** 管理者が名前を変えた見出しは、元の名前で足し直さない */
    public function test_a_second_run_does_not_restore_a_renamed_heading(): void
    {
        $heading = $this->headingNamed('議事録');
        app(UpdateRecordFieldHeading::class)->execute(Meeting::CODE, $heading->id, ['name' => '記録']);
        $before = $this->layout();

        $this->seedBuiltins();

        $this->assertSame($before, $this->layout());
        $this->assertContains('# 記録', $this->layout());
        $this->assertNotContains('# 議事録', $this->layout());
    }

    private function seedBuiltins(): void
    {
        app(BuiltinSeeder::class)->run();
    }

    private function meeting(): RecordModule
    {
        return RecordModule::query()->where('code', Meeting::CODE)->sole();
    }

    private function headingNamed(string $name): RecordFieldHeading
    {
        return RecordFieldHeading::query()->where('record_module_id', $this->meeting()->id)
            ->where('name', $name)->sole();
    }

    /**
     * 会議の項目と見出しの 1 本の並び(sort_key の順。見出しは「# 名前」、項目はコード)。
     *
     * @return list<string>
     */
    private function layout(): array
    {
        $moduleId = $this->meeting()->id;
        $labels = [];

        foreach (RecordField::query()->where('record_module_id', $moduleId)->get() as $field) {
            $labels[$field->sort_key] = $field->code;
        }

        foreach (RecordFieldHeading::query()->where('record_module_id', $moduleId)->get() as $heading) {
            $labels[$heading->sort_key] = '# '.$heading->name;
        }

        ksort($labels);

        return array_values($labels);
    }
}
