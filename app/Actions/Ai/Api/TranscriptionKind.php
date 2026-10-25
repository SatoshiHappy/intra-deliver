<?php

namespace App\Actions\Ai\Api;

/**
 * AI の文字起こしの種類(依存の逆転 = docs の 設計/AI/公開の口.md 3.1)。呼び手のモジュールが種類ごとに 1 クラスで実装し、
 * 起動(App\Providers\AppServiceProvider)がタグ {@see self::TAG} で登録する。Ai は実装を直接呼ばず、束ねる 1 か所(内部の TranscriptionKinds)を通す。
 * コードは設定 config/ai_runs.php の kinds に型 transcription であるものだけを使える(文章の種類 {@see PromptKind} とまたいで一意。
 * 同じコードの実装が 2 つ・設定に無いコードの実装は起動のテストで落とす)。
 */
interface TranscriptionKind
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'ai.transcription-kinds';

    /** 種類のコード(<モジュールの小文字>.<用途>。例 meeting.transcription) */
    public function code(): string;

    /**
     * 書き込みの口 {@see WriteRecordFromRun} で書いてよい業務モジュールのコード(固定の値)。書かない種類は null。
     */
    public function writesTo(): ?string;

    /**
     * 区間の一覧を解釈し、書き込みの口 {@see WriteRecordFromRun} と自分の表を書く(業務データのレコードは書き込みの口からだけ書く)。
     * 実行の結果を書くトランザクションの中で呼ばれる。区間の文は信頼できない入力として形と長さを確かめてから書く。
     * 例外を投げると、このトランザクションの書き込みはすべて巻き戻り、実行は失敗(completion)になる。予期する失敗は
     * {@see RunCompletionFailed} に理由のコードを付けて投げる({@see PromptKind::complete()} と同じ)。
     */
    public function complete(CompletedTranscription $run): void;

    /**
     * 実行を失敗にするのと同じトランザクションで、実行の行の鍵を取る前に呼ばれる(失敗の通知など。docs の 設計/AI/公開の口.md 3.1、
     * 実行と費用.md 4 節の「失敗の書き方」)。依頼が 422 で断られたときは呼ばれない。
     * トランザクションの外に効くことをしない(巻き戻されうるため)。予期する失敗だけを分けて扱い、ほかの例外は投げる。
     */
    public function failed(FailedRun $run): void;
}
