<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordScreenAddons;

/**
 * テストの一覧と詳細に足す実装({@see FixedRecordScreenAddons})を、起動と同じタグ {@see RecordScreenAddons::TAG} で登録する手助け。
 */
trait RegistersRecordScreenAddons
{
    private int $registeredRecordScreenAddons = 0;

    /**
     * 実装を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     */
    protected function registerRecordScreenAddons(FixedRecordScreenAddons $addons): FixedRecordScreenAddons
    {
        $name = 'test.record-screen-addons.'.$this->registeredRecordScreenAddons++;
        $this->app->instance($name, $addons);
        $this->app->tag([$name], RecordScreenAddons::TAG);

        return $addons;
    }

    /**
     * ダイアログを開く操作(押せる・値を変えない。$overrides で口の型に反した値も作れる)。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function addonAction(string $code, array $overrides = []): array
    {
        return [
            'code' => $code,
            'label' => "{$code}の操作",
            'dialog' => "test.{$code}",
            'props' => ['recordId' => 1],
            'disabledReason' => null,
            'changesValues' => false,
            ...$overrides,
        ];
    }
}
