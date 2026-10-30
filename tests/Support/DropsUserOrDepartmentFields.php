<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * マイグレーションのテストの手助け: 型「ユーザーと組織」(user_or_department)の項目と、その値の行を消す。型の一覧を狭める down
 * (2026_10_26_100001)は、その型の項目の行があると止まる(項目と値を黙って消さない)。初期データの組み込みの会議の項目「関係者」
 * (docs の 設計/業務ツール/会議/README.md の 1.2)を、テストの TX の中で消してから戻すときに使う。
 */
trait DropsUserOrDepartmentFields
{
    private function dropUserOrDepartmentFields(): void
    {
        $fieldIds = DB::table('record_fields')->where('type', 'user_or_department')->pluck('id');
        DB::table('record_references')->whereIn('record_field_id', $fieldIds)->delete();
        DB::table('record_fields')->whereIn('id', $fieldIds)->delete();
    }
}
