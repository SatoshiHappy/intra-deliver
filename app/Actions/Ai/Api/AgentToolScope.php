<?php

namespace App\Actions\Ai\Api;

use LogicException;

/**
 * 道具ごとの渡せる範囲(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目): 範囲の業務モジュールのコードと名前と、実行の始めに
 * 読んだ定義と参照制限の既定(設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4。モデルに渡した道具の定義と B1 の確かめと確認の内容に
 * 同じ値を使う)。並びは道具の対象の並び。
 */
final readonly class AgentToolScope
{
    /**
     * $shown は $modules と同じ形の写しで、説明に入れる名前(業務モジュール・項目・選択肢・参照先の名前)を会話の印の形で直した物。
     * 説明を組む所だけが使う(定義そのものは直さない = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
     *
     * @param  array<string, array{name: string, definition: array<string, mixed>}>  $modules  業務モジュールのコード → 名前と定義
     * @param  array<string, array{name: string, definition: array<string, mixed>}>  $shown
     * @param  array<string, bool>  $rowAccessDefaults  業務モジュールのコード → 参照制限の既定のチェック(見せる人の既定は無い = 空)
     */
    public function __construct(
        public array $modules = [],
        public array $shown = [],
        public array $rowAccessDefaults = [],
    ) {}

    /**
     * 範囲の業務モジュールのコード。
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(strval(...), array_keys($this->modules));
    }

    public function has(string $moduleCode): bool
    {
        return isset($this->modules[$moduleCode]);
    }

    public function isEmpty(): bool
    {
        return $this->modules === [];
    }

    /**
     * 業務モジュールの参照制限の既定のチェック(作るの W 表の元のチェックと確認の内容の今の値 = 設計/AI/道具と権限/README.md 10.2)。
     *
     * @throws LogicException 範囲に既定の無い業務モジュールのとき(範囲を作る所は既定を読めた業務モジュールだけを入れる)
     */
    public function rowAccessDefault(string $moduleCode): bool
    {
        return $this->rowAccessDefaults[$moduleCode]
            ?? throw new LogicException("範囲に業務モジュール {$moduleCode} の参照制限の既定が無い");
    }
}
