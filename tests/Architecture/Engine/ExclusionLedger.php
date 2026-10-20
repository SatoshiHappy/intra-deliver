<?php

namespace Tests\Architecture\Engine;

use DateTimeImmutable;
use LogicException;
use UnexpectedValueException;

/**
 * 検査の除外の台帳(検査名 → 除外の行の一覧)と、実体に現れた除外・抑制を照合する(汎用)。照合の向きは 実体 ⊆ 台帳。
 *
 * 行 = 種類 1 つ({@see self::KINDS} のどれか)と理由(reason)。種類ごとの意味:
 * - path     = パスパターン(台帳の起点からの相対。`*` は 1 階層の中、`**` は配下すべて)
 * - rule     = 除外規則の名前(抑制の注釈・オプション・設定の値など、パスでないもの)
 * - baseline = ベースラインファイルの使用
 * - pattern  = ツールの書式のまま登録した除外(正規表現など。文字列の完全一致で照合する)
 * - package  = 依存パッケージ 1 つの除外(`composer:<名前>` か `npm:<名前>`。依存のライセンス検査 #7 が使う)。期限 expires(`YYYY-MM-DD`。その日まで有効)を必ず添える。
 *            expires は package の行だけが持ち、package の行は scope を持てず、呼び出し側が渡す検査(fromArray の $packageCheck)の下にだけ置け、同じ値を重ねて書けない
 *            (読まれない行・効かない行を台帳に残さない)
 *
 * 行は適用範囲(scope)を持てる。書ける値は {@see self::SCOPES} だけで、それ以外の値(大文字・空文字・別の語・文字列でない値)は
 * 台帳の不正とする(scope 無しと読まない = 範囲を限った除外が全範囲の除外に黙って広がらない)。
 * 照合は範囲ごとに分かれ、scope の無い行は範囲を指定しない照合だけ、scope のある行は同じ範囲を指定した照合だけに一致する。
 * - dir = 作業木の走査だけに効く除外(履歴の走査には効かない)
 *
 * 1 つの除外が複数の検査を外すとき(1 回の実行で複数の検査を行うツールの除外)は、外される検査のすべてに登録を要求する。
 * 台帳に検査名のキーが無ければ、その検査の登録は 0 件として扱う。
 */
