<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordShowSections;

/**
 * テストの詳細の節({@see FixedRecordShowSection})を、起動と同じタグ {@see RecordShowSections::TAG} で登録する手助け。
 */
trait RegistersRecordShowSections
{
    private int $registeredRecordShowSections = 0;

    /**
     * 節を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     */
    protected function registerRecordShowSection(FixedRecordShowSection $section): FixedRecordShowSection
    {
        $name = 'test.record-show-sections.'.$this->registeredRecordShowSections++;
        $this->app->instance($name, $section);
        $this->app->tag([$name], RecordShowSections::TAG);

        return $section;
    }

    /**
     * 帯に出る節(件数つき)と、開いた時の中身(list の 0 件)。
     */
    protected function listSection(
        string $code,
        string $heading,
        string $shortHeading,
        ?int $count = 2,
    ): FixedRecordShowSection {
        return new FixedRecordShowSection($code, $heading, $shortHeading, ['count' => $count], [
            'kind' => 'list', 'count' => $count, 'items' => [], 'emptyText' => 'ありません', 'more' => null,
        ]);
    }
}
