<?php

namespace App\Queries\Workflow;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 3a の「処理対象者に当たる」の項: 処理対象者の行のユーザーが自分(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節。段 2 の未処理の述語の中身)。
 */
class OwnCaseTargetTerm implements CaseTargetTerm
{
    public function apply(Builder $targets, int $userId, DateTimeInterface $date): void
    {
        $targets->where($targets->qualifyColumn('user_id'), $userId);
    }
}
