<?php

namespace Tests\Feature\Migrations;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\DropsUserOrDepartmentFields;
use Tests\Support\TransactionalTestCase;

/**
 * 型「ユーザーと組織」のマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、ADR 0028 の影響の DB): up の後は検査制約 3 つが
 * 型 user_or_department と、その型の is_multiple・grants_row_access を受け、ほかの型の is_multiple・grants_row_access は今と同じに拒む。
 * down はその型を拒む元の式に戻す(その型の項目の行があれば失敗する)。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class UserOrDepartmentTypeMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use DropsUserOrDepartmentFields;

    private const MIGRATION = '2026_10_26_100001_add_user_or_department_type_to_record_fields.php';

    public function test_up_accepts_the_type_with_multiple_and_the_row_access_flag(): void
    {
        $field = RecordField::factory()->create([
            'type' => RecordFieldType::UserOrDepartment,
            'is_multiple' => true,
            'grants_row_access' => true,
        ]);

        $this->assertSame(RecordFieldType::UserOrDepartment, $field->refresh()->type);
        $this->assertRejectedBy(
            'record_fields_type_is_multiple_check',
            fn () => RecordField::factory()->create(['type' => RecordFieldType::Integer, 'is_multiple' => true]),
        );
        $this->assertRejectedBy(
            'record_fields_type_grants_row_access_check',
            fn () => RecordField::factory()->create(['type' => RecordFieldType::String, 'grants_row_access' => true]),
        );
    }

    public function test_down_rejects_the_type_and_up_accepts_it_again(): void
    {
        $this->dropUserOrDepartmentFields();
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertRejectedBy('record_fields_type_check', fn () => $this->insertField([]));

        $this->migrator()->run([$this->migrationPath()]);
        $this->insertField(['is_multiple' => true, 'grants_row_access' => true]);

        $this->assertSame(1, DB::table('record_fields')->where('type', 'user_or_department')->count());
    }

    public function test_down_fails_while_a_field_of_the_type_remains(): void
    {
        $this->dropUserOrDepartmentFields();
        $this->insertField([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('型 user_or_department の項目が 1 件残っているので戻せない');
        $this->migrator()->reset([$this->migrationPath()]);
    }

    /**
     * 型 user_or_department の項目の行を SQL で入れる(モデルの列挙のキャストを通さない)。
     *
     * @param  array<string, bool>  $flags
     */
    private function insertField(array $flags): void
    {
        $attributes = RecordField::factory()->makeOne()->getAttributes();
        DB::table('record_fields')->insert([...$attributes, 'type' => 'user_or_department', ...$flags]);
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function migrationPath(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }
}
