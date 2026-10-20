<?php

namespace App\Actions\Workflow;

use LogicException;

/**
 * ノードを書く順の 1 か所(docs の 設計/ワークフロー/ルートの深さ の実装へ送られた手段 1): 参照される側(対の終了・指定するノード)を参照する側より先に書く。
 * 指す列を NULL で入れてから指し直すと、遅延できない検査制約(種類と指定の種類の組・対の終了)と複合外部キーに当たるため。版のノードを書く(初期データ)・
 * 申請の写しの両方がこれを使う。参照の無いノードは渡した順のまま。
 */
class ReferenceFirstOrder
{
    /**
     * @template TKey of int|string
     *
     * @param  array<TKey, list<TKey>>  $references  キー → そのノードが指すノードのキー(渡した順が並びの既定)
     * @return list<TKey> 書く順
     *
     * @throws LogicException 指す先が無い・参照が循環しているとき(定義の誤り)
     */
    public function order(array $references): array
    {
        $ordered = [];
        $visiting = [];

        foreach (array_keys($references) as $key) {
            $this->visit($key, $references, $visiting, $ordered);
        }

        return array_values($ordered);
    }

    /**
     * @template TKey of int|string
     *
     * @param  TKey  $key
     * @param  array<TKey, list<TKey>>  $references
     * @param  array<int|string, true>  $visiting
     * @param  array<int|string, TKey>  $ordered
     */
    private function visit(int|string $key, array $references, array &$visiting, array &$ordered): void
    {
        if (isset($ordered[$key])) {
            return;
        }

        if (isset($visiting[$key]) || ! array_key_exists($key, $references)) {
            throw new LogicException("ノード {$key} の参照が循環しているか、指す先が無い");
        }

        $visiting[$key] = true;

        foreach ($references[$key] as $referenced) {
            $this->visit($referenced, $references, $visiting, $ordered);
        }

        unset($visiting[$key]);
        $ordered[$key] = $key;
    }
}
