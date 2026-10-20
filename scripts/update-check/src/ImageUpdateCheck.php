<?php

namespace UpdateCheck;

/**
 * #4 固定しているイメージの新しい版(タグの一覧から同じ形の新しいタグを探す)と、固定したタグが今指す digest の変化。
 */
final readonly class ImageUpdateCheck implements Check
{
    public function __construct(private ImageUpdateResults $results) {}

    public function run(): Section
    {
        $builder = new SectionBuilder('#4', '固定しているイメージの新しい版', ['イメージ', '固定したタグ', '新しいタグ', '固定した digest が今指す先']);

        foreach ($this->results->updates as $label => $update) {
            $builder->attempt($label, fn (): array => [$this->row($label, $update)]);
        }

        foreach ($this->results->failures as $label => $reason) {
            $builder->unconfirmed($label, $reason);
        }

        return $builder->build();
    }

    private function row(string $label, ImageUpdate $update): Row
    {
        return new Row(
            $update->hasUpdate() ? Status::Update : Status::Info,
            [$label, $update->tag, $update->newestTag ?? '(なし)', $update->digestChanged() ? '変わった: '.$update->currentDigest : '同じ'],
        );
    }
}
