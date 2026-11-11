<?php

namespace Tests\Feature\Ai;

use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use LogicException;
use Tests\TestCase;

/**
 * 全部の種類の設定を 1 回で読む口 {@see KindSettings::all()}(docs の 設計/AI/データと失敗と検査.md 8 節): 各コードの形の確かめを 1 回にして、形の合う種類の
 * 設定(コード → 設定)と形の食い違いの文と、形の合わない種類の区分(分からなければ null)を一緒に返す(合う種類だけ・合わない種類だけ・混ざる)。
 * 形の合わない種類が属しうる区分の見積もりの上限は作りの誤り。
 */
final class KindSettingsAllTest extends TestCase
{
    private const MISSING_PROVIDER = '種類 test.bad(prompt)の provider が無いか形が合わない(config/ai_runs.php の kinds)';

    public function test_only_fitting_kinds_are_returned_without_problems(): void
    {
        config(['ai_runs.kinds' => ['test.good' => self::promptKind()]]);

        $this->assertSame(
            [['test.good' => [...self::promptKind(), 'provider_options' => []]], [], []],
            app(KindSettings::class)->all(),
        );
    }

    public function test_only_unfitting_kinds_are_returned_as_problems(): void
    {
        config(['ai_runs.kinds' => ['test.bad' => [...self::promptKind(), 'provider' => '']]]);

        $this->assertSame([[], [self::MISSING_PROVIDER], ['default']], app(KindSettings::class)->all());
    }

    public function test_mixed_kinds_are_split_into_settings_and_problems(): void
    {
        config(['ai_runs.kinds' => [
            'test.bad' => [...self::promptKind(), 'provider' => ''],
            'test.good' => self::promptKind(),
            'test.untyped' => ['type' => 'unknown'],
        ]]);

        [$kinds, $problems, $budgets] = app(KindSettings::class)->all();

        $this->assertSame(['test.good'], array_keys($kinds));
        $this->assertSame([
            self::MISSING_PROVIDER,
            '種類 test.untyped の type が prompt・transcription・conversation のどれでもない(config/ai_runs.php の kinds)',
        ], $problems);
        $this->assertSame(['default', null], $budgets);
    }

    public function test_a_table_that_is_not_an_array_is_a_problem(): void
    {
        config(['ai_runs.kinds' => 'x']);

        $this->assertSame([[], ['config/ai_runs.php の kinds が配列でない'], [null]], app(KindSettings::class)->all());
    }

    public function test_the_budget_ceiling_with_an_unfitting_kind_is_a_construction_error(): void
    {
        config(['ai_runs.kinds' => [
            'test.bad' => [...self::promptKind(), 'provider' => ''],
            'test.good' => self::promptKind(),
        ]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::MISSING_PROVIDER);

        app(RunEstimates::class)->ceilingOf('default');
    }

    public function test_the_budget_ceiling_of_another_budget_is_counted(): void
    {
        config(['ai_runs.kinds' => [
            'test.bad' => [...self::promptKind(), 'provider' => ''],
            'test.other' => [...self::promptKind(), 'budget' => 'transcription'],
        ]]);

        $other = app(KindSettings::class)->all()[0]['test.other'];

        $estimates = app(RunEstimates::class);

        $this->assertSame($estimates->ceilingOfKind($other), $estimates->ceilingOf('transcription'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function promptKind(): array
    {
        return [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
        ];
    }
}
