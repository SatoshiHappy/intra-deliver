<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * コンテンツの申請対象の制約(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節、直列化と検査.md の 6 節の「コンテンツの申請対象」):
 * 同じ業務モジュールを指す 2 つ目のコンテンツ、申請対象の 2 列の片方だけが NULL の行、申請書の業務モジュールでない業務モジュールの項目・
 * 他モジュール参照でない項目・参照先の写しが項目の参照先と違う行を拒み、両方 NULL の行と両方を持つ正しい行は受ける。
 * 申請対象に使っている項目は消せない。業務処理を通さず factory(モデル)と SQL で行を作る。
 */
final class WorkflowContentTargetTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** 申請書の業務モジュールの項目であることを止める外部キー */
    private const MODULE_FOREIGN = 'workflow_contents_record_module_id_target_record_field_id_foreign';

    /** 申請対象の 2 列の組(MATCH FULL)と、他モジュール参照の項目で参照先が合うことを止める外部キー */
    private const TARGET_FOREIGN = 'workflow_contents_target_record_field_id_target_record_module_id_foreign';

    public function test_rejects_a_second_content_for_the_same_record_module(): void
    {
        $content = WorkflowContent::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_contents_record_module_id_unique',
            fn () => WorkflowContent::factory()->createOne(['record_module_id' => $content->record_module_id]),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nulledTargetColumns(): array
    {
        return ['項目だけ' => ['target_record_module_id'], '参照先だけ' => ['target_record_field_id']];
    }

    #[DataProvider('nulledTargetColumns')]
    public function test_rejects_a_target_with_only_one_of_the_two_columns(string $nulled): void
    {
        $row = WorkflowContent::factory()->withTarget()->makeOne()->getAttributes();

        $this->assertRejectedBy(
            self::TARGET_FOREIGN,
            fn () => DB::table('workflow_contents')->insert([...$row, $nulled => null]),
        );
    }

    public function test_accepts_a_content_without_a_target_and_one_with_a_valid_target(): void
    {
        $without = WorkflowContent::factory()->createOne()->refresh();
        $with = WorkflowContent::factory()->withTarget()->createOne()->refresh();
        $field = RecordField::query()->findOrFail($with->target_record_field_id);

        $this->assertSame([null, null], [$without->target_record_field_id, $without->target_record_module_id]);
        $this->assertSame(
            [$with->record_module_id, $field->referenced_record_module_id],
            [$field->record_module_id, $with->target_record_module_id],
        );
    }

    /**
     * 申請対象の項目の作り方(申請書の業務モジュールの id を受ける)と、拒む外部キー。
     *
     * @return array<string, array{Closure(int): RecordField, string}>
     */
    public static function invalidTargetFields(): array
    {
        return [
            'ほかの業務モジュールの他モジュール参照の項目' => [
                fn (int $moduleId) => RecordField::factory()->reference()->createOne(),
                self::MODULE_FOREIGN,
            ],
            '明細のモジュールの他モジュール参照の項目' => [
                fn (int $moduleId) => RecordField::factory()->reference()
                    ->for(RecordModule::factory()->state(['parent_record_module_id' => $moduleId]))->createOne(),
                self::MODULE_FOREIGN,
            ],
            '他モジュール参照でない項目' => [
                fn (int $moduleId) => RecordField::factory()->createOne(['record_module_id' => $moduleId]),
                self::TARGET_FOREIGN,
            ],
        ];
    }

    /**
     * 参照先の写しは、他モジュール参照の項目なら項目の参照先、そうでなければ別の業務モジュール(項目の側に合う行が無い)。
     *
     * @param  Closure(int): RecordField  $field
     */
    #[DataProvider('invalidTargetFields')]
    public function test_rejects_a_target_field_that_is_not_a_reference_field_of_the_form_module(
        Closure $field,
        string $constraint,
    ): void {
        $module = RecordModule::factory()->createOne();
        $target = $field($module->id);
        $copy = $target->referenced_record_module_id ?? RecordModule::factory()->createOne()->id;

        $this->assertRejectedBy($constraint, fn () => WorkflowContent::factory()->createOne([
            'record_module_id' => $module->id,
            'target_record_field_id' => $target->id,
            'target_record_module_id' => $copy,
        ]));
    }

    public function test_rejects_a_copy_of_the_referenced_module_that_differs_from_the_field(): void
    {
        $row = WorkflowContent::factory()->withTarget()->makeOne()->getAttributes();
        $otherModuleId = RecordModule::factory()->createOne()->id;

        $this->assertRejectedBy(
            self::TARGET_FOREIGN,
            fn () => DB::table('workflow_contents')->insert([...$row, 'target_record_module_id' => $otherModuleId]),
        );
    }

    /**
     * 項目の行を指す外部キーは 2 本あり、どちらが先に拒むかは決まらないので、外部キーの違反で拒まれて項目が残ることを確かめる。
     */
    public function test_rejects_deleting_a_field_that_a_content_uses_as_its_target(): void
    {
        $content = WorkflowContent::factory()->withTarget()->createOne();
        $field = DB::table('record_fields')->where('id', $content->target_record_field_id);

        $this->assertThrows(
            fn () => DB::transaction(fn () => $field->clone()->delete()),
            QueryException::class,
            'foreign key constraint "workflow_contents_',
        );

        $this->assertTrue($field->clone()->exists());
    }
}
