<?php

namespace Tests\Support;

use App\Queries\Workflow\CaseTargetTerm;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * テストの「処理対象者に当たる」の項(3b の代理の項の形の見本): 判定を求める者が代理先なら、処理対象者の行のユーザーが代理元の行に当たる。
 * ほかの判定を求める者には何も当たらない(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節・7 節の差し込み口のテスト)。
 */
final readonly class DelegatedCaseTargetTerm implements CaseTargetTerm
{
    public function __construct(
        private int $principalUserId,
        private int $delegateUserId,
    ) {}

    public function apply(Builder $targets, int $userId, DateTimeInterface $date): void
    {
        if ($userId !== $this->delegateUserId) {
            $targets->whereRaw('false');

            return;
        }

        $targets->where($targets->qualifyColumn('user_id'), $this->principalUserId);
    }
}
