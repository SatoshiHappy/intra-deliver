<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Records\RecordHeadingGroups;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 見出しのまとまりの束ね方と管理の画面の並び(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 1 節・
 * 6 節の「束ね方」、詳細の画面/README.md の 6.2 節の `headings`、業務モジュールの管理.md の 6 節の `headings`・`items`)。
 */
final class RecordHeadingGroupsTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_groups_the_fields_by_the_headings_sort_keys(): void
    {
        $module = $this->recordModule('projects');
        $this->fields($module, ['f1' => 1, 'f2' => 2, 'f3' => 3, 'f4' => 6]);
        $a = $this->heading($module, 'A', 3);
        $b = $this->heading($module, 'B', 5);

        $this->assertSame([
            ['id' => null, 'name' => null, 'fieldCodes' => ['f1', 'f2']],
            ['id' => $a->id, 'name' => 'A', 'fieldCodes' => ['f3']],
            ['id' => $b->id, 'name' => 'B', 'fieldCodes' => ['f4']],
        ], app(RecordShowPanelQuery::class)->headings($module->id));
    }

    public function test_a_module_without_headings_is_one_head_group_and_without_fields_is_empty(): void
    {
        $module = $this->recordModule('projects');
        $this->fields($module, ['f1' => 1, 'f2' => 0]);
        $groups = app(RecordHeadingGroups::class);

        $this->assertSame([['id' => null, 'name' => null, 'fieldCodes' => ['f2', 'f1']]], $groups->groups($module->id));
        $this->assertSame([], $groups->groups($this->recordModule('empties')->id));
    }

    public function test_a_heading_without_fields_is_left_out_but_the_admin_props_keep_it(): void
    {
        $module = $this->recordModule('projects');
        $empty = $this->heading($module, '空', 0);
        $filled = $this->heading($module, '中身', 10);
        $this->fields($module, ['f1' => 10]);
        $groups = app(RecordHeadingGroups::class);

        $this->assertSame(
            [['id' => $filled->id, 'name' => '中身', 'fieldCodes' => ['f1']]],
            $groups->groups($module->id),
        );
        $this->assertSame([
            'headings' => [
                ['id' => $empty->id, 'name' => '空', 'fieldCount' => 0],
                ['id' => $filled->id, 'name' => '中身', 'fieldCount' => 1],
            ],
            'items' => [
                ['kind' => 'heading', 'id' => $empty->id],
                ['kind' => 'heading', 'id' => $filled->id],
                ['kind' => 'field', 'code' => 'f1'],
            ],
        ], $groups->adminProps($module->id));
    }

    public function test_on_the_same_sort_key_the_heading_comes_first(): void
    {
        $module = $this->recordModule('projects');
        $this->fields($module, ['f1' => 5]);
        $heading = $this->heading($module, 'A', 5);

        $this->assertSame(
            [['id' => $heading->id, 'name' => 'A', 'fieldCodes' => ['f1']]],
            app(RecordHeadingGroups::class)->groups($module->id),
        );
    }

    public function test_details_fields_belong_to_no_heading_and_are_not_counted(): void
    {
        $module = $this->recordModule('projects');
        $this->fields($module, ['f1' => 1, 'f2' => 3]);
        $this->detailsField($module, 'rows', ['sort_key' => 2]);
        $heading = $this->heading($module, 'A', 0);
        $groups = app(RecordHeadingGroups::class);

        $this->assertSame(
            [['id' => $heading->id, 'name' => 'A', 'fieldCodes' => ['f1', 'f2']]],
            $groups->groups($module->id),
        );
        $props = $groups->adminProps($module->id);
        $this->assertSame([['id' => $heading->id, 'name' => 'A', 'fieldCount' => 2]], $props['headings']);
        $this->assertSame([
            ['kind' => 'heading', 'id' => $heading->id],
            ['kind' => 'field', 'code' => 'f1'],
            ['kind' => 'field', 'code' => 'rows'],
            ['kind' => 'field', 'code' => 'f2'],
        ], $props['items']);
    }

    /**
     * @param  array<string, int>  $sortKeys  項目のコード → 並び順
     */
    private function fields(RecordModule $module, array $sortKeys): void
    {
        foreach ($sortKeys as $code => $sortKey) {
            $this->field($module, $code, attributes: ['sort_key' => $sortKey]);
        }
    }

    private function heading(RecordModule $module, string $name, int $sortKey): RecordFieldHeading
    {
        return RecordFieldHeading::factory()->create([
            'record_module_id' => $module->id,
            'name' => $name,
            'sort_key' => $sortKey,
        ]);
    }
}
