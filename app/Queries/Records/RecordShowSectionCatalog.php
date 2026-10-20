<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordShowSections;
use App\Support\AccessContext;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 業務ツールの節の全体 = タグ {@see RecordShowSections::TAG} で束ねた実装を登録の順に呼ぶ 1 か所(内部。docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節)。名札は読むたびに確かめて例外にし(全部の詳細に効く
 * 実装の誤り)、中身の形の違いは報告して失敗の形にする。実装の例外は握らない。閲覧の判定は呼び手がしてから呼ぶ。
 *
 * @phpstan-import-type RecordShowSectionDescriptor from RecordShowSections
 * @phpstan-import-type RecordShowTableSection from RecordShowSections
 * @phpstan-import-type RecordShowListSection from RecordShowSections
 * @phpstan-import-type RecordShowLinesSection from RecordShowSections
 * @phpstan-import-type RecordShowFailedSection from RecordShowSections
 *
 * @phpstan-type SectionContent RecordShowTableSection|RecordShowListSection|RecordShowLinesSection
 *     |RecordShowFailedSection
 * @phpstan-type SectionPresence array{code: string, heading: string, shortHeading: string, count: int|null}
 */
class RecordShowSectionCatalog
{
    /** 名札の code の形(<モジュール名の小文字>-<節の名前>。Records の欄のキーは - を持たないので重ならない) */
    private const CODE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+\z/';

    /** 帯の名前の最大の文字数(詳細の画面/README.md の 2.1 節) */
    private const SHORT_HEADING_MAX = 4;

    /**
     * @param  iterable<RecordShowSections>  $recordShowSections
     */
    public function __construct(
        #[Tag(RecordShowSections::TAG)] private iterable $recordShowSections,
        private RecordShowSectionShape $shape,
    ) {}

    /**
     * 帯に出す節(登録の順。presence() が null の節を除く)。中身は作らない。
     *
     * @return list<SectionPresence>
     *
     * @throws LogicException 名札の誤り
     */
    public function presences(AccessContext $context, string $moduleCode, int $recordId): array
    {
        $shown = [];

        foreach ($this->sections() as [$descriptor, $section]) {
            $presence = $section->presence($context, $moduleCode, $recordId);

            if ($presence !== null) {
                $shown[] = [...$descriptor, 'count' => $this->countOf($descriptor['code'], $presence)];
            }
        }

        return $shown;
    }

    /**
     * code の節の中身(その節の section() だけを呼ぶ)。code の節が無ければ null、節が null を返したら content が null。
     *
     * @return array{code: string, content: SectionContent|null}|null
     *
     * @throws LogicException 名札の誤り
     */
    public function content(AccessContext $context, string $moduleCode, int $recordId, string $code): ?array
    {
        foreach ($this->sections() as [$descriptor, $section]) {
            if ($descriptor['code'] === $code) {
                $content = $section->section($context, $moduleCode, $recordId);

                return ['code' => $code, 'content' => $this->checked($code, $content)];
            }
        }

        return null;
    }

    /**
     * 確かめた名札と実装の組(登録の順)。
     *
     * @return list<array{RecordShowSectionDescriptor, RecordShowSections}>
     *
     * @throws LogicException 名札の誤り(code の形・重複、空の heading、shortHeading の字数・正式の名前との違い)
     */
    private function sections(): array
    {
        $sections = [];
        $codes = [];

        foreach ($this->recordShowSections as $section) {
            $descriptor = $section->descriptor();
            $this->ensureDescriptor($section::class, $descriptor);

            if (in_array($descriptor['code'], $codes, true)) {
                throw new LogicException("詳細の節の code {$descriptor['code']} が重なる(".$section::class.')');
            }

            $codes[] = $descriptor['code'];
            $sections[] = [$descriptor, $section];
        }

        return $sections;
    }

    /**
     * @param  RecordShowSectionDescriptor  $descriptor
     *
     * @throws LogicException 名札の誤り
     */
    private function ensureDescriptor(string $class, array $descriptor): void
    {
        $short = mb_strlen($descriptor['shortHeading'], 'UTF-8');
        $fitsAsIs = mb_strlen($descriptor['heading'], 'UTF-8') <= self::SHORT_HEADING_MAX;
        $errors = array_filter([
            preg_match(self::CODE_PATTERN, $descriptor['code']) !== 1 ? 'code の形' : null,
            trim($descriptor['heading']) === '' ? '空の heading' : null,
            $short === 0 || $short > self::SHORT_HEADING_MAX ? 'shortHeading の字数' : null,
            $fitsAsIs && $descriptor['shortHeading'] !== $descriptor['heading']
                ? '4 字までの heading と違う shortHeading'
                : null,
        ]);

        if ($errors !== []) {
            throw new LogicException("詳細の節 {$class} の名札の誤り: ".implode('、', $errors));
        }
    }

    /**
     * presence() の件数(形が違えば報告して件数なし)。
     */
    private function countOf(string $code, mixed $presence): ?int
    {
        $valid = is_array($presence) && array_keys($presence) === ['count']
            && ($presence['count'] === null || is_int($presence['count']));

        if (! $valid) {
            report(new LogicException("詳細の節 {$code} の presence() の形が違う"));

            return null;
        }

        return $presence['count'];
    }

    /**
     * 形の違う中身は報告して失敗の形にする(節の口.md の 2 節の 4)。
     *
     * @param  SectionContent|null  $content
     * @return SectionContent|null
     */
    private function checked(string $code, ?array $content): ?array
    {
        if ($content === null || $this->shape->isValid($content)) {
            return $content;
        }

        report(new LogicException("詳細の節 {$code} の section() の形が違う"));

        return ['kind' => 'failed'];
    }
}
