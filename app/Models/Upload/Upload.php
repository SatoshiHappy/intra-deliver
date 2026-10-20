<?php

namespace App\Models\Upload;

use App\Models\Master\User;
use Database\Factories\Upload\UploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アップロードの台帳(1 行 = 実体 1 つ)。表 uploads(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.2 節)。
 * 持ち主を知らない土台なので、持ち主へのリレーションを持たない(設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 1 節)。
 */
#[Fillable(['path', 'size', 'mime', 'user_id', 'purged_at', 'purge_due_on'])]
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'purged_at' => 'datetime',
            'purge_due_on' => 'date',
        ];
    }

    /**
     * 上げた人(ゲストが上げたなら null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
