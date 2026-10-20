<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節・同じ階層の README.md の 7 節)。
 * 外部キーの削除の動作とリレーションは ForeignKeysTest、列と名前は SchemaTest・ConstraintNamesTest。
 */
final class RecordRevisionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function nonObjectJson(): array
    {
        return [
            '配列' => ['[]'],
            '文字列' => ['"text"'],
            '数値' => ['1'],
        ];
    }

    public function test_reads_the_action_as_an_enum_and_the_snapshot_and_labels_as_arrays(): void
    {
        $revision = RecordRevision::factory()->create()->refresh();

        $this->assertSame(RecordRevisionAction::Create, $revision->action);
        $this->assertSame(1, $revision->revision);
        $this->assertNotNull($revision->snapshot);
        $this->assertNotNull($revision->labels);
        // jsonb はキーの順を保たないので、キーの集まりで比べる
        $snapshotKeys = ['values', 'references', 'details', 'attachments'];
        $this->assertEqualsCanonicalizing($snapshotKeys, array_keys($revision->snapshot));
        $this->assertEqualsCanonicalizing(['fields', 'options'], array_keys($revision->labels));
    }

    public function test_the_factory_writes_the_map_positions_as_json_objects(): void
    {
        $revision = RecordRevision::factory()->create();

        $types = DB::selectOne(
            "SELECT jsonb_typeof(snapshot->'values') AS v, jsonb_typeof(snapshot->'references') AS r,"
            ." jsonb_typeof(snapshot->'details') AS d, jsonb_typeof(snapshot->'attachments') AS a,"
            ." jsonb_typeof(labels->'fields') AS f, jsonb_typeof(labels->'options') AS o"
            .' FROM record_revisions WHERE id = ?',
            [$revision->id],
        );

        $this->assertSame(
            ['object', 'object', 'object', 'array', 'object', 'object'],
            [$types->v, $types->r, $types->d, $types->a, $types->f, $types->o],
        );
    }

    public function test_rejects_the_same_revision_number_twice_for_a_record(): void
    {
        $first = RecordRevision::factory()->create();

        $this->assertRejectedBy(
            'record_revisions_record_id_revision_unique',
            fn () => RecordRevision::factory()->create($this->sameRecord($first)),
        );
    }

    public function test_accepts_the_same_revision_number_for_another_record(): void
    {
        RecordRevision::factory()->create();

        $this->assertModelExists(RecordRevision::factory()->create());
    }

    #[DataProvider('revisionsBelowOne')]
    public function test_rejects_a_revision_number_below_one(int $revision): void
    {
        $this->assertRejectedBy(
            'record_revisions_revision_check',
            fn () => RecordRevision::factory()->update()->create(['revision' => $revision]),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function revisionsBelowOne(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    public function test_rejects_an_action_outside_create_and_update(): void
    {
        $row = RecordRevision::factory()->makeOne()->getAttributes();

        $this->assertRejectedBy(
            'record_revisions_action_check',
            fn () => DB::table('record_revisions')->insert([...$row, 'action' => 'delete']),
        );
    }

    public function test_rejects_a_create_revision_other_than_the_first_and_accepts_an_update_first_revision(): void
    {
        $this->assertRejectedBy(
            'record_revisions_action_revision_check',
            fn () => RecordRevision::factory()->create(['revision' => 2]),
        );

        $first = RecordRevision::factory()->update()->create();

        $this->assertSame([1, RecordRevisionAction::Update], [$first->revision, $first->action]);
    }

    public function test_rejects_a_step_name_or_a_proxy_on_a_revision_without_an_operation(): void
    {
        $constraint = 'record_revisions_origin_operation_origin_node_name_principal_user_id_check';

        $this->assertRejectedBy($constraint, fn () => RecordRevision::factory()->create(['origin_node_name' => '課長']));
        $this->assertRejectedBy(
            $constraint,
            fn () => RecordRevision::factory()->byUser()->create(['principal_user_id' => User::factory()]),
        );
    }

    public function test_accepts_an_operation_without_a_step_name_and_a_proxy_with_an_operation(): void
    {
        $this->assertModelExists(RecordRevision::factory()->fromOperation('AI(議事録の作成)', null)->create());
        $this->assertModelExists(RecordRevision::factory()->byProxy()->create());
    }

    public function test_rejects_an_empty_operation_or_step_name(): void
    {
        $this->assertRejectedBy(
            'record_revisions_origin_operation_check',
            fn () => RecordRevision::factory()->create(['origin_operation' => '']),
        );
        $this->assertRejectedBy(
            'record_revisions_origin_node_name_check',
            fn () => RecordRevision::factory()->fromOperation()->create(['origin_node_name' => '']),
        );
    }

    public function test_rejects_a_proxy_equal_to_the_executor_and_a_proxy_without_an_executor(): void
    {
        $constraint = 'record_revisions_principal_user_id_user_id_check';
        $user = User::factory()->create();

        $this->assertRejectedBy($constraint, fn () => RecordRevision::factory()->fromOperation()
            ->create(['user_id' => $user->id, 'principal_user_id' => $user->id]));
        $this->assertRejectedBy($constraint, fn () => RecordRevision::factory()->fromOperation()
            ->create(['principal_user_id' => User::factory()]));
    }

    #[DataProvider('nonObjectJson')]
    public function test_rejects_a_snapshot_or_labels_that_is_not_a_json_object(string $json): void
    {
        $row = RecordRevision::factory()->makeOne()->getAttributes();

        $this->assertRejectedBy(
            'record_revisions_snapshot_check',
            fn () => DB::table('record_revisions')->insert([...$row, 'snapshot' => $json]),
        );
        $this->assertRejectedBy(
            'record_revisions_labels_check',
            fn () => DB::table('record_revisions')->insert([...$row, 'labels' => $json]),
        );
    }

    public function test_rejects_a_record_of_another_module(): void
    {
        $record = Record::factory()->create();
        $otherModule = RecordModule::factory()->create();

        $this->assertRejectedBy(
            'record_revisions_record_module_id_record_id_foreign',
            fn () => RecordRevision::factory()
                ->create(['record_module_id' => $otherModule->id, 'record_id' => $record->id]),
        );
    }

    public function test_deleting_the_record_deletes_its_revisions(): void
    {
        $revision = RecordRevision::factory()->create();

        DB::table('records')->where('id', $revision->record_id)->delete();

        $this->assertModelMissing($revision);
    }

    /**
     * 同じレコードを指す列(版の番号は既定のまま)。
     *
     * @return array{record_module_id: int, record_id: int}
     */
    private function sameRecord(RecordRevision $revision): array
    {
        return ['record_module_id' => $revision->record_module_id, 'record_id' => $revision->record_id];
    }
}
