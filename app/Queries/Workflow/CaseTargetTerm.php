<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCaseNodeTarget;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 「処理対象者に当たる」の項 1 つ(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節 = 3b の差し込み口)。起動(App\Providers)がタグ {@see self::TAG}
 * で登録し、{@see CaseTargetTerms} が項の OR に束ねる。3a の項 = {@see OwnCaseTargetTerm}。3b は代理の項をここに実装して登録する。
 */
interface CaseTargetTerm
{
    /** 項を束ねるコンテナのタグ */
    public const TAG = 'workflow.case-target-terms';

    /**
     * 処理対象者の行の問い合わせに、この項の条件を足す(呼び手が OR の 1 組の中の入れ子で呼ぶ)。
     *
     * @param  Builder<WorkflowCaseNodeTarget>  $targets
     * @param  int  $userId  判定を求める者の、d に有効なユーザー
     */
    public function apply(Builder $targets, int $userId, DateTimeInterface $date): void;
}
