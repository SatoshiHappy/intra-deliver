<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 項目の型に「ユーザーと組織」(user_or_department)を足し、項目の検査制約 3 つ(型の一覧・is_multiple を許す型・grants_row_access を許す型)を
 * 付け替える(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、ADR 0028)。名前は今のまま。今ある行はどれも新しい式を満たす。
 * 戻すと、型 user_or_department の項目の行があれば理由つきで止まる(その項目と値を黙って消さない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replace(
            'record_fields_type_check',
            "CHECK (type IN ('string', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'choice', "
            ."'user', 'department', 'user_or_department', 'record', 'details'))",
        );
        $this->replace(
            'record_fields_type_is_multiple_check',
            "CHECK (type IN ('user', 'department', 'user_or_department') OR NOT is_multiple)",
        );
        $this->replace(
            'record_fields_type_grants_row_access_check',
            "CHECK (type IN ('user', 'department', 'user_or_department') OR NOT grants_row_access)",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $remaining = DB::table('record_fields')->where('type', 'user_or_department')->count();

        if ($remaining > 0) {
            throw new RuntimeException(
                "型 user_or_department の項目が {$remaining} 件残っているので戻せない"
                .'(型の一覧から外すと、その項目と値を消すことになる)',
            );
        }

        $this->replace(
            'record_fields_type_grants_row_access_check',
            "CHECK (type IN ('user', 'department') OR NOT grants_row_access)",
        );
        $this->replace(
            'record_fields_type_is_multiple_check',
            "CHECK (type IN ('user', 'department') OR NOT is_multiple)",
        );
        $this->replace(
            'record_fields_type_check',
            "CHECK (type IN ('string', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'choice', "
            ."'user', 'department', 'record', 'details'))",
        );
    }

    /**
     * 検査制約 1 つを同じ名前で式だけ替える。
     */
    private function replace(string $name, string $check): void
    {
        DB::statement("ALTER TABLE record_fields DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE record_fields ADD CONSTRAINT {$name} {$check}");
    }
};
