<?php

namespace App\Models\Records;

use App\Models\Master\User;
use App\Models\Upload\Upload;
use Database\Factories\Records\RecordAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * レコードの添付(レコードの部品)。表 record_attachments(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.3 節)。
 */
#[Fillable(['record_module_id', 'record_id', 'upload_id', 'name', 'user_id'])]
class RecordAttachment extends Model
{
    /** @use HasFactory<RecordAttachmentFactory> */
    use HasFactory;

    /**
     * 付け先のレコード。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 実体(台帳の行)。
     *
     * @return BelongsTo<Upload, $this>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * 付けた人(ゲストが付けたなら null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
