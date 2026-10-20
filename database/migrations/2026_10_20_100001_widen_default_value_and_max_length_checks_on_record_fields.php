<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 項目の既定値と最大の文字数の検査制約を付け替える(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、項目と規則/README.md 1 節・3 節)。
 * 日付・日時の既定値に相対の既定値のオブジェクトを受け、複数行の最大の文字数を 20000 まで受ける。名前は今のまま(式に出る列の順)。
 * 戻すと、相対の既定値の行か、4000 を超える複数行の最大の文字数の行があれば失敗する。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replace(
            'record_fields_default_value_type_check',
            "CHECK (default_value IS NULL OR CASE type WHEN 'integer' THEN default_value::text ~ '^-?[0-9]{1,15}$' "
            ."WHEN 'boolean' THEN jsonb_typeof(default_value) = 'boolean' "
            ."WHEN 'string' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'decimal' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'date' THEN jsonb_typeof(default_value) IN ('string', 'object') "
            ."WHEN 'datetime' THEN jsonb_typeof(default_value) IN ('string', 'object') "
            ."WHEN 'choice' THEN jsonb_typeof(default_value) = 'string' ELSE false END)",
        );
        $this->replace(
            'record_fields_type_is_multiline_max_length_check',
            "CHECK ((type = 'string' OR (NOT is_multiline AND max_length IS NULL)) "
            .'AND (max_length IS NULL OR max_length BETWEEN 1 AND CASE WHEN is_multiline THEN 20000 ELSE 1000 END))',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replace(
            'record_fields_default_value_type_check',
            "CHECK (default_value IS NULL OR CASE type WHEN 'integer' THEN default_value::text ~ '^-?[0-9]{1,15}$' "
            ."WHEN 'boolean' THEN jsonb_typeof(default_value) = 'boolean' "
            ."WHEN 'string' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'decimal' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'date' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'datetime' THEN jsonb_typeof(default_value) = 'string' "
            ."WHEN 'choice' THEN jsonb_typeof(default_value) = 'string' ELSE false END)",
        );
        $this->replace(
            'record_fields_type_is_multiline_max_length_check',
            "CHECK ((type = 'string' OR (NOT is_multiline AND max_length IS NULL)) "
            .'AND (max_length IS NULL OR max_length BETWEEN 1 AND CASE WHEN is_multiline THEN 4000 ELSE 1000 END))',
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
