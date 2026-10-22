<?php

namespace Database\Seeders;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Builtin\Meeting;
use App\Enums\Menu\MenuIcon;
use App\Enums\Records\RowAccessScope;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RelativeDefault;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 組み込みの業務モジュールの投入(docs の 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」、会議 = 設計/業務ツール/会議/README.md の 1 節)。
 * 配備ごとに流すので管理者が変えた定義を戻さず、旗は業務処理の入力から立てられないので作った後にここで立てる。
 */
class BuiltinSeeder extends Seeder
{
    /** 会議の業務モジュールを作る入力(名前・説明・アイコン) */
    private const MEETING_MODULE = [
        'code' => Meeting::CODE, 'name' => '会議', 'description' => '会議の記録と議事録', 'icon' => MenuIcon::Users->value,
    ];

    public function __construct(
        private CreateRecordModule $createModule,
        private UpdateRecordModule $updateModule,
        private AddRecordField $addField,
    ) {}

    /**
     * @throws LogicException 削除予定日の既定の期間の設定が相対の既定値の形でないとき・業務モジュール project が無いとき・
     *                        同じコードの組み込みでない定義があるとき
     */
    public function run(): void
    {
        $purgeAfter = RelativeDefault::tryFromDuration(config('meeting.purge_after'))
            ?? throw new LogicException('設定 meeting.purge_after は相対の既定値の期間(例 P6M)にする');

        DB::transaction(function () use ($purgeAfter) {
            $project = RecordModule::query()->where('code', Meeting::PROJECT_MODULE);

            if (! $project->whereNull('parent_record_module_id')->exists()) {
                throw new LogicException('組み込みの会議は業務モジュール '.Meeting::PROJECT_MODULE.' を要する');
            }

            $module = $this->meetingModule();

            foreach (Meeting::cases() as $field) {
                $this->ensureField($module, $field, $purgeAfter->duration);
            }
        });
    }

    /**
     * 会議の業務モジュール(無ければ作り、見える範囲を関係する人だけにし、添付ファイルを使用にして旗を立てる。音声を添付に残す = 会議 README の 1.2)。
     *
     * @throws LogicException 同じコードの組み込みでない業務モジュール(明細のモジュールを含む)があるとき
     */
    private function meetingModule(): RecordModule
    {
        $module = RecordModule::query()->where('code', Meeting::CODE)->first();

        if ($module !== null) {
            $this->throwIfNotBuiltin($module, Meeting::CODE);

            return $module;
        }

        $module = $this->createModule->execute(self::MEETING_MODULE);
        $this->updateModule->execute(Meeting::CODE, [
            'row_access_scope' => RowAccessScope::Related->value,
            'is_attachment_enabled' => true,
        ]);
        RecordModule::query()->whereKey($module->id)->update(['is_builtin' => true]);

        return $module;
    }

    /**
     * 項目が無ければ末尾に作って旗を立てる。
     *
     * @throws LogicException 同じコードの組み込みでない項目があるとき
     */
    private function ensureField(RecordModule $module, Meeting $field, string $purgeAfter): void
    {
        $current = RecordField::query()->where('record_module_id', $module->id)->where('code', $field->value)->first();

        if ($current !== null) {
            $this->throwIfNotBuiltin($current, Meeting::CODE.'.'.$field->value);

            return;
        }

        $created = $this->addField->execute(Meeting::CODE, $field->definition($purgeAfter));
        RecordField::query()->whereKey($created->id)->update(['is_builtin' => true]);
    }

    /**
     * @throws LogicException 組み込みでないとき(黙って組み込みとして扱わない)
     */
    private function throwIfNotBuiltin(RecordModule|RecordField $definition, string $code): void
    {
        if (! $definition->is_builtin) {
            throw new LogicException("組み込みの定義 {$code} と同じコードの、組み込みでない定義がある");
        }
    }
}
