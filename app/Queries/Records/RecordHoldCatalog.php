<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordHold;
use App\Queries\Records\Api\RecordHolds;
use Illuminate\Container\Attributes\Tag;

/**
 * レコードの持ち主の全体 = タグ {@see RecordHolds::TAG} で束ねた実装を登録の順に聞く 1 か所(内部。公開の口に出さない。docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表の RecordHoldCatalog の行)。業務データの入れ物の業務処理と読み取りは、持ち主の実装を
 * 直接呼ばずにここを通す。鍵を取らない(書き込みは呼び手のトランザクションの中で呼ぶ。画面の can・hold の読み取りはトランザクションを求めない)。
 * 実装が 0 件なら持ち主は無い。
 */
class RecordHoldCatalog
{
    /**
     * @param  iterable<RecordHolds>  $recordHolds
     */
    public function __construct(#[Tag(RecordHolds::TAG)] private iterable $recordHolds) {}

    /**
     * 書き込みを拒む持ち主: レコード(親の無いもの)の持ち主のうち、渡した鍵と違う最初のもの(鍵が null なら最初の持ち主)。無ければ null。
     * 持ち主が 2 つ以上なら、渡した鍵と違う持ち主が必ずあるので、どの鍵でも返る(項目と規則/規則と業務処理/README.md 9.2 の 2)。
     */
    public function blockingHold(int $recordId, ?string $holder): ?RecordHold
    {
        foreach ($this->recordHolds as $recordHolds) {
            $hold = $recordHolds->holdsOf([$recordId])[$recordId] ?? null;

            if ($hold !== null && $hold->holder !== $holder) {
                return $hold;
            }
        }

        return null;
    }

    /**
     * レコード(親の無いもの)の持ち主のどれかが、その鍵を持つか(鍵を渡した書き込みが、鍵の持ち主であることを確かめる)。
     */
    public function isHeldBy(int $recordId, string $holder): bool
    {
        foreach ($this->recordHolds as $recordHolds) {
            if (($recordHolds->holdsOf([$recordId])[$recordId] ?? null)?->holder === $holder) {
                return true;
            }
        }

        return false;
    }

    /**
     * その業務モジュール(親の無いもの。明細のモジュールなら呼び手が親の id を渡す)のレコードの持ち主を、実装を登録の順に聞いて最初の 1 つ。無ければ null。
     */
    public function firstHoldInModule(int $recordModuleId): ?RecordHold
    {
        foreach ($this->recordHolds as $recordHolds) {
            $hold = $recordHolds->firstHoldInModule($recordModuleId);

            if ($hold !== null) {
                return $hold;
            }
        }

        return null;
    }
}
