<?php

namespace UpdateCheck;

/**
 * #6 実行の本体(PHP・PostgreSQL)のパッチ版。イメージの中の版が、その系列の最新の版(endoflife.date)より古ければ更新あり。
 * PHP は #4 と重なることがある(#6 は提供元の版、#4 は取り込めるイメージ)。
 */
final readonly class RuntimeVersionCheck implements Check
{
    /** 本体の名前 → [endoflife.date の製品, 系列に使う版の桁数] */
    public const PRODUCTS = ['PHP' => ['php', 2], 'PostgreSQL' => ['postgresql', 1]];

    public function __construct(
        private RuntimeVersions $runtime,
        private EolClient $eol,
    ) {}

    /**
     * この項目が endoflife.date に照会する製品(先取りの一覧の元。別に書かない)。
     *
     * @return list<string>
     */
    public static function products(): array
    {
        return array_column(self::PRODUCTS, 0);
    }

    public function run(): Section
    {
        $builder = new SectionBuilder('#6', '実行の本体のパッチ版', ['本体', 'イメージの中の版', '系列の最新の版']);

        foreach (self::PRODUCTS as $name => [$product, $parts]) {
            if (isset($this->runtime->reasons[$name])) {
                $builder->unconfirmed($name, $this->runtime->reasons[$name]);

                continue;
            }

            $builder->attempt($name, fn (): array => [$this->row($name, $product, $parts)]);
        }

        return $builder->build();
    }

    /**
     * @throws UnconfirmedException
     */
    private function row(string $name, string $product, int $parts): Row
    {
        $installed = $this->runtime->versions[$name] ?? '';
        $series = implode('.', array_slice(explode('.', $installed), 0, $parts));
        $latest = $this->eol->cycle($product, $series)->latest();

        if ($installed === '' || $latest === '') {
            throw new UnconfirmedException("{$name} の版を比べられない(イメージ: {$installed}・最新: {$latest})");
        }

        return new Row(version_compare($installed, $latest, '<') ? Status::Update : Status::Info, [$name, $installed, $latest]);
    }
}
