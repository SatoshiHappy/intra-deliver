<?php

namespace App\Queries\Workflow;

use App\Queries\Menu\Api\HomeSections;

/**
 * ワークフローのホームの節を登録するタグ(docs の 設計/メニュー/ホーム.md 6.2・6.7、保証の正本 = 設計/ワークフロー/業務データとの接続/公開の口.md 8.1)。
 * 節の口・形・失敗の保証は全体のホームと同じ {@see HomeSections}。起動がワークフローの 5 つと主な操作を登録の順でこのタグに付け、
 * ワークフローのホームの入口が登録の順に呼ぶ(全体のホームのタグ {@see HomeSections::TAG} とは混ざらない)。
 */
final class WorkflowHomeSections
{
    public const TAG = 'workflow.home-sections';
}
