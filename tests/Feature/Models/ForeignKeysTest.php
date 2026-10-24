<?php

namespace Tests\Feature\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\CommonMasterSchema;
use Tests\Support\MeetingSchema;
use Tests\Support\MenuSchema;
use Tests\Support\NoticeSchema;
use Tests\Support\RecordsCommonSchema;
use Tests\Support\RecordsSchema;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UploadSchema;
use Tests\Support\WorkflowAttachmentSchema;
use Tests\Support\WorkflowCaseHandlingSchema;
use Tests\Support\WorkflowContentTargetSchema;
use Tests\Support\WorkflowSchema;

/**
 * 外部キーの削除の動作とリレーション(docs の 設計/共通マスタ/共通の規則.md 7 節。自分の部品は CASCADE、ほかの事柄への参照は RESTRICT。
 * メニューの表も同じ = 設計/メニュー/表.md。業務データの入れ物の表も同じ = 設計/業務データの入れ物/データモデル/表.md 2 節・制約の分担と検査.md 7 節。
 * ワークフローの表も同じ = 設計/ワークフロー/データモデル/表.md 2 節、直列化と検査.md 6 節。通知の表も同じ = 設計/ワークフロー/案件の運用/通知.md の 2 節。
 * レコードの共通機能の表とアップロードの台帳も同じ = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.9 節。
 * 会議の表も同じ = 設計/業務ツール/共通データ.md の「専用の表」)。
 *
 * 外部キーの行はモジュールごとの表の形(tests/Support/ の CommonMasterSchema・MenuSchema・RecordsSchema・WorkflowSchema・
 * WorkflowCaseHandlingSchema・NoticeSchema・RecordsCommonSchema・UploadSchema・WorkflowAttachmentSchema・
 * WorkflowContentTargetSchema・MeetingSchema の FOREIGN_KEYS)に置き、ここで並べる(一時保存の添付の表 = 設計/ワークフロー/申請の周辺/データモデル.md の 3.3 節)。
 * 1 行 = 1 つの外部キー: 参照する側のモデル、factory の状態、列、参照先の表と列、削除の動作、
 * 参照する側のリレーション、参照先の側のリレーション(参照先のモジュールから参照する側のモジュールへ依存できないとき・同じ向きのリレーションが
 * 別の行にあるときは null)。削除の動作は、参照先の行を参照先の列のすべてで選んで消して確かめる(複合外部キーの参照先の列が id で終わらないため。
 * 列の値は DB から読み直した行から採る = 複合外部キーの列に生成列があるため)。
 */
