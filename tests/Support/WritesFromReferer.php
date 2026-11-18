<?php

namespace Tests\Support;

/**
 * 同じ画面の中の書き込みを、要求の Referer だけで元の画面を指して送る(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の
 * 3 節の「同じ画面の中の書き込みの戻り先」)。セッションの直前の URL を別の所にしておき、戻り先が Referer から決まることを確かめられるようにする
 * (`from()` は両方に同じ URL を入れるので、片方への退行を見分けられない)。
 */
trait WritesFromReferer
{
    protected function fromReferer(string $page): static
    {
        return $this->withSession(['_previous' => ['url' => 'http://localhost/elsewhere']])
            ->withHeader('Referer', $page);
    }
}
