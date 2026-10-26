<?php

namespace App\Queries\Records\Api;

use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordCandidatesQuery;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 他モジュール参照の項目の候補の口(ほかのモジュールの入口が、判定を求める者を決めてから候補を引く = docs の
 * 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。業務データの入れ物の画面の候補の入口と同じ読み取り(設計/業務データの入れ物/画面/README.md 4 節)を呼ぶだけで、
 * 判定・並び・件数を変えない。主体 = 渡した判定を求める者、認可 = 業務モジュールの read、ロック = 取らない、読み取りだけ。
 */
class RecordCandidates
{
    public function __construct(private RecordCandidatesQuery $candidates) {}

    /**
     * $excludedId は候補から除くレコードの id(null = 除かない)。語の検証は呼び手。
     *
     * @return list<array{id: int, label: string|null}> 新しい順に 10 件まで
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException 業務モジュールの read を許されないとき
     */
    public function candidates(
        Requester $requester,
        string $moduleCode,
        string $keyword,
        ?int $excludedId,
        DateTimeInterface $date,
    ): array {
        return $this->candidates->candidates($requester, $moduleCode, $keyword, $excludedId, $date);
    }
}
