<?php

namespace UpdateCheck;

/**
 * #7 の系列を、決まった場所から読む(設計/更新チェック.md 1 節の表): PHP・Node.js は Dockerfile のタグ、Laravel は composer.lock、
 * React は package-lock.json。
 */
final readonly class SeriesReader
{
    /**
     * @param  array<string, ImageSpec>  $specs  ラベル → イメージ(frankenphp・node)
     */
    public function __construct(
        private string $root,
        private ImageReader $images,
        private array $specs,
    ) {}

    /**
     * @throws UnconfirmedException
     */
    public function php(): string
    {
        return $this->fromTag('frankenphp');
    }

    /**
     * @throws UnconfirmedException
     */
    public function node(): string
    {
        return $this->fromTag('node');
    }

    /**
     * @throws UnconfirmedException
     */
    public function laravel(): string
    {
        $lock = Json::parse($this->file('composer.lock'), 'composer.lock');

        foreach (Json::tables(Json::table($lock, 'packages', 'composer.lock'), 'composer.lock の packages') as $package) {
            if (Json::optionalString($package, 'name') === 'laravel/framework') {
                return $this->major(Json::optionalString($package, 'version'), 'laravel/framework');
            }
        }

        throw new UnconfirmedException('composer.lock に laravel/framework が無い');
    }

    /**
     * @throws UnconfirmedException
     */
    public function react(): string
    {
        $lock = Json::parse($this->file('package-lock.json'), 'package-lock.json');
        $packages = Json::table($lock, 'packages', 'package-lock.json');
        $react = Json::optionalTable($packages, 'node_modules/react', 'package-lock.json の packages') ?? [];

        return $this->major(Json::optionalString($react, 'version'), 'react');
    }

    /**
     * @throws UnconfirmedException
     */
    private function fromTag(string $label): string
    {
        $spec = $this->specs[$label] ?? throw new UnconfirmedException("{$label} のイメージの定義が無い");
        $tag = $this->images->read($spec)->tag;

        // 系列を読む規則は ImageCatalog の ImageSpec::$seriesPattern と同じ(1 か所に持つ)
        if ($spec->seriesPattern === null || preg_match($spec->seriesPattern, $tag, $match) !== 1) {
            throw new UnconfirmedException("{$label} のタグから系列を読めない: {$tag}");
        }

        return $match[1];
    }

    /**
     * @throws UnconfirmedException
     */
    private function major(string $version, string $name): string
    {
        if (preg_match('/^v?(\d+)\./', $version, $match) !== 1) {
            throw new UnconfirmedException("{$name} の版から系列を読めない: {$version}");
        }

        return $match[1];
    }

    /**
     * @throws UnconfirmedException
     */
    private function file(string $path): string
    {
        $text = @file_get_contents($this->root.'/'.$path);

        if ($text === false) {
            throw new UnconfirmedException("ファイルを読めない: {$path}");
        }

        return $text;
    }
}