final class ForeignKeysTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}> */
    private const FOREIGN_KEYS = [
        ...CommonMasterSchema::FOREIGN_KEYS,
        ...MenuSchema::FOREIGN_KEYS,
        ...RecordsSchema::FOREIGN_KEYS,
        ...RecordsCommonSchema::FOREIGN_KEYS,
        ...WorkflowSchema::FOREIGN_KEYS,
        ...WorkflowCaseHandlingSchema::FOREIGN_KEYS,
        ...NoticeSchema::FOREIGN_KEYS,
        ...UploadSchema::FOREIGN_KEYS,
        ...WorkflowAttachmentSchema::FOREIGN_KEYS,
        ...WorkflowContentTargetSchema::FOREIGN_KEYS,
        ...MeetingSchema::FOREIGN_KEYS,
    ];

    /**
     * 削除の動作を、参照先の 1 行を消して確かめられない外部キー(モデル (列))。削除の動作の種類は「設計どおりの外部キー」の照合が確かめ、
     * 範囲を止める働きは各表の制約のテスト(tests/Feature/Models/Records/)が確かめる。
     * - Record (record_module_id, parent_record_module_id)・Record (record_module_id, is_detail)・RecordReference (record_field_id, referenced_record_module_id)・
     *   RecordReference (record_field_id, is_multiple): 参照先の行を、
     *   同じ行の別の RESTRICT の外部キー(records (record_module_id)・record_references (record_module_id, record_field_id))も指すので、
     *   どちらの制約が先に拒むかが決まらない。
     * - RecordRule (record_module_id): 規則は同じ業務モジュールの項目を RESTRICT で指すので、業務モジュールを消す連鎖で項目が規則より先に消され、
     *   規則の外部キーに拒まれうる(設計/業務データの入れ物/データモデル/表.md 2.7)。
     * - WorkflowCaseNode (id, workflow_case_history_id)・WorkflowCaseHistory (workflow_case_id, workflow_case_node_id)・
     *   WorkflowCaseHistory (workflow_case_id, send_back_workflow_case_node_id): 案件のノードと履歴が互いを指すので、1 行の削除で削除の動作を確かめられない
     *   (設計/ワークフロー/データモデル/直列化と検査.md 6 節。連鎖の結果は tests/Feature/Models/Workflow/ の削除のテスト)。
     * - WorkflowCaseConfirmation (workflow_case_id, workflow_case_node_id)・WorkflowCaseConfirmation (user_id):
     *   確認した人はそのノードの確認対象者(複合外部キー)なので、ノードは確認対象者の行(連鎖で消える)と確認の履歴の行からも、
     *   人は確認対象者の行からも指され、そちらの制約が先に拒む(設計/ワークフロー/案件の運用/データモデル/表.md の 3.11 節)。
     * - WorkflowContent (record_module_id, target_record_field_id)・
     *   WorkflowContent (target_record_field_id, target_record_module_id):
     *   申請対象の項目の行を 2 本の RESTRICT の外部キーが指し、どちらの制約が先に拒むか(RI のトリガーの名前の順)に頼れない
     *   (項目の削除を拒むことは tests/Feature/Models/Workflow/ の申請対象の制約のテスト)。
     */
    private const DELETE_NOT_ISOLATED = [
        'Record (record_module_id, parent_record_module_id)',
        'Record (record_module_id, is_detail)',
        'RecordReference (record_field_id, referenced_record_module_id)',
        'RecordReference (record_field_id, is_multiple)',
        'RecordRule (record_module_id)',
        'WorkflowCaseNode (id, workflow_case_history_id)',
        'WorkflowCaseHistory (workflow_case_id, workflow_case_node_id)',
        'WorkflowCaseHistory (workflow_case_id, send_back_workflow_case_node_id)',
        'WorkflowCaseConfirmation (workflow_case_id, workflow_case_node_id)',
        'WorkflowCaseConfirmation (user_id)',
        'WorkflowContent (record_module_id, target_record_field_id)',
        'WorkflowContent (target_record_field_id, target_record_module_id)',
    ];

    /**
     * @return array<string, array{class-string<Model>, string|null, list<string>, string, list<string>, string, string|null}>
     */
    public static function restrictingForeignKeys(): array
    {
        return self::cases(fn (string $label, string $action): bool => $action === 'restrict' && ! in_array($label, self::DELETE_NOT_ISOLATED, true));
    }

    /**
     * @return array<string, array{class-string<Model>, string|null, list<string>, string, list<string>, string, string|null}>
     */
    public static function cascadingForeignKeys(): array
    {
        return self::cases(fn (string $label, string $action): bool => $action === 'cascade' && ! in_array($label, self::DELETE_NOT_ISOLATED, true));
    }

    /**
     * @return array<string, array{class-string<Model>, string|null, list<string>, string, list<string>, string, string|null}>
     */
    public static function notIsolatedForeignKeys(): array
    {
        return self::cases(fn (string $label): bool => in_array($label, self::DELETE_NOT_ISOLATED, true));
    }

    public function test_the_database_has_exactly_the_designed_foreign_keys(): void
    {
        $expected = array_map(
            fn (array $foreignKey): string => self::describe((new $foreignKey[0])->getTable(), $foreignKey[2], $foreignKey[3], $foreignKey[4], $foreignKey[5]),
            self::FOREIGN_KEYS,
        );

        $actual = $this->foreignKeysInDatabase();

        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    #[DataProvider('restrictingForeignKeys')]
    public function test_rejects_deleting_a_referenced_row_and_resolves_the_relations(string $model, ?string $state, array $columns, string $referencedTable, array $referencedColumns, string $relation, ?string $inverse): void
    {
        $row = $this->factory($model, $state)->createOne()->refresh();
        $table = $row->getTable();

        $this->assertRelationsResolve($row, $relation, $inverse);
        $this->assertRejectedBy(
            "{$table}_".implode('_', $columns).'_foreign',
            fn () => DB::table($referencedTable)->where($this->referencedKey($row, $columns, $referencedColumns))->delete(),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    #[DataProvider('cascadingForeignKeys')]
    public function test_deletes_the_part_with_its_owner_and_resolves_the_relations(string $model, ?string $state, array $columns, string $referencedTable, array $referencedColumns, string $relation, ?string $inverse): void
    {
        $row = $this->factory($model, $state)->createOne()->refresh();

        $this->assertRelationsResolve($row, $relation, $inverse);
        DB::table($referencedTable)->where($this->referencedKey($row, $columns, $referencedColumns))->delete();

        $this->assertModelMissing($row);
    }

    /**
     * 削除を確かめられない外部キー({@see self::DELETE_NOT_ISOLATED})も、リレーションは確かめる。
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    #[DataProvider('notIsolatedForeignKeys')]
    public function test_resolves_the_relations_of_a_foreign_key_whose_delete_is_not_isolated(string $model, ?string $state, array $columns, string $referencedTable, array $referencedColumns, string $relation, ?string $inverse): void
    {
        $this->assertRelationsResolve($this->factory($model, $state)->createOne(), $relation, $inverse);
    }

    public function test_the_foreign_keys_whose_delete_is_not_isolated_are_all_listed_foreign_keys(): void
    {
        $labels = array_map(fn (array $foreignKey): string => self::label($foreignKey), self::FOREIGN_KEYS);

        $this->assertSame([], array_values(array_diff(self::DELETE_NOT_ISOLATED, $labels)));
    }

    /**
     * 参照する側のリレーションが参照先の行を返し、参照先の側のリレーション(あれば)が参照する側の行を返す。
     */
    private function assertRelationsResolve(Model $row, string $relation, ?string $inverse): void
    {
        $referenced = $row->getRelationValue($relation);

        $this->assertInstanceOf(Model::class, $referenced);
        $this->assertSame($row->getAttribute($row->{$relation}()->getForeignKeyName()), $referenced->getKey());

        if ($inverse !== null) {
            $value = $referenced->getRelationValue($inverse);

            $this->assertTrue($value instanceof Model ? $value->is($row) : $value->contains($row));
        }
    }

    /**
     * 行の外部キーの値で、参照先の行を選ぶ条件(参照先の列 → 値)。
     *
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     * @return array<string, mixed>
     */
    private function referencedKey(Model $row, array $columns, array $referencedColumns): array
    {
        return array_combine($referencedColumns, array_map(fn (string $column): mixed => $row->getAttribute($column), $columns));
    }

    /**
     * @param  class-string<Model>  $model
     * @return Factory<Model>
     */
    private function factory(string $model, ?string $state): Factory
    {
        $factory = Factory::factoryForModel($model);

        return $state === null ? $factory : $factory->{$state}();
    }

    /**
     * DB にある、共通マスタ・メニュー・業務データの入れ物・ワークフロー・通知の表の外部キー(表・列・参照先・削除の動作)。
     *
     * @return array<int, string>
     */
    private function foreignKeysInDatabase(): array
    {
        $rows = DB::select(
            "select c.conrelid::regclass::text as table_name, c.confrelid::regclass::text as referenced_table, c.confdeltype as on_delete,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(c.conkey) with ordinality k(attnum, ord) join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum) as columns,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(c.confkey) with ordinality k(attnum, ord) join pg_attribute a on a.attrelid = c.confrelid and a.attnum = k.attnum) as referenced_columns
               from pg_constraint c
              where c.contype = 'f' and c.conrelid::regclass::text = any(?)",
            ['{'.implode(',', array_keys(self::schemaColumns())).'}'],
        );

        return array_map(fn (stdClass $row): string => self::describe(
            $row->table_name,
            explode(',', $row->columns),
            $row->referenced_table,
            explode(',', $row->referenced_columns),
            ['r' => 'restrict', 'c' => 'cascade'][$row->on_delete] ?? "other({$row->on_delete})",
        ), $rows);
    }

    /**
     * 外部キーを数える表(共通マスタ・メニュー・業務データの入れ物・ワークフロー・通知・アップロードの台帳・会議)の列の定義。
     *
     * @return array<string, array<string, string>>
     */
    private static function schemaColumns(): array
    {
        return CommonMasterSchema::COLUMNS + MenuSchema::COLUMNS + RecordsSchema::COLUMNS + RecordsCommonSchema::COLUMNS
            + WorkflowSchema::COLUMNS + WorkflowCaseHandlingSchema::COLUMNS + NoticeSchema::COLUMNS + UploadSchema::COLUMNS
            + WorkflowAttachmentSchema::COLUMNS + MeetingSchema::COLUMNS;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    private static function describe(string $table, array $columns, string $referencedTable, array $referencedColumns, string $onDelete): string
    {
        return "{$table} (".implode(', ', $columns).") → {$referencedTable} (".implode(', ', $referencedColumns).") on delete {$onDelete}";
    }

    /**
     * 外部キーの行の名前(モデル (列))。
     *
     * @param  array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}  $foreignKey
     */
    private static function label(array $foreignKey): string
    {
        return class_basename($foreignKey[0]).' ('.implode(', ', $foreignKey[2]).')';
    }

    /**
     * 条件(名前・削除の動作)に合う外部キーの行を、provider の行(モデル・状態・列・参照先の表と列・リレーション・参照先の側のリレーション)にする。
     *
     * @param  callable(string, string): bool  $keep
     * @return array<string, array{class-string<Model>, string|null, list<string>, string, list<string>, string, string|null}>
     */
    private static function cases(callable $keep): array
    {
        $cases = [];

        foreach (self::FOREIGN_KEYS as $foreignKey) {
            [$model, $state, $columns, $referencedTable, $referencedColumns, $action, $relation, $inverse] = $foreignKey;
            $label = self::label($foreignKey);

            if ($keep($label, $action)) {
                $cases[$label] = [$model, $state, $columns, $referencedTable, $referencedColumns, $relation, $inverse];
            }
        }

        return $cases;
    }
}
