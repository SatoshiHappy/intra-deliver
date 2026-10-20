<?php

namespace App\Models\Master;

use Database\Factories\Master\UserFactory;
use DateTimeInterface;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ユーザー = 本家のプロファイル(期間化するマスタのアンカー)。表 users(docs の 設計/共通マスタ/)。
 */
#[Fillable(['code', 'sort_key'])]
class User extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * パスワード列が無いため、どのパスワードとも合わない空文字を返す(docs の 設計/デモユーザー切替/今の利用者.md 5.2)。
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * remember 列が無いため、remember を持たない空の列名を返す(docs の 設計/デモユーザー切替/今の利用者.md 5.2)。
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return HasMany<UserTerm, $this>
     */
    public function userTerms(): HasMany
    {
        return $this->hasMany(UserTerm::class);
    }

    /**
     * @return HasMany<DepartmentMembership, $this>
     */
    public function departmentMemberships(): HasMany
    {
        return $this->hasMany(DepartmentMembership::class);
    }

    /**
     * d に有効なもの(d に有効な期間の行があるもの。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, DateTimeInterface $date): void
    {
        $query->whereHas('userTerms', fn (Builder $terms) => $terms->effectiveOn($date));
    }
}
