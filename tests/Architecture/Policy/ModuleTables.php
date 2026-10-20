<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\PhpStan\ModuleTableWritesRule;

/**
 * 自分のモジュールの表は自分の業務処理だけが書く(Policy。docs の 設計/業務ツール/機械検査.md の検査 (d))。判定本体は Engine の
 * {@see ModuleTableWritesRule}(phpstan.neon がこのクラスの定数を渡す): App\Models\<M> のモデルの書き込みのメソッドを呼べるのは App\Actions\<M> の下だけ
 * (Meeting が AI の実行の記録を、Ai がレコードを直接保存するなど、決めた口・検証・更新履歴を通らない経路を止める)。
 *
 * 書き込みのメソッドは副作用の dbWrite と同じ一覧({@see SideEffects::ELOQUENT_WRITE_CALLS})。書く表のモデルは、受け手がモデルならそのモデル、
 * ビルダ・リレーションなら {@see self::MODEL_TEMPLATES} の型引数(larastan が付ける)。表の名前で書くクエリビルダ(DB::table)は、モデルが分からないので
 * 止まらない(DB への書き込みが業務処理だけなのは台帳②の dbWrite が止める)。照合しないディレクトリは副作用の規則と同じ(DB の初期化・テスト・検査の道具)。
 */
final class ModuleTables
{
    /** モデルの置き場(App\Models\<M>)と、書いてよい業務処理の置き場(App\Actions\<M>) */
    public const MODELS = LayerRules::MODELS;

    public const OWNERS = LayerRules::ACTIONS;

    /**
     * ビルダ・リレーションの型 → 書く表のモデルを持つ型引数の名前(vendor/laravel/framework の @template)。この表に無い型(モデル)は受け手そのものがモデル。
     *
     * @var array<class-string, string>
     */
    public const MODEL_TEMPLATES = [
        'Illuminate\Database\Eloquent\Builder' => 'TModel',
        'Illuminate\Database\Eloquent\Relations\Relation' => 'TRelatedModel',
    ];
}
