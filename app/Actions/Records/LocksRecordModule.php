<?php

namespace App\Actions\Records;

use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * 業務モジュールごとの書き込みの直列化(docs の 設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節。メインの決定 2026-10-11)。
 * 鍵 = トップの業務モジュール(明細のモジュールならその親)の id から作る、トランザクションレベルの勧告ロック 1 つ。
 * - レコードの書き込み(登録・更新・削除。明細を含む)は共有で取る(pg_advisory_xact_lock_shared)。レコードどうしは並ぶ。
 * - 定義の書き込み(項目・選択肢・規則・明細のモジュール)は、共通マスタのロックの後に排他で取る(pg_advisory_xact_lock)。そのモジュールの
 *   レコードの書き込みと直列になり、「使われているかを確かめてから消す」が同時の保存に破られない。
 * 取る順はレコード = 業務モジュール → 行の鍵、定義 = 共通マスタ → 業務モジュール → 行(明細の行の削除)で、レコードの書き込みは共通マスタの
 * ロックを取らないので待ちの輪はできない。同じ接続が持っているロックを重ねて取るのは常に成功する。どちらもトランザクションの終わりで外れる。
 * 呼び手がトランザクションを持つ(この trait はトランザクションを始めない)。
 */
trait LocksRecordModule
{
    /** 業務モジュールの鍵の起点(共通マスタの鍵 WritesCommonMaster::COMMON_MASTER_LOCK_KEY = 4,100,000,001 と重ならない bigint の空間) */
    private const RECORD_MODULE_LOCK_BASE = 4_200_000_000_000_000;

    /**
     * レコードの書き込みの前に、業務モジュールの鍵を共有で取り、渡された業務モジュールを読み直す。
     * 鍵の後の業務モジュールの値(参照制限の既定・添付の許可)を使うため = docs の
     * 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 1。
     */
    private function lockRecordModuleShared(RecordModule $module): void
    {
        DB::select('SELECT pg_advisory_xact_lock_shared(?)', [$this->recordModuleLockKey($module)]);
        $module->refresh();
    }

    /**
     * コードで業務モジュール(明細のモジュールを含む)を引き、定義の書き込みの前にその鍵を排他で取る。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     */
    private function lockRecordModuleExclusively(string $moduleCode): RecordModule
    {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail();
        DB::select('SELECT pg_advisory_xact_lock(?)', [$this->recordModuleLockKey($module)]);

        return $module;
    }

    /**
     * 業務モジュールの鍵(明細のモジュールは親の鍵。親子は作った後に変わらない)。
     */
    private function recordModuleLockKey(RecordModule $module): int
    {
        return self::RECORD_MODULE_LOCK_BASE + ($module->parent_record_module_id ?? $module->id);
    }
}
