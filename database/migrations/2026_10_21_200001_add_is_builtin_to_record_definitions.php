<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 業務モジュール・項目・選択肢に組み込みの旗 is_builtin を足す(docs の 設計/業務ツール/共通データ.md の「入れ物の拡張」の「組み込みの旗」、
 * 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」)。既定は false(前からある行は組み込みでない)。
 * 組み込みの行が残っている間は戻さない(列を消すと黙って組み込みでなくなる)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_modules', function (Blueprint $table) {
            $table->boolean('is_builtin')->default(false);
        });

        Schema::table('record_fields', function (Blueprint $table) {
            $table->boolean('is_builtin')->default(false);
        });

        Schema::table('record_field_options', function (Blueprint $table) {
            $table->boolean('is_builtin')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasBuiltin = DB::table('record_modules')->where('is_builtin', true)->exists()
            || DB::table('record_fields')->where('is_builtin', true)->exists()
            || DB::table('record_field_options')->where('is_builtin', true)->exists();

        if ($hasBuiltin) {
            throw new RuntimeException(
                '組み込みの定義の行が残っているので戻せない(is_builtin の列を消すと、組み込みの定義が組み込みでないものになる)',
            );
        }

        Schema::table('record_field_options', function (Blueprint $table) {
            $table->dropColumn('is_builtin');
        });

        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn('is_builtin');
        });

        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('is_builtin');
        });
    }
};
