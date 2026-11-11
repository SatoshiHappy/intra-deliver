<?php

namespace App\Actions\Ai\Instructions;

use LogicException;
use ParseError;

/**
 * 指示の文のファイル 1 つ(文の鍵と文字列の配列を返す PHP。docs の 設計/AI/設定とプロンプト/README.md 11.4 の「文の置き場」)を読む部品。
 * 最初に使う時に 1 回だけ読んで形を確かめ、結果を持つ(文を使わない経路では読まない)。値を使う所では欠けを作りの誤り(LogicException)にし、
 * 起動の時の確かめ {@see self::problems()} では投げずに文にする(docs の 設計/AI/データと失敗と検査.md 8 節)。
 */
final class InstructionTextFile
{
    /** @var array{array<string, string>|string, ParseError|null}|null 読んだ結果(文の配列か誤りの文と、構文の誤り)。読む前は null */
    private ?array $loaded = null;

    public function __construct(public readonly string $path) {}

    /**
     * 鍵の文。
     *
     * @throws LogicException ファイルが無い・読めない・形が合わない・鍵が無いとき
     */
    public function text(string $key): string
    {
        [$texts, $error] = $this->loaded();

        if (is_string($texts)) {
            throw new LogicException($texts, 0, $error);
        }

        return $texts[$key] ?? throw new LogicException($this->missing($key));
    }

    /**
     * 起動の時の確かめ: ファイルが無い・読めない・形が合わない・要る鍵が無い・置き換え口が許す一覧(docs の
     * 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6)と値を入れないで済む口に無い・置き換え口がその文に呼び手が渡す鍵と値を入れないで済む口に
     * 無い。無ければ空。
     *
     * @param  array<string, list<string>>  $valueKeys  要る文の鍵と、その文に呼び手が渡す値の鍵
     * @param  list<string>  $optional  値を入れないで済む口
     * @return list<string>
     */
    public function problems(array $valueKeys, array $optional): array
    {
        [$texts] = $this->loaded();

        if (is_string($texts)) {
            return [$texts];
        }

        $absent = array_values(array_diff(array_keys($valueKeys), array_keys($texts)));
        $problems = array_map($this->missing(...), $absent);
        $allowed = [...InstructionPlaceholders::ALLOWED, ...$optional];

        foreach ($texts as $key => $text) {
            $passed = [...($valueKeys[$key] ?? InstructionPlaceholders::ALLOWED), ...$optional];

            foreach (InstructionPlaceholders::namesIn($text) as $name) {
                $problem = $this->placeholderProblem($key, $name, $allowed, $passed);

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * 文の 1 つの置き換え口の食い違い(許す一覧に無い・その文に渡さない)。無ければ null。
     *
     * @param  list<string>  $allowed  許す一覧と値を入れないで済む口
     * @param  list<string>  $passed  その文に呼び手が渡す鍵と値を入れないで済む口
     */
    private function placeholderProblem(string $key, string $name, array $allowed, array $passed): ?string
    {
        $where = "指示の文 {$key}({$this->path})に";

        return match (true) {
            ! in_array($name, $allowed, true) => "{$where}許す一覧に無い置き換え口 {{{$name}}} がある",
            ! in_array($name, $passed, true) => "{$where}その文に渡さない置き換え口 {{{$name}}} がある",
            default => null,
        };
    }

    /**
     * @return array{array<string, string>|string, ParseError|null}
     */
    private function loaded(): array
    {
        return $this->loaded ??= self::read($this->path);
    }

    /**
     * ファイルの文の配列か読めないときの誤りの文と、構文の誤り。
     *
     * @return array{array<string, string>|string, ParseError|null}
     */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            return ["指示の文のファイル {$path} が無い", null];
        }

        try {
            $texts = require $path;
        } catch (ParseError $exception) {
            return ["指示の文のファイル {$path} を読めない: {$exception->getMessage()}", $exception];
        }

        return [self::isTexts($texts) ? $texts : "指示の文のファイル {$path} が文の鍵と文字列の配列を返さない", null];
    }

    /** @phpstan-assert-if-true array<string, string> $texts */
    private static function isTexts(mixed $texts): bool
    {
        if (! is_array($texts)) {
            return false;
        }

        foreach ($texts as $key => $text) {
            if (! is_string($key) || ! is_string($text)) {
                return false;
            }
        }

        return true;
    }

    private function missing(string $key): string
    {
        return "指示の文 {$key} が {$this->path} に無い";
    }
}
