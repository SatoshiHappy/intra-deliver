<?php

namespace App\Actions\Ai\Api;

/**
 * 書く道具の確認の内容(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の中身。① が実行の読み取りで読んで 1 枚のカードに出す)。
 * 包みが道具の行に書き、モデルには送らない(10.6)。直すの版は入れない(置き場は道具の行の版 = 設計/AI/実行の記録.md 2.4)。
 *
 * 鍵: target(module・moduleName・直すは id と name)・fields(項目のコード → name・current・next。値は画面の初期値の形。明細の項目は
 * 行の列)・rowAccess(current・next)・parties(書く値と今の値の相手)・audienceChanges(見られる人が変わる項目のコードと rowAccess)。
 * references(前の件を指す参照 → k)は ③ の共通の部品が足す。
 */
final readonly class RecordConfirmation
{
    /**
     * @param  array<string, mixed>  $content  JSON にできる配列
     */
    public function __construct(public array $content) {}
}
