<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 通知の受け取りの設定の表を、ミュートの印の表に改名する(1 行 = 利用者 1 人が種類 1 つをミュートする。docs の ADR 0050 の
 * 決定 1 と行の移行。規則 = 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節)。行と連番はそのまま残す。
 *
 * PostgreSQL は表を改名しても、旧い表の名前を持つ制約・索引・連番の名前を変えないので、docs の 設計/共通マスタ/共通の規則.md の
 * 9 節の形に揃えて改名する(主キー・一意・外部キー・検査の制約は、改名すると同じ名前の索引も改名される)。NOT NULL が名前つきの
 * 制約として pg_constraint に載るのは PostgreSQL 18 からなので、NOT NULL の制約はあるときだけ改名する。
 *
 * 前方互換でない(表 notice_opt_outs を読む前の版のコードは、この後に動かない。配り方 = docs の 設計/公開の配備/配備と戻し方.md の 6.3)。
 */
return new class extends Migration
{
    /** 制約の種類の接尾辞(いつもある制約) */
    private const CONSTRAINTS = ['pkey', 'user_id_type_unique', 'user_id_foreign', 'type_check'];

    /** NOT NULL の制約の接尾辞(PostgreSQL 18 からある制約) */
    private const NOT_NULL_CONSTRAINTS = ['id_not_null', 'user_id_not_null', 'type_not_null'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 表の改名は Schema::rename に表の名前をじかに書く(larastan がマイグレーションからモデルの列を読むため)
        Schema::rename('notice_opt_outs', 'notice_mutes');
        $this->renameNames('notice_opt_outs', 'notice_mutes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('notice_mutes', 'notice_opt_outs');
        $this->renameNames('notice_mutes', 'notice_opt_outs');
    }

    /**
     * 改名した表 $to の、旧い表の名前 $from を持つ制約・索引・連番の名前を、$to の形に改名する。
     */
    private function renameNames(string $from, string $to): void
    {
        foreach (self::CONSTRAINTS as $suffix) {
            DB::statement("ALTER TABLE {$to} RENAME CONSTRAINT {$from}_{$suffix} TO {$to}_{$suffix}");
        }

        foreach (self::NOT_NULL_CONSTRAINTS as $suffix) {
            $exists = DB::table('pg_constraint')
                ->whereRaw('conrelid = ?::regclass', [$to])
                ->where('conname', "{$from}_{$suffix}")
                ->exists();

            if ($exists) {
                DB::statement("ALTER TABLE {$to} RENAME CONSTRAINT {$from}_{$suffix} TO {$to}_{$suffix}");
            }
        }

        DB::statement("ALTER SEQUENCE {$from}_id_seq RENAME TO {$to}_id_seq");
    }
};
