<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 同じ申請書のレコードに一時保存でない案件を 1 つまでにする(docs の 設計/ワークフロー/データモデル/表.md の 2.5)。
 * 完了を挟む競合も、申請の行の挿入で止める。名前は変えない(申請の業務処理が一意違反を名前で見分ける)。
 * 重なる行が残っていれば up は失敗し、行を消さない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX workflow_cases_record_id_unique');
        DB::statement('CREATE UNIQUE INDEX workflow_cases_record_id_unique ON workflow_cases (record_id)'
            ." WHERE state <> 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX workflow_cases_record_id_unique');
        DB::statement('CREATE UNIQUE INDEX workflow_cases_record_id_unique ON workflow_cases (record_id)'
            ." WHERE state = 'active'");
    }
};
