<?php

namespace App\Models\Notice;

use Database\Factories\Notice\NoticeRequiredTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 通知の種類 1 つが必須の印(行がある = 必須、無い = 必須でない)。表 notice_required_types(docs の ADR 0050 の決定 1)。
 * 必須を外すと行を消す。利用者の設定({@see NoticeMute})は書き換えず、上に重ねる。
 */
#[Fillable(['type'])]
class NoticeRequiredType extends Model
{
    /** @use HasFactory<NoticeRequiredTypeFactory> */
    use HasFactory;
}
