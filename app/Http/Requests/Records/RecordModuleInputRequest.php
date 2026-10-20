<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 業務モジュールを作る入力の封筒(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.2): { code, name, description, icon }。どれも 1 つの値なので、入口で見る
 * 形は無い(規則なし)。値の中身(コードの書式・予約語・一意、名前の空・長さ、U+0000、アイコンが選択肢にあること)は業務処理が確かめて、入力の名前のキーの 422 で拒む
 * (設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3。ほかの呼び手にも同じものが効くように)。値の前後の空白と空文字は既定のミドルウェアが除き、業務処理も同じ変換をする。
 */
class RecordModuleInputRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['code', 'name', 'description', 'icon'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 業務処理の入力(送られたキーだけ)。
     *
     * @return array<string, mixed>
     */
    public function moduleInput(): array
    {
        return $this->only(self::KEYS);
    }
}
