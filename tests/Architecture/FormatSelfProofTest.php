<?php

namespace Tests\Architecture;

use JsonException;
use Tests\Architecture\Engine\PintFindings;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use UnexpectedValueException;

/**
 * F 整形(#2)の自己証明。scripts/ci/format と同じ pint --test を、同じ pint.json のまま
 * 走査ルートだけ fixture に差し替えて走らせる(pint.json の fixture の除外はアプリのルートからの相対なので、fixture を直接渡すと掛からない)。
 * fixer の名前を出力に出すため -v と --format=json を付ける(判定は変わらない)。
 */
final class FormatSelfProofTest extends SelfProofTestCase
{
    protected function findings(ProcessResult $result): array
    {
        try {
            return PintFindings::fromJson($result->stdout);
        } catch (JsonException|UnexpectedValueException $exception) {
            throw new UnexpectedValueException("Pint の出力を読めない: {$exception->getMessage()}\n".$result->describe(), previous: $exception);
        }
    }

    public function test_detects_a_formatting_difference(): void
    {
        $this->assertDetects('no_unused_imports', 'Format/violating/UnusedImport.php', null, self::format('Format/violating'));
    }

    public function test_passes_formatted_code(): void
    {
        $this->assertClean(self::format('Format/passing'));
    }

    private static function format(string $fixtureRoot): ProcessResult
    {
        return Process::run(
            ['vendor/bin/pint', '--test', '-v', '--format=json', 'tests/Architecture/fixtures/'.$fixtureRoot],
            dirname(__DIR__, 2),
        );
    }
}
