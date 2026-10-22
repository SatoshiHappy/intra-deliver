<?php

namespace App\Actions\Records;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Master\FillsAllLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Menu\MenuIcon;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Records\RecordModule;
use Illuminate\Validation\ValidationException;

/**
 * 親の無い業務モジュールを作る(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、認可とメニュー/README.md 1 節・3 節、メニューと初期データ.md 5.1)。
 * 同じトランザクションで:
 * - 認可のリソース(タイプ record-module、ID record-module-<コード>、URI record-module://module/<コード>、親 = セット record-modules、
 *   表示名 = すべてのロケールに業務モジュールの名前)
 * - 初期ポリシー(ゲストユーザと認証済みユーザに {@see self::EVERYONE_ACTIONS} の 4 つのアクションの許可 = 8 件)
 * - グループ「業務」の 2 つにアイテム({@see AddRecordModuleItems}。表示名 = 業務モジュールの名前、アイコン = 業務モジュールの icon の写し、並び = 作った順)
 * 業務モジュールの icon(データモデル/表.md 2.1)= 入力の icon(無ければ {@see MenuIcon::Table}。{@see MenuIcon::recordModuleChoices()} にあるもの)。
 *
 * 入力 = { code, name, description, icon }(画面/管理の画面.md 3.2。キーはエラーのキー)。コードの書式・予約語・一意、名前、説明、アイコンの選択肢を書く前に確かめる
 * (設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)。セット record-modules か「業務」のグループが無ければ拒む。勧告ロックの下で行う。
 */
class CreateRecordModule
{
    use FillsAllLocales;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use WritesCommonMaster;

    /**
     * 初期ポリシーで全員に許すアクション(認可とメニュー/README.md 3 節)。全件の参照は置かない
     * (置くと、見える範囲を「関係する人だけ」にしても全員が関係しないレコードを見る)。
     */
    public const EVERYONE_ACTIONS = [
        RecordModuleAction::Read,
        RecordModuleAction::Create,
        RecordModuleAction::Update,
        RecordModuleAction::Delete,
    ];

    public function __construct(
        private CreateResource $createResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
        private AddRecordModuleItems $addItems,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(array $input): RecordModule
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($input) {
            [$code, $name, $description, $icon] = $this->validated($input);
            $set = AuthzResourceGroup::query()->where('code', RecordModuleAction::SET_CODE)->first()
                ?? throw ValidationException::withMessages(['module' => ['業務モジュールのセット '.RecordModuleAction::SET_CODE.' がありません']]);
            // 列の既定(添付の使用・見える範囲)を書かずに DB に任せるので、作った後に読み直して返す(戻りの属性が null にならない)
            $module = RecordModule::query()->create(['code' => $code, 'name' => $name, 'description' => $description, 'icon' => $icon->value, 'parent_record_module_id' => null, 'sort_key' => 0])->refresh();
            $names = $this->forAllLocales($name);
            $resource = $this->createResource->execute(
                RecordModuleAction::resourceGroupCode($code),
                $set->id,
                RecordModuleAction::RESOURCE_TYPE,
                RecordModuleAction::uri($code),
                array_map(fn (string $displayName) => ['display_name' => $displayName], $names),
            );
            $this->permitEveryone($resource->authz_resource_group_id);
            $this->addItems->execute($module, $names);

            return $module;
        });
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{string, string, string|null, MenuIcon}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function validated(array $input): array
    {
        $code = $input['code'] ?? null;
        $name = $input['name'] ?? null;
        $description = $input['description'] ?? null;
        $rawIcon = $input['icon'] ?? null;
        $this->throwIfAny([
            'code' => $this->codeError($code) ?? (RecordModule::query()->where('code', $code)->exists() ? 'このコードは既に使用されています' : null),
            'name' => $this->textError($name, '名前'),
            'description' => $this->descriptionError($description),
            'icon' => $rawIcon === null ? null : $this->iconError($rawIcon),
        ]);

        return [(string) $code, (string) $name, is_string($description) ? $description : null, MenuIcon::tryRecordModuleChoice($rawIcon) ?? MenuIcon::Table];
    }

    /**
     * ゲストユーザと認証済みユーザ(既存の 1 条件の対象者。無ければ作る)に、{@see self::EVERYONE_ACTIONS} の許可を置く。
     */
    private function permitEveryone(int $resourceGroupId): void
    {
        foreach ([MetaSubject::Anonymous, MetaSubject::Authenticated] as $metaSubject) {
            $subjectGroupId = $this->findOrCreate->execute(new SubjectConditionInput(metaSubject: $metaSubject), [])->id;

            foreach (self::EVERYONE_ACTIONS as $action) {
                $this->createPolicy->execute($resourceGroupId, $subjectGroupId, RecordModuleAction::RESOURCE_TYPE, $action->value, PolicyEffect::Permit);
            }
        }
    }
}
