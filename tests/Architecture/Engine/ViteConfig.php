<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * Vite の設定ファイル(vite.config.ts)を読む(汎用)。TypeScript の設定を自前で解釈せず、Vite の読み手(vite-plus の loadConfigFromFile)に
 * JSON で出させる。vite-plus は $toolRoot の node_modules から解決する。
 */
final class ViteConfig
{
    private const LOADER = <<<'JS'
        const { dirname } = await import('node:path');
        const { loadConfigFromFile } = await import('vite-plus');
        const [file, section] = process.argv.slice(1);
        const loaded = await loadConfigFromFile({ command: 'serve', mode: 'test' }, file, dirname(file), 'silent');
        if (loaded === null) {
            console.error('設定ファイルが無い: ' + file);
            process.exit(1);
        }
        process.stdout.write(JSON.stringify(loaded.config[section] ?? {}));
        JS;

    /**
     * 設定 $configuration の test の節(vp test = Vitest の設定)。
     *
     * @return array<mixed>
     *
     * @throws UnexpectedValueException 読めないとき・test の節が表でないとき
     */
    public static function testSettings(string $toolRoot, string $configuration): array
    {
        return self::section($toolRoot, $configuration, 'test');
    }

    /**
     * 設定 $configuration の lint の節(vp lint = oxlint の設定)。
     *
     * @return array<mixed>
     *
     * @throws UnexpectedValueException 読めないとき・lint の節が表でないとき
     */
    public static function lintSettings(string $toolRoot, string $configuration): array
    {
        return self::section($toolRoot, $configuration, 'lint');
    }

    /**
     * @return array<mixed>
     *
     * @throws UnexpectedValueException
     */
    private static function section(string $toolRoot, string $configuration, string $section): array
    {
        return Values::table(
            JsonCommand::run(['node', '--input-type=module', '-e', self::LOADER, $configuration, $section], $toolRoot),
            "{$configuration} の {$section}",
        );
    }
}
