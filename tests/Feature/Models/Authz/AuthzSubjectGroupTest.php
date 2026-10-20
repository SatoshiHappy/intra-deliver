<?php

namespace Tests\Feature\Models\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の条件の組み方(docs の 設計/共通マスタ/ロールと認可/対象者.md: all か any)と用途(policy か selection。既定 policy。
 * UNIQUE (id, purpose) は指す側の表の複合外部キーの参照先)。
 */
final class AuthzSubjectGroupTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{SubjectCombination}>
     */
    public static function combinations(): array
    {
        return ['all' => [SubjectCombination::All], 'any' => [SubjectCombination::Any]];
    }

    /**
     * @return array<string, array{SubjectGroupPurpose}>
     */
    public static function purposes(): array
    {
        return ['policy' => [SubjectGroupPurpose::Policy], 'selection' => [SubjectGroupPurpose::Selection]];
    }

    #[DataProvider('combinations')]
    public function test_reads_back_the_combination_as_the_enum(SubjectCombination $combination): void
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);

        $this->assertSame($combination, $group->refresh()->combination);
    }

    public function test_rejects_a_combination_outside_the_list(): void
    {
        $row = [...AuthzSubjectGroup::factory()->makeOne()->getAttributes(), 'combination' => 'none'];

        $this->assertRejectedBy('authz_subject_groups_combination_check', fn () => DB::table('authz_subject_groups')->insert($row));
    }

    #[DataProvider('purposes')]
    public function test_reads_back_the_purpose_as_the_enum(SubjectGroupPurpose $purpose): void
    {
        $group = AuthzSubjectGroup::factory()->create(['purpose' => $purpose]);

        $this->assertSame($purpose, $group->refresh()->purpose);
    }

    public function test_the_purpose_defaults_to_policy(): void
    {
        $id = DB::table('authz_subject_groups')->insertGetId(AuthzSubjectGroup::factory()->makeOne()->getAttributes());

        $this->assertSame('policy', DB::table('authz_subject_groups')->where('id', $id)->value('purpose'));
    }

    public function test_rejects_a_purpose_outside_the_list(): void
    {
        $row = [...AuthzSubjectGroup::factory()->makeOne()->getAttributes(), 'purpose' => 'other'];

        $this->assertRejectedBy('authz_subject_groups_purpose_check', fn () => DB::table('authz_subject_groups')->insert($row));
    }

    public function test_the_id_and_purpose_pair_is_unique_under_its_name(): void
    {
        $definitions = array_column(DB::select(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'authz_subject_groups'::regclass and conname = 'authz_subject_groups_id_purpose_unique'",
        ), 'definition');

        $this->assertSame(['UNIQUE (id, purpose)'], $definitions);
    }

    public function test_a_composite_foreign_key_to_the_id_and_purpose_pair_rejects_another_purpose(): void
    {
        DB::statement('CREATE TABLE test_selection_references (authz_subject_group_id bigint NOT NULL, purpose varchar(20) NOT NULL, '
            .'CONSTRAINT test_selection_references_group_fk FOREIGN KEY (authz_subject_group_id, purpose) REFERENCES authz_subject_groups (id, purpose))');
        $selection = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Selection]);

        DB::table('test_selection_references')->insert(['authz_subject_group_id' => $selection->id, 'purpose' => 'selection']);

        $this->assertRejectedBy('test_selection_references_group_fk', fn () => DB::table('test_selection_references')->insert([
            'authz_subject_group_id' => $selection->id,
            'purpose' => 'policy',
        ]));
        $this->assertSame(1, DB::table('test_selection_references')->count());
    }
}
