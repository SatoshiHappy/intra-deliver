<?php

namespace Tests\Architecture;

use App\Actions\Records\ValidatesDefinitionText;
use App\Support\DefinitionText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * 定義のコードの書式の写しの整合(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.2・3.4)。画面の入力時の検査 resources/js/lib/Records/recordDefinitionCode.ts は、
 * 業務処理のコードの書式(App\Support\DefinitionText の CODE_PATTERN、App\Actions\Records\ValidatesDefinitionText の OPTION_CODE_PATTERN)とその誤りの文言の写し
 * (二重の定義を受け入れ、このテストで結ぶ)。TS に、`formatError(code, /<PHP の本体>/, <文言の定数>)` の呼び出しと、
 * `const <文言の定数> = '<PHP の文言>'` の宣言が、PHP と同じ文字列で現れることを確かめる(片方だけ変わると失敗。
 * 書式の組と文言の組が入れ替わっても、文言の定数の値が入れ替わっても失敗)。
 *
 * PHP の定数の修飾子はちょうど `D`(PHP の `$` を TS の `$` と同じく文字列の末尾だけに合わせる = docs の 設計/コード構成/否決案と指針.md 8 節)。
 *
 * 自己証明: 本体・文言を変えた TS、呼び出しの文言の定数を入れ替えた TS、文言の定数の値を入れ替えた TS で、指摘になること。修飾子が無い・`D` 以外を含む定数で失敗すること。
 */
final class RecordDefinitionCodeMirrorTest extends TestCase
{
    /** 写している PHP の定数(正規表現)と、TS で対応する文言の定数の名前。PHP の文言は、その正規表現で照合する match の腕から読む */
    private const MIRRORED = ['CODE_PATTERN' => 'CODE_MESSAGE', 'OPTION_CODE_PATTERN' => 'OPTION_CODE_MESSAGE'];

    /** PHP の定数を持つクラスとそのファイル(定数ごとに読み先を分ける) */
    private const SOURCES = [
        'CODE_PATTERN' => [DefinitionText::class, '/app/Support/DefinitionText.php'],
        'OPTION_CODE_PATTERN' => [ValidatesDefinitionText::class, '/app/Actions/Records/ValidatesDefinitionText.php'],
    ];

    public function test_the_screen_mirrors_the_patterns_and_messages_of_the_business_process(): void
    {
        $this->assertSame([], self::problems(self::tsSource()));
    }

    public function test_detects_a_pattern_that_differs(): void
    {
        $changed = str_replace('{0,49}$/', '{0,48}$/', self::tsSource());

        $this->assertSame(['正規表現と文言の対応の呼び出しが TS に無い: CODE_PATTERN'], self::problems($changed));
    }

    public function test_detects_a_message_that_differs(): void
    {
        $changed = str_replace('50 文字までにしてください', '50 文字以内にしてください', self::tsSource());

        $this->assertSame(
            ['文言の宣言が TS に無い: CODE_PATTERN', '文言の宣言が TS に無い: OPTION_CODE_PATTERN'],
            self::problems($changed),
        );
    }

    public function test_detects_the_message_constants_swapped_in_the_calls(): void
    {
        $changed = strtr(self::tsSource(), [', CODE_MESSAGE);' => ', OPTION_CODE_MESSAGE);', ', OPTION_CODE_MESSAGE);' => ', CODE_MESSAGE);']);

        $this->assertSame(
            ['正規表現と文言の対応の呼び出しが TS に無い: CODE_PATTERN', '正規表現と文言の対応の呼び出しが TS に無い: OPTION_CODE_PATTERN'],
            self::problems($changed),
        );
    }

    public function test_detects_the_values_of_the_message_constants_swapped(): void
    {
        $changed = strtr(self::tsSource(), [
            self::messageOf('CODE_PATTERN') => self::messageOf('OPTION_CODE_PATTERN'),
            self::messageOf('OPTION_CODE_PATTERN') => self::messageOf('CODE_PATTERN'),
        ]);

        $this->assertSame(
            ['文言の宣言が TS に無い: CODE_PATTERN', '文言の宣言が TS に無い: OPTION_CODE_PATTERN'],
            self::problems($changed),
        );
    }

    public function test_reads_the_body_of_a_pattern_with_exactly_the_d_modifier(): void
    {
        $this->assertSame('^[a-z]$', self::bodyOf('/^[a-z]$/D'));
    }

    #[TestWith(['/^[a-z]$/'], '修飾子なし')]
    #[TestWith(['/^[a-z]$/Du'], 'D と u')]
    #[TestWith(['/^[a-z]$/i'], 'i')]
    public function test_detects_a_pattern_without_exactly_the_d_modifier(string $pattern): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('/本体/D の形でない');

        self::bodyOf($pattern);
    }

    /**
     * @return list<string>
     */
    private static function problems(string $ts): array
    {
        $problems = [];

        foreach (self::MIRRORED as $constant => $messageConstant) {
            $body = preg_quote(self::patternBody($constant), '/');
            $message = preg_quote(self::messageOf($constant), '/');

            if (preg_match("/formatError\(\s*code,\s*\/{$body}\/,\s*{$messageConstant}\s*\)/", $ts) !== 1) {
                $problems[] = "正規表現と文言の対応の呼び出しが TS に無い: {$constant}";
            }

            if (preg_match("/const {$messageConstant} =\s*'{$message}'/", $ts) !== 1) {
                $problems[] = "文言の宣言が TS に無い: {$constant}";
            }
        }

        return $problems;
    }

    /**
     * PHP の定数(`/本体/D` の本体)。
     */
    private static function patternBody(string $constant): string
    {
        $pattern = (new ReflectionClass(self::SOURCES[$constant][0]))->getConstants()[$constant] ?? null;

        if (! is_string($pattern)) {
            throw new RuntimeException("{$constant} が文字列でない");
        }

        return self::bodyOf($pattern);
    }

    /**
     * 正規表現の文字列(`/本体/修飾子`)の本体。修飾子がちょうど `D` でなければ失敗する。
     */
    private static function bodyOf(string $pattern): string
    {
        if (preg_match('#\A/(?<body>.*)/(?<modifiers>[a-zA-Z]*)\z#s', $pattern, $parts) !== 1 || $parts['modifiers'] !== 'D') {
            throw new RuntimeException("{$pattern} が /本体/D の形でない");
        }

        return $parts['body'];
    }

    /**
     * 書式に合わないときの誤りの文言(定数を持つファイルの `self::<定数>, $code) !== 1 => '文言'`)。
     */
    private static function messageOf(string $constant): string
    {
        if (preg_match('/self::'.$constant.', \$code\) !== 1 => \'(?<message>[^\']+)\'/', self::read(self::SOURCES[$constant][1]), $match) !== 1) {
            throw new RuntimeException("{$constant} の誤りの文言が見つからない");
        }

        return $match['message'];
    }

    private static function tsSource(): string
    {
        return self::read('/resources/js/lib/Records/recordDefinitionCode.ts');
    }

    private static function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).$path);

        if ($source === false) {
            throw new RuntimeException("{$path} を読めない");
        }

        return $source;
    }
}
