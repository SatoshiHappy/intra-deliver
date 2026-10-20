<?php

namespace App\Actions\Alpha\Api;

use App\Models\Records\Record;

/**
 * 公開の口のクラスの public プロパティ(昇格でない)にモデル
 */
final class ExposesModel
{
    public ?Record $record = null;
}
