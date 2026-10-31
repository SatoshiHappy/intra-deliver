<?php

namespace Tests\Feature\Migrations;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 参照制限の形への移しのマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.1 の「参照制限の列と今ある行」・2.8、
 * レコードの共通機能/データモデル/表.md 2.1.1 の「移行の例外」): all → 既定 偽・チェック 外れ、related → 既定 真・親の無いレコードのチェック 付き
 * (明細の行は外れ)。最新の版だけに row_access(参照者は空)が入り、前の版は変わらない。参照者の行は無い。up の後の最初の保存でチェックだけを変えると
 * 版ができる。down は row_access_scope を戻し(既定が真なら related)、版の row_access は残す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 * row_access は jsonb でキーの順が保たれないので、キーの順に依らない比べ方(assertEquals)で見る。
 */
final class RowAccessRestrictionMigrationTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private const MIGRATIONS = [
        '2026_10_31_100001_replace_row_access_scope_with_row_access_restricted.php',
        '2026_10_31_100002_create_record_row_access_viewers_table.php',
    ];

    public function test_up_maps_the_scope_to_the_default_and_the_records_and_adds_the_latest_snapshot(): void
    {
        $this->migrator()->reset($this->paths());
        $before = $this->scopedRows();

        $this->migrator()->run($this->paths());

        $modules = DB::table('record_modules')->whereIn('id', [$before['all'], $before['related'], $before['child']])
            ->pluck('row_access_restricted_by_default', 'id');
        $this->assertSame([false, true, false], [
            $modules[$before['all']], $modules[$before['related']], $modules[$before['child']],
        ]);
        $records = DB::table('records')->pluck('row_access_restricted', 'id');
        $this->assertSame([false, true, false], [
            $records[$before['open']], $records[$before['restricted']], $records[$before['row']],
        ]);
        $this->assertEquals(
            [['restricted' => true, 'viewers' => []], ['restricted' => false, 'viewers' => []]],
            [$this->rowAccessOf($before['restricted'], 2), $this->rowAccessOf($before['open'], 1)],
        );
        $this->assertNull($this->rowAccessOf($before['restricted'], 1));
        $this->assertSame(0, DB::table('record_row_access_viewers')->count());
        $this->assertFalse(Schema::hasColumn('record_modules', 'row_access_scope'));
    }

    public function test_the_first_save_after_up_that_only_changes_the_restriction_makes_a_revision(): void
    {
        $this->migrator()->reset($this->paths());
        $before = $this->scopedRows();
        $this->migrator()->run($this->paths());

        app(UpdateRecord::class)->execute(
            $this->contextFor(), 'm_all', $before['open'], new RecordInput(rowAccessRestricted: true), null,
        );

        $latest = RecordRevision::query()->where('record_id', $before['open'])->orderByDesc('revision')->firstOrFail();
        $this->assertEquals(
            [2, ['restricted' => true, 'viewers' => []]],
            [$latest->revision, $latest->snapshot['row_access'] ?? null],
        );
    }

    public function test_down_restores_the_scope_and_keeps_the_row_access_of_the_snapshots(): void
    {
        $this->migrator()->reset($this->paths());
        $before = $this->scopedRows();
        $this->migrator()->run($this->paths());

        $this->migrator()->reset($this->paths());

        $this->assertSame(
            ['all', 'related', 'all'],
            DB::table('record_modules')->whereIn('id', [$before['all'], $before['related'], $before['child']])
                ->orderBy('id')->pluck('row_access_scope')->all(),
        );
        $this->assertFalse(Schema::hasColumn('record_modules', 'row_access_restricted_by_default'));
        $this->assertFalse(Schema::hasColumn('records', 'row_access_restricted'));
        $this->assertFalse(Schema::hasTable('record_row_access_viewers'));
        $this->assertEquals(['restricted' => true, 'viewers' => []], $this->rowAccessOf($before['restricted'], 2));
        $this->assertArrayNotHasKey('row_access', $this->snapshotOf($before['restricted'], 1));
    }

    /**
     * 戻した後の形(row_access_scope)で、all と related の業務モジュール(related に明細のモジュール)、それぞれの親の無いレコード、明細の行、
     * 版を作る(related のレコードは版 2 つ、all のレコードは版 1 つ)。列が無いのでモデルの factory を通さずに書く。
     *
     * @return array{all: int, related: int, child: int, open: int, restricted: int, row: int}
     */
    private function scopedRows(): array
    {
        $all = $this->recordModule('m_all');
        $related = $this->recordModule('m_related');
        DB::table('record_modules')->where('id', $related->id)->update(['row_access_scope' => 'related']);
        $child = RecordModule::factory()->create([
            'parent_record_module_id' => $related->id, 'icon' => null, 'is_attachment_enabled' => false,
        ]);
        $record = fn (int $moduleId, ?int $parentId = null, ?int $parentModuleId = null) => DB::table('records')
            ->insertGetId([
                'record_module_id' => $moduleId,
                'parent_record_module_id' => $parentModuleId,
                'parent_record_id' => $parentId,
                'sort_key' => $parentId === null ? 0 : 1,
                'field_values' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $open = $record($all->id);
        $restricted = $record($related->id);
        $row = $record($child->id, $restricted, $related->id);
        $this->revision($all->id, $open, 1);
        $this->revision($related->id, $restricted, 1);
        $this->revision($related->id, $restricted, 2);

        return [
            'all' => $all->id, 'related' => $related->id, 'child' => $child->id,
            'open' => $open, 'restricted' => $restricted, 'row' => $row,
        ];
    }

    private function revision(int $moduleId, int $recordId, int $revision): void
    {
        $snapshot = ['values' => [], 'references' => [], 'details' => [], 'attachments' => []];
        DB::table('record_revisions')->insert([
            'record_module_id' => $moduleId,
            'record_id' => $recordId,
            'revision' => $revision,
            'action' => $revision === 1 ? 'create' : 'update',
            'snapshot' => json_encode($snapshot),
            'labels' => json_encode(['fields' => [], 'options' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(int $recordId, int $revision): array
    {
        $json = DB::table('record_revisions')->where('record_id', $recordId)->where('revision', $revision)
            ->value('snapshot');
        $snapshot = json_decode((string) $json, true);

        return is_array($snapshot) ? $snapshot : [];
    }

    private function rowAccessOf(int $recordId, int $revision): mixed
    {
        return $this->snapshotOf($recordId, $revision)['row_access'] ?? null;
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_map(fn (string $file) => database_path('migrations/'.$file), self::MIGRATIONS);
    }
}
