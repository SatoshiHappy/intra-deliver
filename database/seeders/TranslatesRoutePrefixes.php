<?php

namespace Database\Seeders;

/**
 * 初期データの seeder が、ルートの接頭辞のリソースグループのロケールの行(表示名と説明)を作る。説明はルートの束だけが持つ
 * (認可設定の画面で行の名前の右の「?」の吹き出しに出す = docs の 設計/メニュー/認可との結び付け.md 2.3 の束の規則 4)。
 */
trait TranslatesRoutePrefixes
{
    /**
     * @param  array{0: string, 1: string, 2?: string, 3?: string}  $names  [表示名(ja), 表示名(en), 説明(ja), 説明(en)]
     * @return array<string, array{display_name: string, description: string|null}>
     */
    private function prefixTranslations(array $names): array
    {
        return [
            'ja' => ['display_name' => $names[0], 'description' => $names[2] ?? null],
            'en' => ['display_name' => $names[1], 'description' => $names[3] ?? null],
        ];
    }
}
