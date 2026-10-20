<?php

namespace App\Queries\Authz;

use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;

/**
 * 認可設定の画面の props can(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.3): 操作している人が、編集のそれぞれの操作のルートを実行できるか。
 * 列の追加は候補を引けないと行えず、列の削除は守りが保存の権限を求めるので、それぞれ 2 つのルートの両方を求める。
 *
 * @phpstan-type AuthzEditCan array{editCells: bool, addSubject: bool, deleteSubject: bool}
 */
class AuthzEditPermissions
{
    /** 列を追加するルート */
    public const SUBJECTS_STORE_ROUTE = 'authz.editor.subjects.store';

    /** 列を削除するルート */
    public const SUBJECTS_DESTROY_ROUTE = 'authz.editor.subjects.destroy';

    /** 列を追加の候補のルート */
    public const SUBJECT_CANDIDATES_ROUTE = 'authz.editor.subject-candidates';

    public function __construct(private AuthzEditGuard $guard) {}

    /**
     * @return AuthzEditCan
     */
    public function of(AccessContext $context): array
    {
        $operator = Requester::fromAccessContext($context);
        $allows = fn (string $routeName) => $this->guard->allowsRoute($operator, $routeName, $context->date);
        $editCells = $allows(AuthzEditGuard::CELLS_ROUTE);

        return [
            'editCells' => $editCells,
            'addSubject' => $allows(self::SUBJECTS_STORE_ROUTE) && $allows(self::SUBJECT_CANDIDATES_ROUTE),
            'deleteSubject' => $allows(self::SUBJECTS_DESTROY_ROUTE) && $editCells,
        ];
    }
}
