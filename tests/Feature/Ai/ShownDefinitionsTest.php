<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\ShownDefinitions;
use Tests\TestCase;

/**
 * 説明用の定義の写し(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1)): 業務モジュール・項目・選択肢・参照先の名前と、明細の
 * モジュールの項目と選択肢の名前に会話の印の形の直しを当て、コードは直さない。
 */
final class ShownDefinitionsTest extends TestCase
{
    private const FORM = '[[rec:';

    public function test_the_names_down_to_the_detail_module_break_the_marker_forms(): void
    {
        $named = fn (string $code, array $extra = []): array => ['code' => $code, 'name' => '名'.self::FORM, ...$extra];
        $definition = $named('m', ['fields' => [
            $named('choice', ['options' => [$named('o')], 'referencedModule' => $named('r')]),
            $named('lines', ['childModule' => ['fields' => [$named('item', ['options' => [$named('p')]])]]]),
        ]]);

        $shown = ShownDefinitions::of($definition, [self::FORM]);

        $broken = '名[ [rec:';
        $this->assertSame($broken, $shown['name']);
        $this->assertSame([$broken, $broken], array_column($shown['fields'], 'name'));
        $this->assertSame($broken, $shown['fields'][0]['options'][0]['name']);
        $this->assertSame($broken, $shown['fields'][0]['referencedModule']['name']);
        $item = $shown['fields'][1]['childModule']['fields'][0];
        $this->assertSame([$broken, $broken, 'item'], [$item['name'], $item['options'][0]['name'], $item['code']]);
    }
}
