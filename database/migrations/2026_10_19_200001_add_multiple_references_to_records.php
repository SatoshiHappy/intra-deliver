<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 複数選べる参照(docs の 設計/業務データの入れ物/データモデル/表.md 2.2・2.6、項目と規則/README.md 1 節・5 節)。
 * 項目の is_multiple と、参照の行の並び sort_key・項目の is_multiple の写しを足し、項目ごとに 1 行の一意を、並びの位置と相手ごとの一意に替える。
 * 今の行はどれも項目ごとに 1 行で sort_key = 0・is_multiple = false なので、そのまま新しい制約を満たす。
 * 項目ごとに 2 行以上の参照が残っている間は戻さない(項目ごとに 1 行の一意に戻すには、残りを消すしかない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            $table->boolean('is_multiple')->default(false);

            $table->unique(['id', 'is_multiple']);
        });

        DB::statement(
            'ALTER TABLE record_fields ADD CONSTRAINT record_fields_type_is_multiple_check '
            ."CHECK (type IN ('user', 'department') OR NOT is_multiple)",
        );

        Schema::table('record_references', function (Blueprint $table) {
            $table->bigInteger('sort_key')->default(0);
            $table->boolean('is_multiple')->default(false);

            $table->dropUnique(['record_id', 'record_field_id']);
            $table->unique(['record_id', 'record_field_id', 'sort_key']);
            $table->unique(['record_id', 'record_field_id', 'user_id']);
            $table->unique(['record_id', 'record_field_id', 'department_id']);
            $table->foreign(['record_field_id', 'is_multiple'])
                ->references(['id', 'is_multiple'])->on('record_fields')->restrictOnDelete();
            $table->index(['record_field_id', 'is_multiple']);
        });

        DB::statement(
            'ALTER TABLE record_references ADD CONSTRAINT record_references_is_multiple_sort_key_check '
            .'CHECK (is_multiple OR sort_key = 0)',
        );
        DB::statement(
            'ALTER TABLE record_references ADD CONSTRAINT record_references_sort_key_check '
            .'CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)',
        );
    }

    /**
     * Reverse the migrations(足した順の逆に戻す)。
     */
    public function down(): void
    {
        $hasMany = DB::table('record_references')
            ->select('record_id')
            ->groupBy('record_id', 'record_field_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasMany) {
            throw new RuntimeException(
                '1 つの項目に参照が 2 つ以上あるレコードが残っているので戻せない'
                .'(項目ごとに 1 行の一意に戻すと、2 つ目以降の参照を消すことになる)',
            );
        }

        DB::statement('ALTER TABLE record_references DROP CONSTRAINT record_references_sort_key_check');
        DB::statement('ALTER TABLE record_references DROP CONSTRAINT record_references_is_multiple_sort_key_check');

        Schema::table('record_references', function (Blueprint $table) {
            $table->dropIndex(['record_field_id', 'is_multiple']);
            $table->dropForeign(['record_field_id', 'is_multiple']);
            $table->dropUnique(['record_id', 'record_field_id', 'department_id']);
            $table->dropUnique(['record_id', 'record_field_id', 'user_id']);
            $table->dropUnique(['record_id', 'record_field_id', 'sort_key']);
            $table->unique(['record_id', 'record_field_id']);
            $table->dropColumn(['sort_key', 'is_multiple']);
        });

        DB::statement('ALTER TABLE record_fields DROP CONSTRAINT record_fields_type_is_multiple_check');

        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropUnique(['id', 'is_multiple']);
            $table->dropColumn('is_multiple');
        });
    }
};
