<?php

namespace App\Actions\Ai\Api;

/**
 * AI の会話の型の種類(依存の逆転 = docs の 設計/AI/公開の口.md 3.1・3.4、正本 = 設計/AI/エージェントの実行/口と契約.md 9.13)。
 * 会話の持ち主のモジュール(① AI エージェント)が 1 つ実装し、起動(App\Providers\AppServiceProvider)がタグ {@see self::TAG} で登録する。
 * Ai は実装を直接呼ばず、束ねる 1 か所(内部の ConversationKinds)を通す。コードは設定 config/ai_runs.php の kinds に型 conversation であるものだけを
 * 使える(文章 {@see PromptKind}・文字起こし {@see TranscriptionKind} とまたいで一意。食い違いは起動の時の確かめで落とす)。
 * 種類は書いてよい業務モジュールを持たない(Ai の書き込みの口と実行の対象のレコードを読む口を使わない。業務データは道具だけが読み書きする)。
 *
 * 受け手の規則(9.13): 題が ① の規則(長さと形)に合わなければ書かずに続け、例外にしない。会話が消えた後に呼ばれたら何もしない(実行の終わりは Ai が書く)。
 * 予期する失敗(題が合わない・会話が無い)だけを分けて扱い、ほかの例外は投げる。トランザクションの外に効くことをしない(巻き戻されうるため)。
 */
interface ConversationKind
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'ai.conversation-kinds';

    /** 種類のコード(<モジュールの小文字>.<用途>) */
    public function code(): string;

    /** この種類の会話の保存の口(① が実装する = 9.13 の「会話の保存の口」) */
    public function store(): ConversationStore;

    /**
     * 成功・確認待ち・止めたで終わるとき、結果を書くトランザクションの中で呼ばれる(待ちの実行を止める口が止めたときは呼ばれない = 9.13)。
     * 例外を投げると、このトランザクションの書き込みはすべて巻き戻り、実行は失敗(完了の処理)になる。
     */
    public function completed(ConversationEnded $run): void;

    /**
     * 実行を失敗にするのと同じトランザクションで、実行の行の鍵を取る前に呼ばれる(失敗の受け手 = 公開の口.md 3.1 と同じ。順の正本 =
     * 設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」)。例外を投げると受け手の書き込みは巻き戻り、実行は失敗のまま残る。
     * 受け手の中から依頼の口を呼ぶと作りの誤り(公開の口.md 3 節の表の B7)。
     */
    public function failed(ConversationFailed $run): void;
}
