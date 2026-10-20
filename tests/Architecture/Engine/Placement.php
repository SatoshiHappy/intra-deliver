<?php

namespace Tests\Architecture\Engine;

/**
 * ディレクトリの直下に置けるもの(ディレクトリとファイルの名前)を照合する(汎用)。照合の向きは 実体 ⊆ 許可。
 * 置き場が無いときは照合しない(未作成の置き場は 0 件)。
 */
final class Placement
{
    /** 許可に無いディレクトリ */
    public const DIRECTORY = 'placement.directory';

    /** 許可に無いファイル */
    public const FILE = 'placement.file';

    /**
     * 置き場ごとの許可(走査ルート $root 起点のパス → 置けるディレクトリとファイルの名前。files が null なら、ファイルは何でも置ける)に
     * 収まらないものを指摘にする(規則 ID = 種類、file = 走査ルート起点のパス)。
     *
     * @param  array<string, array{directories: list<string>, files: list<string>|null}>  $rules
     * @return list<Finding>
     */
    public static function findings(string $root, array $rules): array
    {
        $findings = [];

        foreach ($rules as $path => $rule) {
            $absolute = $root.'/'.$path;

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (LedgerMatch::subset(Directories::names($absolute), $rule['directories'])->extra as $name) {
                $findings[] = new Finding(self::DIRECTORY, $path.'/'.$name, null);
            }

            if ($rule['files'] === null) {
                continue;
            }

            foreach (LedgerMatch::subset(Directories::fileNames($absolute), $rule['files'])->extra as $name) {
                $findings[] = new Finding(self::FILE, $path.'/'.$name, null);
            }
        }

        return $findings;
    }
}
