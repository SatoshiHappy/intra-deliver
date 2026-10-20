<?php

namespace Tests\Architecture;

use App\Enums\Menu\MenuIcon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * メニューのアイコンの許可表の整合(docs の 設計/画面の意匠/サイドバーとアイコン.md 5.2、設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1)。
 * 正本は PHP の列挙 {@see MenuIcon}、画面の対応表は resources/js/components/MenuIcon.tsx の ICONS。2 つのキーの集合は両方向に一致する
 * (TSX だけにある名前も、列挙だけにある名前も失敗)。業務モジュールに選べる部分集合は、列挙の全ケースの部分集合。
 *
 * 自己証明: 抜き出しと比較は、食い違う文字列(TSX だけ・列挙だけ)を渡して指摘になること、一致する文字列で指摘にならないことを確かめる。
 */
final class MenuIconTableTest extends TestCase
{
    /** 対応表の宣言(キーは `名前:` か `'名前':`) */
    private const TABLE_SOURCE = <<<'TSX'
const ICONS: Record<string, LucideIcon> = {
    house: House,
    'shield-check': ShieldCheck,
    table: Table,
};
TSX;

    public function test_the_table_of_the_screen_and_the_enum_have_the_same_names(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/MenuIcon.tsx');

        $this->assertIsString($source);
        $this->assertSame([], self::differences(self::tableKeys($source), self::enumValues()), '画面の対応表と列挙 MenuIcon の名前が食い違っている');
    }

    public function test_the_choices_of_a_record_module_are_a_subset_of_the_enum(): void
    {
        $choices = array_keys(MenuIcon::recordModuleChoices());

        $this->assertSame([], array_values(array_diff($choices, self::enumValues())));
        $this->assertSame($choices, array_values(array_unique($choices)));
        $this->assertSame(MenuIcon::Table->value, $choices[0], '先頭は既定の table');
    }

    public function test_extracts_both_quoted_and_bare_keys(): void
    {
        $this->assertSame(['house', 'shield-check', 'table'], self::tableKeys(self::TABLE_SOURCE));
    }

    public function test_detects_a_name_only_in_the_table_and_a_name_only_in_the_enum(): void
    {
        $this->assertSame(
            ['画面の対応表だけにある: shield-check', '列挙だけにある: map'],
            self::differences(['house', 'table', 'shield-check'], ['house', 'table', 'map']),
        );
        $this->assertSame([], self::differences(['house', 'table'], ['table', 'house']));
    }

    /**
     * 対応表(ICONS の宣言)のキー。
     *
     * @return list<string>
     */
    private static function tableKeys(string $source): array
    {
        if (preg_match('/const ICONS[^=]*=\s*\{(?<body>.*?)^\};/ms', $source, $block) !== 1) {
            throw new RuntimeException('ICONS の宣言が見つからない');
        }

        preg_match_all("/^\s*'?(?<name>[a-z0-9-]+)'?:/m", $block['body'], $matches);

        return $matches['name'];
    }

    /**
     * @return list<string>
     */
    private static function enumValues(): array
    {
        return array_map(fn (MenuIcon $icon) => $icon->value, MenuIcon::cases());
    }

    /**
     * @param  list<string>  $table
     * @param  list<string>  $enum
     * @return list<string>
     */
    private static function differences(array $table, array $enum): array
    {
        return [
            ...array_map(fn (string $name) => "画面の対応表だけにある: {$name}", array_values(array_diff($table, $enum))),
            ...array_map(fn (string $name) => "列挙だけにある: {$name}", array_values(array_diff($enum, $table))),
        ];
    }
}
