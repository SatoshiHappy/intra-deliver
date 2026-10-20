<?php

namespace Tests\Feature\Models\Upload;

use App\Models\Upload\Upload;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * アップロードの台帳(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.2 節・同じ階層の README.md の 7 節:
 * 0 バイトの行・空の path・mime を書けない、同じ path を 2 行書けない、実体を消した日時と期限の日は両方あるか両方無い)と、
 * 制約と索引の名前。列と外部キーは SchemaTest・ForeignKeysTest が確かめる。
 */
final class UploadTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_reads_the_purge_columns_as_a_date_time_and_a_date(): void
    {
        $upload = Upload::factory()->purged()->create()->refresh();

        $this->assertInstanceOf(CarbonInterface::class, $upload->purged_at);
        $this->assertSame(today()->toDateString(), $upload->purge_due_on?->toDateString());
    }

    public function test_rejects_a_zero_byte_row(): void
    {
        $this->assertRejectedBy('uploads_size_check', fn () => Upload::factory()->create(['size' => 0]));
    }

    public function test_accepts_a_one_byte_row(): void
    {
        $this->assertModelExists(Upload::factory()->create(['size' => 1]));
    }

    public function test_rejects_an_empty_path_and_an_empty_mime(): void
    {
        $this->assertRejectedBy('uploads_path_check', fn () => Upload::factory()->create(['path' => '']));
        $this->assertRejectedBy('uploads_mime_check', fn () => Upload::factory()->create(['mime' => '']));
    }

    public function test_rejects_the_same_path_twice(): void
    {
        $first = Upload::factory()->create();

        $this->assertRejectedBy('uploads_path_unique', fn () => Upload::factory()->create(['path' => $first->path]));
    }

    public function test_rejects_a_purged_at_without_a_due_date_and_the_reverse(): void
    {
        $constraint = 'uploads_purged_at_purge_due_on_check';

        $this->assertRejectedBy($constraint, fn () => Upload::factory()->create(['purged_at' => now()]));
        $this->assertRejectedBy($constraint, fn () => Upload::factory()->create(['purge_due_on' => today()]));
    }

    public function test_accepts_neither_and_both_of_the_purge_columns(): void
    {
        $this->assertModelExists(Upload::factory()->create());
        $this->assertModelExists(Upload::factory()->purged()->create());
    }

    public function test_the_database_has_exactly_the_designed_constraint_and_index_names(): void
    {
        $constraints = DB::table('pg_constraint')->whereRaw("conrelid = 'uploads'::regclass")
            ->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'uploads')->pluck('indexname')->all();
        $names = array_values(array_unique([...$constraints, ...$indexes]));
        sort($names);

        $this->assertSame([
            'uploads_mime_check',
            'uploads_path_check',
            'uploads_path_unique',
            'uploads_pkey',
            'uploads_purged_at_purge_due_on_check',
            'uploads_size_check',
            'uploads_user_id_foreign',
            'uploads_user_id_index',
        ], $names);
    }
}
