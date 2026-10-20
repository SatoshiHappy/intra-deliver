<?php

namespace App\Actions\Alpha\Api;

use App\Models\Records\Record;

/**
 * 公開の口の値のクラスのコンストラクタ(public メソッド)の引数にモデル
 */
final readonly class CarriesModel
{
    public function __construct(public Record $record) {}
}
