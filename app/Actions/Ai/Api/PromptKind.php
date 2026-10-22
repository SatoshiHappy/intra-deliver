<?php

namespace App\Actions\Ai\Api;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * AI の実行の種類(依存の逆転 = docs の 設計/AI/公開の口.md 3.1)。呼び手のモジュールが種類ごとに 1 クラスで実装し、
 * 起動(App\Providers\AppServiceProvider)がタグ {@see self::TAG} で登録する。Ai は実装を直接呼ばず、束ねる 1 か所(内部の PromptKinds)を通す。
 * コードは設定 config/ai_runs.php の kinds にあるものだけを使える(同じコードの実装が 2 つ・設定に無いコードの実装は起動のテストで落とす)。
 */
interface PromptKind
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'ai.prompt-kinds';

    /** 種類のコード(<モジュールの小文字>.<用途>。例 meeting.minutes) */
    public function code(): string;

    /**
     * 構造化出力の形(laravel/ai の HasStructuredOutput::schema と同じ)。文章だけの種類も 1 項目の形にする(呼び方を 1 つにするため)。
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;

    /**
     * 書き込みの口 {@see WriteRecordFromRun} で書いてよい業務モジュールのコード(固定の値。LLM の出力から取らない)。書かない種類は null。
     */
    public function writesTo(): ?string;

    /**
     * 応答を解釈し、書き込みの口 {@see WriteRecordFromRun} と自分の表を書く(業務データのレコードは書き込みの口からだけ書く)。
     * 実行の結果を書くトランザクションの中で呼ばれる。出力は信頼できない入力として形と値を確かめてから書く。
     * 例外を投げると、このトランザクションの書き込みはすべて巻き戻り、実行は失敗(completion)になる。予期する失敗(出力の形・長さが合わないなど)は
     * {@see RunCompletionFailed} に理由のコードを付けて投げる(2 段目の依頼が断られた 422 = {@see RunRequestDeclined} はその理由のコードになる。
     * レコードの 403・404・422 の例外の型は、出どころによらず
     * {@see FailedRun} のレコードの理由になるので、自分の確かめには使わない)。
     */
    public function complete(CompletedRun $run): void;

    /**
     * 実行を失敗にしたトランザクションの後に、別のトランザクションで呼ばれる(失敗の通知など)。依頼が 422 で断られたとき(記録を作らない)は呼ばれない。
     * 予期する失敗(対象のレコードが無いなど)だけを分けて扱い、ほかの例外は投げる(報告され、実行は失敗のまま)。
     */
    public function failed(FailedRun $run): void;
}
