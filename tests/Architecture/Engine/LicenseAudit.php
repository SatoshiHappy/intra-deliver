<?php

namespace Tests\Architecture\Engine;

use DateTimeImmutable;

/**
 * 依存のライセンスが許可リストに収まるかを判定する(汎用)。許可リストと例外は呼び出し側(Policy・台帳)から渡す。
 *
 * - 宣言されたライセンスのどれか 1 つが許可の式(SPDX。{@see SpdxExpression})なら許可
 * - ライセンスを読めなかった記録(problem)は、除外があっても失敗
 * - 許可でなくても、台帳の除外があり期限(その日まで有効)が今日以降なら通す。期限切れは失敗
 * - 台帳の除外に載るパッケージが依存の実体に無ければ失敗(台帳の水増しを残さない)
 */
final readonly class LicenseAudit
{
    /**
     * @param  list<string>  $allowed  許可する SPDX 識別子
     * @param  array<string, string>  $exceptions  除外のパッケージ(`composer:<名前>` / `npm:<名前>`)→ 期限(`YYYY-MM-DD`)
     */
    public function __construct(
        private array $allowed,
        private array $exceptions,
        private DateTimeImmutable $today,
    ) {}

    /**
     * @param  list<LicenseRecord>  $records
     * @return list<string> 失敗 1 件につき 1 行(パッケージ名・版・ライセンス・理由)
     */
    public function failures(array $records): array
    {
        $failures = [];

        foreach ($records as $record) {
            $reason = $this->violation($record);

            if ($reason !== null) {
                $failures[] = sprintf('%s %s %s [%s]: %s', $record->ecosystem, $record->name, $record->version, implode(' / ', $record->licenses) ?: 'license 欄なし', $reason);
            }
        }

        $present = array_map(fn (LicenseRecord $record): string => $record->key(), $records);

        foreach (array_diff(array_keys($this->exceptions), $present) as $absent) {
            $failures[] = "台帳⑧ {$absent}: 台帳にあるが依存の実体に無い(除外を外す)";
        }

        return $failures;
    }

    private function violation(LicenseRecord $record): ?string
    {
        // 除外が覆うのはライセンスの判定だけ。ライセンスを読めなかった(入っていない・package.json が無いなど)記録は除外で通さない
        if ($record->problem !== null) {
            return $record->problem;
        }

        foreach ($record->licenses as $license) {
            if (SpdxExpression::isAllowed($license, $this->allowed)) {
                return null;
            }
        }

        $expires = $this->exceptions[$record->key()] ?? null;

        if ($expires === null) {
            return $record->licenses === [] ? 'ライセンスが不明(license 欄なし)' : '許可リストに無い';
        }

        return $this->today->format('Y-m-d') > $expires ? "除外の期限切れ(期限 {$expires})" : null;
    }
}
