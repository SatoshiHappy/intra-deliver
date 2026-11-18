<?php

namespace App\Queries\Notice\Api;

use App\Queries\Notice\NoticeTypeCatalog;

/**
 * 通知の種類の一覧の口(依存の逆転 = docs の 設計/コード構成/README.md の 3 節。保証の正本 = ADR 0050 の決定 2、規則 =
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節)。通知を送る後ろのモジュールが実装し、起動(App\Providers)が
 * タグ {@see self::TAG} で登録する(登録の順 = 画面の見出しの順)。通知のモジュールは実装を直接呼ばず、束ねる 1 か所(内部の {@see NoticeTypeCatalog})を通す。
 */
interface NoticeTypes
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'notice.types';

    /**
     * 主体の判定をしない。自分のモジュールの種類の列挙のすべての値を、列挙の宣言の順に答える(画面の語は答えない)。
     *
     * 答えの値が守る規則 = docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節
     * (破れていれば束ねる時に例外)。
     *
     * @return list<string> 種類「モジュール.種類」(表 notices の type。例 workflow.process_request)
     */
    public function types(): array;
}
