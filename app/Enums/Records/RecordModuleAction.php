<?php

namespace App\Enums\Records;

/**
 * 認可のリソースタイプ「業務モジュール」(record-module)のアクションと、業務モジュールのリソースグループ ID・URI の作り方
 * (docs の 設計/業務データの入れ物/認可とメニュー/README.md 1 節。ID は本書群が決めた)。作り方はここだけに置き、業務処理・読み取り・初期データが使う。
 *
 * セット record-modules の下に、親の無い業務モジュールごとのリソースグループ(record-module-<コード>、URI record-module://module/<コード>)を置く。
 * 明細のモジュールはリソースを持たない。
 */
enum RecordModuleAction: string
{
    /** リソースタイプの ID */
    public const RESOURCE_TYPE = 'record-module';

    /** このタイプのリソースを置くリソースグループセットの ID */
    public const SET_CODE = 'record-modules';

    /** 参照 */
    case Read = 'read';

    /** 登録 */
    case Create = 'create';

    /** 更新 */
    case Update = 'update';

    /** 削除 */
    case Delete = 'delete';

    /** 全件の参照(見える範囲「関係者だけ」の業務モジュールで、自分が関係者でないレコードも参照する = ADR 0018 の決定 4) */
    case ReadAll = 'read-all';

    /**
     * 業務モジュールのコードから、リソースグループ ID を作る。
     */
    public static function resourceGroupCode(string $moduleCode): string
    {
        return self::RESOURCE_TYPE.'-'.$moduleCode;
    }

    /**
     * 業務モジュールのコードから、リソース URI を作る。
     */
    public static function uri(string $moduleCode): string
    {
        return self::RESOURCE_TYPE.'://module/'.$moduleCode;
    }
}
