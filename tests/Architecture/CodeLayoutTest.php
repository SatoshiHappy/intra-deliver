<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\LedgerMatch;
use Tests\Architecture\Engine\NamespaceBounds;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\LayerRules;
use Tests\Architecture\Policy\SideEffects;

/**
 * 台帳(config/guardrails_allow.php)と Policy の照合と自己証明(docs の 設計/コード構成/README.md 1 節・2 節・4 節)。
 *
 * - 台帳① の基底ディレクトリには層の規則がある(台帳① ⊆ {@see LayerRules::LAYER_NAMESPACES} の基底ディレクトリ)
 * - 台帳② の種類は副作用の 7 種と一致し、判定する呼び出しも同じ種類を持つ({@see SideEffects::KINDS}・{@see SideEffects::CALLS})
 * - 台帳② は上限に収まる({@see SideEffects::UPPER_BOUNDS})
 */
final class CodeLayoutTest extends TestCase
{
    public function test_every_base_directory_has_a_layer_rule(): void
    {
        $difference = LedgerMatch::subset(self::baseDirectories(), self::layerDirectories());

        $this->assertSame([], $difference->extra, '台帳① に、層の規則(LayerRules)の無い基底ディレクトリがある: '.$difference->describe());
    }

    public function test_detects_a_base_directory_without_a_layer_rule(): void
    {
        $difference = LedgerMatch::subset([...self::baseDirectories(), 'Listeners'], self::layerDirectories());

        $this->assertSame(['Listeners'], $difference->extra);
    }

    public function test_side_effect_kinds_match_the_policy(): void
    {
        $difference = LedgerMatch::equal(array_map('strval', array_keys(self::capabilities())), SideEffects::KINDS);

        $this->assertSame([[], []], [$difference->extra, $difference->missing], '台帳② の種類が副作用の種類と一致しない: '.$difference->describe());
        $this->assertSame(SideEffects::KINDS, array_keys(SideEffects::CALLS), '判定する呼び出しの種類が副作用の種類と一致しない');
        $this->assertSame(SideEffects::KINDS, array_keys(SideEffects::UPPER_BOUNDS), '上限の種類が副作用の種類と一致しない');
    }

    public function test_side_effect_ledger_is_within_the_upper_bounds(): void
    {
        $this->assertSame([], self::describe(NamespaceBounds::findings(self::capabilities(), SideEffects::UPPER_BOUNDS)));
    }

    /**
     * @return array<string, array{array<string, list<string>>, list<string>}>
     */
    public static function ledgersOutsideTheBounds(): array
    {
        return [
            'DB への書き込みを読み取りに' => [['dbWrite' => ['App\\Actions', 'App\\Queries']], ['bounds.outside dbWrite App\\Queries']],
            'HTTP を入口のモジュールに' => [['http' => ['App\\Http\\Controllers\\Web\\Menu']], ['bounds.outside http App\\Http\\Controllers\\Web\\Menu']],
            '名前が業務処理で始まる別の名前空間' => [['queue' => ['App\\ActionsExtra']], ['bounds.outside queue App\\ActionsExtra']],
            'DB への書き込みをジョブに' => [['dbWrite' => ['App\\Actions', 'App\\Jobs\\Ai']], ['bounds.outside dbWrite App\\Jobs\\Ai']],
            'DB への書き込みをコマンドに' => [['dbWrite' => ['App\\Console\\Commands']], ['bounds.outside dbWrite App\\Console\\Commands']],
            '上限の無い種類' => [['mail' => ['App\\Actions']], ['bounds.unknownKind mail']],
        ];
    }

    /**
     * @param  array<string, list<string>>  $ledger
     * @param  list<string>  $expected
     */
    #[DataProvider('ledgersOutsideTheBounds')]
    public function test_detects_a_ledger_entry_outside_the_upper_bounds(array $ledger, array $expected): void
    {
        $this->assertSame($expected, self::describe(NamespaceBounds::findings($ledger, SideEffects::UPPER_BOUNDS)));
    }

    public function test_passes_a_ledger_entry_for_a_module_inside_the_bounds(): void
    {
        $this->assertSame([], self::describe(NamespaceBounds::findings(['http' => ['App\\Actions\\Menu']], SideEffects::UPPER_BOUNDS)));
    }

    public function test_passes_side_effects_other_than_db_writes_for_jobs_and_commands(): void
    {
        $ledger = ['queue' => ['App\\Jobs\\Ai'], 'http' => ['App\\Console\\Commands\\Ai'], 'ai' => ['App\\Jobs']];

        $this->assertSame([], self::describe(NamespaceBounds::findings($ledger, SideEffects::UPPER_BOUNDS)));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private static function describe(array $findings): array
    {
        return array_map(fn (Finding $finding): string => $finding->ruleId.' '.$finding->file, $findings);
    }

    /**
     * @return array<mixed>
     */
    private static function capabilities(): array
    {
        return Values::table(self::allow()['capabilities'] ?? null, '台帳② capabilities');
    }

    /**
     * @return list<string>
     */
    private static function layerDirectories(): array
    {
        return array_map(fn (string $namespace): string => substr($namespace, strlen('App\\')), LayerRules::LAYER_NAMESPACES);
    }

    /**
     * @return list<string>
     */
    private static function baseDirectories(): array
    {
        return Values::strings(self::allow()['base_directories'] ?? null, '台帳① base_directories');
    }

    /**
     * @return array<mixed>
     */
    private static function allow(): array
    {
        return Values::table(require dirname(__DIR__, 2).'/config/guardrails_allow.php', 'config/guardrails_allow.php');
    }
}
