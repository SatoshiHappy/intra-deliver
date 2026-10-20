<?php

namespace UpdateCheck;

/**
 * 結果の全体。先頭行の件数と、#1〜#8 ごとの表と、実行の URL を Markdown にする(設計/更新チェック.md 4 節)。
 */
final readonly class Report
{
    /** コメントの長さの上限(GitHub のコメントは 65,536 文字まで) */
    public const COMMENT_LIMIT = 60000;

    /**
     * @param  list<Section>  $sections
     */
    public function __construct(
        private array $sections,
        private string $runUrl,
    ) {}

    public function count(Status $status): int
    {
        return array_sum(array_map(static fn (Section $section): int => $section->count($status), $this->sections));
    }

    public function unconfirmedCount(): int
    {
        return array_sum(array_map(static fn (Section $section): int => count($section->unconfirmed), $this->sections));
    }

    /**
     * 先頭行。書くジョブがこの形から件数を読む(5 節)。
     */
    public function headline(): string
    {
        return sprintf(
            '要対応 %d 件 / 更新あり %d 件 / 確認できなかった %d 件 / 土台で未修正の脆弱性 %d 件',
            $this->count(Status::Action),
            $this->count(Status::Update),
            $this->unconfirmedCount(),
            $this->count(Status::Unfixed),
        );
    }

    /**
     * Job Summary 用の全文。
     */
    public function full(): string
    {
        $parts = [$this->headline(), $this->runUrl === '' ? '実行: ローカル' : '実行: '.$this->runUrl];

        foreach ($this->sections as $section) {
            $parts[] = $this->section($section);
        }

        return implode("\n\n", $parts)."\n";
    }

    /**
     * コメント用。上限を超えたら上限で切り、切ったことと全文が Job Summary にあることを書く。先頭行は残る。
     */
    public function comment(int $limit = self::COMMENT_LIMIT): string
    {
        $full = $this->full();

        if (Text::length($full) <= $limit) {
            return $full;
        }

        $notice = "\n> 長さの上限(".number_format($limit)." 文字)で切った。全文は実行の Job Summary にある。\n";

        // 行の境目で切る(コードの囲み・表の行の途中で切ると、外の文字列が生の Markdown になる)
        return Text::cutLines($full, $limit - Text::length($notice) - 1)."\n".$notice;
    }

    private function section(Section $section): string
    {
        // 見出しに裸の #数字を書かない(Issue への自動リンクになり、毎回「mentioned in」が付く)
        $lines = ['### 項目 '.ltrim($section->id, '#').' '.$section->title];

        if ($section->rows !== []) {
            $lines[] = Markdown::table(
                ['区分', ...$section->headers],
                array_map($this->cells(...), $section->rows),
            );
        }

        $unconfirmed = array_map(
            static fn (Unconfirmed $item): string => '- 確認できなかった: '.Markdown::code($item->subject).' — '.Markdown::code($item->reason),
            $section->unconfirmed,
        );

        if ($unconfirmed !== []) {
            $lines[] = implode("\n", $unconfirmed);
        }

        if ($section->rows === [] && $section->unconfirmed === []) {
            $lines[] = '該当なし';
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function cells(Row $row): array
    {
        return [$row->status->value, ...array_map(Markdown::code(...), $row->cells)];
    }
}
