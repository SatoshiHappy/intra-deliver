<?php

namespace App\Actions\Ai\Instructions;

use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 差し替える口「指示の組み立て」(Ai の内部。docs の 設計/AI/設定とプロンプト/README.md 11.4・ADR 0042 の決定 5)。会話のループと道具は、
 * モデルに送る文をこの口だけから受け取り、文を読むのはこの口の中だけ。版は 2 つ(移した版 = 手元だけのリポジトリ、代わりの版 =
 * {@see DefaultInstructionBuilder})で、どちらも約束(同じ値なら同じ文・値の無い置き換え口は作りの誤り・置き換えは 1 回)を守る。
 */
interface InstructionBuilder
{
    /**
     * 呼び出しの種類ごとのシステムの文 1 本(空の部分を捨てて空行で継ぐ)と、送る履歴に足す文の列。
     *
     * @throws LogicException 文に値の無い置き換え口があるとき・文が無いとき(作りの誤り)
     */
    public function build(InstructionRequest $request): BuiltInstructions;

    /**
     * 定型の文(保存か送るだけかは {@see AiInstructionTemplate::isStored()})。並びの値は改行で継いで入れる。中断の印は会話の保存に印だけを書き、
     * 送るときにここから文を引く。
     *
     * @param  array<string, string|list<string>>  $values  置き換え口の名前と値
     *
     * @throws LogicException 文に値の無い置き換え口があるとき・文が無いとき(作りの誤り)
     */
    public function template(AiInstructionTemplate $template, array $values): string;

    /**
     * 要約の答えから下書きの節を外して組んだ、要約の後に続ける文(呼び手が会話の保存に要約の文として書く)。
     *
     * @throws LogicException 文が無いとき(作りの誤り)
     */
    public function compactSummary(string $answer): string;

    /**
     * 会話の題の答えの 1 欄の名前と説明(説明は空でもよい = docs の 設計/AI/設定とプロンプト/README.md 11.4 の「題の答えの形」)。
     *
     * @throws LogicException 文が無いとき(作りの誤り)
     */
    public function titleField(): TitleField;

    /**
     * その版の文が使う会話の印の形の一覧(会話の印の文字列そのもの。docs の 設計/AI/設定とプロンプト/README.md 11.4 の返す物)。版ごとに 1 つで、
     * 受け取る物によらず同じ一覧を返す。ループが送る履歴を組む時に、道具の結果の本文と定型に入れる値の中の同じ形の文字列を直すのに使う
     * (設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
     *
     * @return list<string>
     *
     * @throws LogicException 文が無いとき(作りの誤り)
     */
    public function conversationMarkerForms(): array;

    /**
     * 起動の時の確かめ(docs の 設計/AI/データと失敗と検査.md 8 節): その版の文と共有の定型の、許す一覧に無い置き換え口と、定型の種類と
     * 呼び出しの種類に文が無いこと。投げずに文にする。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array;
}
