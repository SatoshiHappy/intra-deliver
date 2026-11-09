<?php

namespace App\Actions\Ai\Api;

/**
 * 会話の保存の口(① が実装する。正本 = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「会話の保存の口」、設計/AI/公開の口.md 3.4)。
 * ③ は会話の表と会話の ID を持たず、実行を渡して呼ぶ。① は会話と実行の対応で実行の会話を引き、対応の無い実行は会話が消えたものとして無しを返す。
 * 項目は {@see ConversationItem}(SDK のメッセージの型を使わない)。① は項目を組み替えず書き換えずに持つ(写しの配列 = {@see ConversationItem::toArray()})。
 * 予期する失敗(会話が無い)だけを無しとして返し、ほかの例外は投げる。約束のテストは tests/Feature/Ai/ の ConversationStoreContract(① の本物で流す)。
 */
interface ConversationStore
{
    /**
     * その実行の会話の項目を、最後の要約の境目の項目(を含む)から後ろ、足した順に返す。会話が無ければ null(例外にしない)。
     * 途切れた会話の直しは ③ が読んだ後に当てるので、足したとおりに返す。
     * 写しは値と鍵の順のまま返す(順が変わると接続先のキャッシュが当たらない = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の守る結果)。
     *
     * @return list<ConversationItem>|null
     */
    public function read(int $runId): ?array;

    /**
     * 会話の末尾に、渡された順に足し、各項目をその実行に結ぶ。1 つのトランザクションで全部か無しかで書く(呼び手のトランザクションの中で
     * 呼ばれればその中で、外なら口がコミットする)。会話の行の鍵の下で会話の有無を見て、無ければ何も書かずに false を返す(例外にしない)。
     *
     * @param  list<ConversationItem>  $items
     * @return bool 書いた = true。会話が無い = false
     */
    public function append(int $runId, array $items): bool;
}
