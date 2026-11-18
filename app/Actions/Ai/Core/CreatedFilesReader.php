<?php

namespace App\Actions\Ai\Core;

/**
 * 作ったファイルの読み手(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3)。要約の時に、要約の文に足す作ったファイルの名前と印
 * (設計/AI/エージェントの実行/途中の表示と上限.md 9.9)を、レコードの印の形を直して返す(直すのは繋ぎ = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
 */
interface CreatedFilesReader
{
    /**
     * 要約の文に足す作ったファイル(新しい方から)。
     *
     * @return list<CreatedFileMark>
     */
    public function forCompaction(): array;
}
