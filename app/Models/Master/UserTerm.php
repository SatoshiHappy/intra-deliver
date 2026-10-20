<?php

namespace App\Models\Master;

use App\Enums\Master\Sex;
use Database\Factories\Master\UserTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ユーザーの期間の行。表 user_terms(docs の 設計/共通マスタ/)。
 */
#[Fillable(['user_id', 'start_date', 'end_date', 'is_deleted', 'sex'])]
class UserTerm extends Model
{
    /** @use HasFactory<UserTermFactory> */
    use HasFactory;

    use HasPeriod;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_deleted' => 'boolean',
            'sex' => Sex::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<UserTermTranslation, $this>
     */
    public function userTermTranslations(): HasMany
    {
        return $this->hasMany(UserTermTranslation::class);
    }
}
