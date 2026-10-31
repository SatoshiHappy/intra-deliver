<?php

namespace Tests\Feature\Deploy;

use Tests\Support\BootsWithEnvironmentOverrides;
use Tests\Support\PublishSettingsTemplate;
use Tests\TestCase;

/**
 * 公開用の app.env の雛形の会議の値で起動したアプリで、会議の削除予定日の既定の期間が 30 日になる
 * (docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.1)。値は環境変数として置いてからアプリを作る
 * (仕組みは BootsWithEnvironmentOverrides。PublishedAiSettingsTest と同じ作り)。DB は使わない
 * (共有の初期データが公開の値で入らないようにするため。設定が既定値に入ることは BuiltinSeederTest が見る)。
 */
final class PublishedMeetingSettingsTest extends TestCase
{
    use BootsWithEnvironmentOverrides;

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        return array_filter(
            PublishSettingsTemplate::section('app.env'),
            fn (string $name): bool => str_starts_with($name, 'MEETING_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function test_the_purge_period_of_the_template_is_thirty_days(): void
    {
        $this->assertSame('P30D', config('meeting.purge_after'));
    }
}