final readonly class ExclusionLedger
{
    public const KINDS = ['path', 'rule', 'baseline', 'pattern', 'package'];

    public const SCOPES = ['dir'];

    /**
     * @param  array<string, list<array{kind: string, value: string, scope: string|null, expires: string|null}>>  $rows
     */
    private function __construct(private array $rows) {}

    /**
     * 台帳の配列(検査名 → 行の一覧)を読む。
     *
     * @param  string  $packageCheck  package の行を置ける検査名(intra-deliver 固有の値なので呼び出し側から渡す。そこ以外の下にある package の行は不正)
     *
     * @throws UnexpectedValueException 形が違うとき(行が種類をちょうど 1 つと reason を持たない・値が文字列でない・scope が SCOPES に無い)
     */
    public static function fromArray(mixed $exclusions, string $packageCheck): self
    {
        if (! is_array($exclusions)) {
            throw new UnexpectedValueException('検査の除外の台帳が配列でない');
        }

        $rows = [];

        foreach ($exclusions as $check => $entries) {
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new UnexpectedValueException("検査の除外の台帳の {$check} が行の一覧でない");
            }

            $rows[(string) $check] = array_map(fn (mixed $entry): array => self::row((string) $check, $entry), $entries);
            self::assertPackageRows((string) $check, $rows[(string) $check], $packageCheck);
        }

        return new self($rows);
    }

    /**
     * 検査 $checks のすべてに、範囲 $scope(null = 範囲の指定なし)・種類 $kind・値 $value の行があるか(path は正規化して比べる)。
     * 台帳の種類に無い $kind(ツールの設定にある、登録の形を持たない除外)は常に未登録。
     *
     * @param  list<string>  $checks
     */
    public function registers(array $checks, string $kind, string $value, ?string $scope = null): bool
    {
        $expected = $kind === 'path' ? Paths::normalize($value) : $value;

        return $this->forAll($checks, $scope, fn (array $row): bool => $row['kind'] === $kind
            && ($kind === 'path' ? Paths::normalize($row['value']) : $row['value']) === $expected);
    }

    /**
     * 検査 $checks のすべてで、ファイル $file に一致する path の行か、名前が $rule の rule の行があるか(scope の無い行だけ)。
     *
     * @param  list<string>  $checks
     */
    public function covers(array $checks, string $file, string $rule): bool
    {
        return $this->forAll($checks, null, fn (array $row): bool => ($row['kind'] === 'path' && Paths::matches($row['value'], $file))
            || ($row['kind'] === 'rule' && $row['value'] === $rule));
    }

    /**
     * 検査 $checks のすべてに登録された path の値(scope の無い行だけ。登録の順)。走査そのものから外す範囲を、台帳から決めるのに使う。
     *
     * @param  list<string>  $checks
     * @return list<string>
     */
    public function paths(array $checks): array
    {
        return $this->valuesOf($checks, 'path');
    }

    /**
     * 検査 $checks のすべてに登録された pattern の値(scope の無い行だけ。登録の順)。照合から外す語を、台帳から決めるのに使う。
     *
     * @param  list<string>  $checks
     * @return list<string>
     */
    public function patterns(array $checks): array
    {
        return $this->valuesOf($checks, 'pattern');
    }

    /**
     * ツールの設定に書かれた除外のうち、検査 $checks のすべてに範囲 $scope(null = 範囲の指定なし)で登録されていないものを指摘にする
     * (規則 ID = 設定のキー、file = 除外の値)。
     *
     * @param  list<string>  $checks
     * @param  list<array{key: string, kind: string, value: string}>  $exclusions
     * @return list<Finding>
     */
    public function unregistered(array $checks, array $exclusions, ?string $scope = null): array
    {
        $findings = [];

        foreach ($exclusions as $exclusion) {
            if (! $this->registers($checks, $exclusion['kind'], $exclusion['value'], $scope)) {
                $findings[] = new Finding($exclusion['key'], $exclusion['value'], null);
            }
        }

        return $findings;
    }

    /**
     * ファイルに現れた抑制(規則 ID = トークン、file = 出現したファイル)のうち、検査 $checks のすべてで覆われないものを返す。
     *
     * @param  list<string>  $checks
     * @param  list<Finding>  $occurrences
     * @return list<Finding>
     */
    public function uncovered(array $checks, array $occurrences): array
    {
        return array_values(array_filter(
            $occurrences,
            fn (Finding $occurrence): bool => ! $this->covers($checks, $occurrence->file, $occurrence->ruleId),
        ));
    }

    /**
     * 検査 $check に登録された package の行の値 → 期限(`YYYY-MM-DD`)。
     *
     * @return array<string, string>
     */
    public function packageExpirations(string $check): array
    {
        $expirations = [];

        foreach ($this->rows[$check] ?? [] as $row) {
            if ($row['kind'] === 'package') {
                $expirations[$row['value']] = $row['expires'] ?? throw new LogicException('package 行に expires が無い(不変条件の破れ)');
            }
        }

        return $expirations;
    }

    /**
     * 検査 $checks のすべてに登録された種類 $kind の値(scope の無い行だけ。登録の順)。
     *
     * @param  list<string>  $checks
     * @return list<string>
     */
    private function valuesOf(array $checks, string $kind): array
    {
        if ($checks === []) {
            return [];
        }

        $values = null;

        foreach ($checks as $check) {
            $own = array_filter($this->rows[$check] ?? [], fn (array $row): bool => $row['scope'] === null);
            $found = array_column(array_filter($own, fn (array $row): bool => $row['kind'] === $kind), 'value');
            $values = $values === null ? $found : array_intersect($values, $found);
        }

        return array_values(array_unique($values));
    }

    /**
     * 検査 $checks のすべてに、範囲が $scope と同じで $matches に合う行があるか。
     *
     * @param  list<string>  $checks
     * @param  callable(array{kind: string, value: string, scope: string|null, expires: string|null}): bool  $matches
     */
    private function forAll(array $checks, ?string $scope, callable $matches): bool
    {
        foreach ($checks as $check) {
            $rows = array_filter($this->rows[$check] ?? [], fn (array $row): bool => $row['scope'] === $scope && $matches($row));

            if ($rows === []) {
                return false;
            }
        }

        return $checks !== [];
    }

    /**
     * package の行は $packageCheck の下にだけ置け、同じ値を重ねて書けない。
     *
     * @param  list<array{kind: string, value: string, scope: string|null, expires: string|null}>  $rows
     *
     * @throws UnexpectedValueException
     */
    private static function assertPackageRows(string $check, array $rows, string $packageCheck): void
    {
        $values = array_column(array_filter($rows, fn (array $row): bool => $row['kind'] === 'package'), 'value');

        if ($values !== [] && $check !== $packageCheck) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} に package の行がある(置けるのは {$packageCheck} の下だけ)");
        }

        if (count($values) !== count(array_unique($values))) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の package の行に同じ値が重なっている");
        }
    }

    /**
     * package の行は scope を持てず(範囲の別が無い)、値は `composer:<名>` か `npm:<名>`。
     *
     * @param  array<mixed>  $entry
     *
     * @throws UnexpectedValueException
     */
    private static function assertPackageRow(string $check, array $entry): void
    {
        if (array_key_exists('scope', $entry)) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の package の行は scope を持てない(範囲の別が無い): ".json_encode($entry));
        }

        if (! is_string($entry['package'] ?? null) || preg_match('/^(composer|npm):\S+\z/', $entry['package']) !== 1) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の package の値は composer:<名> か npm:<名>: ".json_encode($entry));
        }
    }

    /**
     * @return array{kind: string, value: string, scope: string|null, expires: string|null}
     *
     * @throws UnexpectedValueException
     */
    private static function row(string $check, mixed $entry): array
    {
        $kinds = is_array($entry) ? array_values(array_intersect(self::KINDS, array_keys($entry))) : [];

        if (! is_array($entry) || count($kinds) !== 1 || ! is_string($entry[$kinds[0]]) || ! is_string($entry['reason'] ?? null)) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の行は、種類(".implode('・', self::KINDS).')を 1 つと reason を文字列で持つ: '.json_encode($entry));
        }

        $unknownKeys = array_diff(array_keys($entry), [$kinds[0], 'reason', 'scope', 'expires']);

        if ($unknownKeys !== []) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の行に知らないキーがある(綴りの誤りで範囲が黙って広がらないように拒否する): ".json_encode(array_values($unknownKeys)));
        }

        if ($kinds[0] === 'package') {
            self::assertPackageRow($check, $entry);
        }

        return [
            'kind' => $kinds[0],
            'value' => $entry[$kinds[0]],
            'scope' => self::scope($check, $entry),
            'expires' => self::expires($check, $kinds[0], $entry),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     *
     * @throws UnexpectedValueException
     */
    private static function scope(string $check, array $entry): ?string
    {
        if (! array_key_exists('scope', $entry)) {
            return null;
        }

        $scope = $entry['scope'];

        if (! is_string($scope) || ! in_array($scope, self::SCOPES, true)) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の行の scope は ".implode('・', self::SCOPES).' のどれか(書かなければ全範囲): '.json_encode($entry));
        }

        return $scope;
    }

    /**
     * 期限は package の行だけが持ち、package の行は必ず持つ(期限の無い除外を作らない)。形は実在する日付の `YYYY-MM-DD`。
     *
     * @param  array<mixed>  $entry
     *
     * @throws UnexpectedValueException
     */
    private static function expires(string $check, string $kind, array $entry): ?string
    {
        if ($kind !== 'package') {
            if (array_key_exists('expires', $entry)) {
                throw new UnexpectedValueException("検査の除外の台帳の {$check} の行の expires は package の行だけが持つ: ".json_encode($entry));
            }

            return null;
        }

        $expires = $entry['expires'] ?? null;
        $date = is_string($expires) ? DateTimeImmutable::createFromFormat('!Y-m-d', $expires) : false;

        if (! is_string($expires) || $date === false || $date->format('Y-m-d') !== $expires) {
            throw new UnexpectedValueException("検査の除外の台帳の {$check} の package の行は、期限 expires を実在する日付の YYYY-MM-DD で持つ: ".json_encode($entry));
        }

        return $expires;
    }
}
