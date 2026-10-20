<?php

namespace UpdateCheck;

/**
 * 実行に使うイメージ(app・db・tunnel)の構築と検査の結果。#5 が見せ、#6(版の読み取り)と #7(OS の系列)が使う。
 * results・failures = 固定したイメージ。newScans・newFailures = 新しい土台に差し替えた検査(新しい土台があるイメージだけ)。
 */
final readonly class ScanOutcome
{
    /**
     * @param  list<ScanResult>  $results
     * @param  list<Unconfirmed>  $failures
     * @param  array<string, ScanResult>  $newScans  名前 → 新しい土台の検査結果
     * @param  array<string, Unconfirmed>  $newFailures  名前 → 新しい土台の構築か検査が確認できなかったもの
     * @param  list<string>  $unknownNewBases  新しい土台の有無を #4 で調べられなかった名前
     */
    public function __construct(
        public array $results,
        public array $failures,
        public array $newScans = [],
        public array $newFailures = [],
        private array $unknownNewBases = [],
    ) {}

    /**
     * 新しい土台はあるかもしれないが検査が確認できなかった名前(新しい土台の構築・検査の失敗と、#4 で新しい土台を調べられなかったもの)。
     * 状態を 2 か所で手で同期しないよう、元の 2 つから導く。
     *
     * @return list<string>
     */
    public function unverified(): array
    {
        return array_values(array_unique([...array_map(strval(...), array_keys($this->newFailures)), ...$this->unknownNewBases]));
    }

    /**
     * 名前(app・db・tunnel)の固定したイメージの検査が確認できたか(設計/更新チェック.md 5 節。#6・#7 は使うイメージごとに判定する)。
     * 新しい土台の失敗は #6・#7 に関わらない。
     */
    public function confirmedFor(string $label): bool
    {
        foreach ($this->failures as $failure) {
            if ($failure->subject === $label) {
                return false;
            }
        }

        return true;
    }
}
