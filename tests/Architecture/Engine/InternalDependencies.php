<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * ほかのモジュールの内部への依存(汎用)。数え方 = ファイル × 依存先のクラスの組。名前は {@see ClassReferences} で読む。
 *
 * 依存する側 = 名前空間が置き場 `<置き場>\<M>`(またはその下)のファイル。依存される側の内部 = 内部の置き場 `<置き場>\<M>` の下のクラスで、
 * 公開の段 `<置き場>\<M>\<段>` の下でないもの(段の無い置き場は全部が内部)。M が依存する側と同じモジュールなら数えない。
 * 依存先は、アプリの名前空間の根から PSR-4 で決まるファイルが在るクラスだけ(名前空間だけの名前・在らない名前を数えない)。
 */
final readonly class InternalDependencies
{
    /**
     * @param  list<string>  $modules  モジュールの名前
     * @param  list<string>  $dependentPlaces  依存する側の置き場
     * @param  list<array{place: string, modules: list<string>, public: string|null}>  $internalPlaces  依存される側の内部の置き場
     * @param  string  $appNamespace  アプリの名前空間の根(例 = App)
     * @param  string  $appDirectory  その根のディレクトリ(根 $root からの相対パス。例 = app)
     */
    public function __construct(
        private array $modules,
        private array $dependentPlaces,
        private array $internalPlaces,
        private string $appNamespace,
        private string $appDirectory,
    ) {}

    /**
     * ファイル $files(根 $root からの相対パス)の、ほかのモジュールの内部への依存の組(ファイル・依存先のクラスの順)。
     *
     * @param  list<string>  $files
     * @return list<array{file: string, from: string, to: string, class: string}>
     *
     * @throws UnexpectedValueException 読めないとき
     */
    public function pairs(string $root, array $files): array
    {
        $pairs = [];

        foreach ($files as $file) {
            $references = ClassReferences::in(TrackedFiles::contents($root, $file));
            array_push($pairs, ...$this->pairsIn($root, $file, $references));
        }

        return $pairs;
    }

    /**
     * 組の数(`<依存する側> → <依存される側>` → 組の数)。
     *
     * @param  list<array{file: string, from: string, to: string, class: string}>  $pairs
     * @return array<string, int>
     */
    public static function counts(array $pairs): array
    {
        $counts = [];

        foreach ($pairs as $pair) {
            $key = $pair['from'].' → '.$pair['to'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * ファイル $file(名前空間と名指すクラスの名前 = $references)の組。
     *
     * @param  array{namespace: string, names: list<string>}  $references
     * @return list<array{file: string, from: string, to: string, class: string}>
     */
    private function pairsIn(string $root, string $file, array $references): array
    {
        $from = self::moduleOf($references['namespace'], $this->dependentPlaces, $this->modules);

        if ($from === null) {
            return [];
        }

        $pairs = [];

        foreach ($references['names'] as $class) {
            $to = $this->internalModuleOf($class);

            if ($to !== null && $to !== $from && is_file($root.'/'.$this->pathOf($class))) {
                $pairs[] = ['file' => $file, 'from' => $from, 'to' => $to, 'class' => $class];
            }
        }

        return $pairs;
    }

    /**
     * クラス $class が内部の置き場のどれかの内部なら、そのモジュール。
     */
    private function internalModuleOf(string $class): ?string
    {
        foreach ($this->internalPlaces as $place) {
            $module = self::moduleOf($class, [$place['place']], $place['modules']);

            $public = $place['public'] === null ? null : $place['place'].'\\'.$module.'\\'.$place['public'].'\\';

            if ($module !== null && ($public === null || ! str_starts_with($class, $public))) {
                return $module;
            }
        }

        return null;
    }

    /**
     * 名前 $name が置き場 `<置き場>\<M>` かその下にあれば M。
     *
     * @param  list<string>  $places
     * @param  list<string>  $modules
     */
    private static function moduleOf(string $name, array $places, array $modules): ?string
    {
        foreach ($places as $place) {
            foreach ($modules as $module) {
                $prefix = $place.'\\'.$module;

                if ($name === $prefix || str_starts_with($name, $prefix.'\\')) {
                    return $module;
                }
            }
        }

        return null;
    }

    private function pathOf(string $class): string
    {
        $relative = substr($class, strlen($this->appNamespace) + 1);

        if (! str_starts_with($class, $this->appNamespace.'\\')) {
            return '';
        }

        return $this->appDirectory.'/'.str_replace('\\', '/', $relative).'.php';
    }
}
