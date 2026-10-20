<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordShowSections;
use App\Support\AccessContext;
use Throwable;

/**
 * テストの中だけで使う詳細の節(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 1 節の口)。決めた名札・件数・中身を返し、
 * 呼ばれた回数を数える。件数と中身は口の型に反した値も渡せる(Records が形を確かめるのを見るため)。中身に例外を渡すと投げる。
 */
final class FixedRecordShowSection implements RecordShowSections
{
    public int $presenceCalls = 0;

    public int $sectionCalls = 0;

    /**
     * @param  mixed  $presence  presence() の結果(口の型は array{count: int|null}|null)
     * @param  mixed  $section  section() の結果(口の型の共用体か null。Throwable なら投げる)
     */
    public function __construct(
        private string $code,
        private string $heading,
        private string $shortHeading,
        private mixed $presence = ['count' => null],
        private mixed $section = null,
    ) {}

    public function descriptor(): array
    {
        return ['code' => $this->code, 'heading' => $this->heading, 'shortHeading' => $this->shortHeading];
    }

    public function presence(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        $this->presenceCalls++;

        if ($this->presence instanceof Throwable) {
            throw $this->presence;
        }

        return $this->presence;
    }

    public function section(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        $this->sectionCalls++;

        if ($this->section instanceof Throwable) {
            throw $this->section;
        }

        return $this->section;
    }
}
