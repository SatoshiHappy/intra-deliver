<?php

namespace Tests\Feature\Migrations;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DropsUserOrDepartmentFields;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者に用途を足すマイグレーションの down と、その後の up(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups の purpose:
 * 既存の行は policy。down は用途 selection の対象者(条件・表示名を含む)を消してから列と制約を消す)。
 * 列と制約の down・up は、後のマイグレーションを含めて正しい順の rollback と migrate で確かめる。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AuthzSubjectGroupPurposeMigrationTest extends TransactionalTestCase
{
    use DropsUserOrDepartmentFields;

    private const MIGRATION = '2026_10_13_000001_add_purpose_to_authz_subject_groups_table.php';

    public function test_down_removes_the_column_and_constraints(): void
    {
        $this->rollBackFromThisMigration();

        $this->assertFalse(Schema::hasColumn('authz_subject_groups', 'purpose'));
        $this->assertSame([], $this->constraintDefinitions());
    }

    public function test_up_after_down_makes_the_existing_rows_policy_and_adds_the_constraints_again(): void
    {
        $this->rollBackFromThisMigration();
        $id = DB::table('authz_subject_groups')->insertGetId(['combination' => 'all', 'sort_key' => 0]);

        $this->migrator()->run([database_path('migrations')]);

        $this->assertSame('policy', DB::table('authz_subject_groups')->where('id', $id)->value('purpose'));
        $this->assertSame([], DB::table('authz_subject_groups')->where('purpose', '<>', 'policy')->pluck('id')->all());
        $this->assertSame(
            [
                'authz_subject_groups_id_purpose_unique' => 'UNIQUE (id, purpose)',
                'authz_subject_groups_purpose_check' => "CHECK (((purpose)::text = ANY ((ARRAY['policy'::character varying, 'selection'::character varying])::text[])))",
            ],
            $this->constraintDefinitions(),
        );
    }

    public function test_down_deletes_the_selection_subject_groups_with_their_conditions_and_keeps_the_policy_ones(): void
    {
        $selectionId = app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition(SelectionConditionKind::User, User::query()->where('code', 'u0001')->sole()->id)], ['ja' => 'down の見本']);
        $policyIds = AuthzSubjectGroup::query()->where('purpose', SubjectGroupPurpose::Policy)->orderBy('id')->pluck('id')->all();

        $this->rollBackFromThisMigration();

        $this->assertSame($policyIds, DB::table('authz_subject_groups')->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            [0, 0],
            [DB::table('authz_subject_conditions')->where('authz_subject_group_id', $selectionId)->count(), DB::table('authz_subject_group_translations')->where('authz_subject_group_id', $selectionId)->count()],
        );
    }

    /**
     * このマイグレーションとその後のマイグレーションを、正しい順に down する(後のマイグレーションの複合外部キー
     * (authz_subject_group_id, purpose) が一意制約 (id, purpose) に依存するので、このマイグレーションだけの down は成り立たない)。
     * 戻す数は migrations の表から数える(後のマイグレーションが増えても固定値を直さない)。migrate:rollback --step と同じマイグレーターの呼び出し。
     */
    private function rollBackFromThisMigration(): void
    {
        // 後のマイグレーションの段 3a のワークフローの down は、フロー・案件の行があると止まる(docs の 設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md 5 節)。
        // 初期データのワークフローの行を TX の中で消してから戻す。
        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');
        // 後のマイグレーションの項目の検査制約を狭める down は、相対の既定値か 4000 字を超える複数行の最大の文字数の
        // 項目があると止まる(初期データの組み込みの会議 = docs の 設計/業務ツール/会議/README.md の 1.2)。TX の中で外してから戻す。
        DB::table('record_fields')->whereRaw("jsonb_typeof(default_value) = 'object'")
            ->update(['default_value' => null]);
        DB::table('record_fields')->where('max_length', '>', 4000)->update(['max_length' => null]);
        // 後のマイグレーションの申請対象の列の down は、申請対象を持つコンテンツの行があると止まる(初期データのコンテンツ)。
        // TX の中で申請対象を外してから戻す(docs の 設計/ワークフロー/初期データ.md の 3 節)。
        DB::table('workflow_contents')->update(['target_record_field_id' => null, 'target_record_module_id' => null]);
        // 後のマイグレーションの型の一覧を狭める down は、型「ユーザーと組織」の項目があると止まる。TX の中で消してから戻す
        $this->dropUserOrDepartmentFields();
        // 組み込みの旗の down は、旗の付いた行があると止まる。TX の中で旗を外してから戻す
        foreach (['record_modules', 'record_fields', 'record_field_options'] as $table) {
            DB::table($table)->update(['is_builtin' => false]);
        }
        $steps = DB::table('migrations')->where('migration', '>=', pathinfo(self::MIGRATION, PATHINFO_FILENAME))->count();

        $rolledBack = $this->migrator()->rollback([database_path('migrations')], ['step' => $steps]);

        $this->assertCount($steps, $rolledBack);
    }

    /**
     * Laravel のマイグレーター(rollback・run は、パスに渡したディレクトリのマイグレーションが対象)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    /**
     * 用途の検査制約と一意制約の、名前 → 定義(名前の順)。
     *
     * @return array<string, string>
     */
    private function constraintDefinitions(): array
    {
        $rows = DB::select(
            "select conname as name, pg_get_constraintdef(oid) as definition from pg_constraint
              where conrelid = 'authz_subject_groups'::regclass and conname in ('authz_subject_groups_purpose_check', 'authz_subject_groups_id_purpose_unique')
              order by conname",
        );

        return array_column($rows, 'definition', 'name');
    }
}
