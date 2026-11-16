<?php

namespace App\Actions\Ai\Api;

/**
 * 呼び出しの引数の中の、ユーザー・組織の相手(docs の 設計/AI/道具と権限/README.md 10.1 の形の外の検証・10.2 のユーザー・組織の名前)。
 * ③ の共通の部品が引いて読み、引数とは別に道具のクラスへ渡す(モデルが引数で偽れないように、引数の鍵にしない)。名前を読めない相手
 * (基準日に有効でない・無い)は名前と所属が null。名前を引く引数を持たない道具と、相手の無い呼び出しでは空。
 *
 * @phpstan-type Party array{field: string, type: 'user'|'department', id: int, name: string|null,
 *     affiliation: string|null}
 */
final readonly class AgentToolParties
{
    /**
     * 相手ごとに、項目のコード・相手の種類と id・基準日の名前・所属(ユーザーの主所属の組織の名前。組織は null)。
     *
     * @param  list<Party>  $parties
     */
    public function __construct(public array $parties = []) {}
}
