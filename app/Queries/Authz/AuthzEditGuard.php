<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ServiceAction;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * 認可の編集の守り(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節): 書いた後に、ルートの認可と同じ判定({@see AuthorizationQuery::allowsUri()})で、
 * 操作している人が認可設定を開くと保存するの両方を許され、守るルート({@see GuardedRouteCatalog})のそれぞれを指定の判定を求める者が許されるか。
 * 開くと保存するを別のルートで持つので、両方を求める(片方だけ残ると、開けるのに直せない・直せるのに開けない)。
 */
class AuthzEditGuard
{
    /** 認可設定を開くルート */
    public const OVERVIEW_ROUTE = 'authz.overview';

    /** 認可設定を保存する(セルを切り替える)ルート */
    public const CELLS_ROUTE = 'authz.editor.cells.update';

    /** 守りに外れたときの文(認可設定の編集.md 2.3 の 6) */
    public const VIOLATION_MESSAGE = 'この変更を行うと認可設定を使用できなくなるため、変更できません。';

    public function __construct(
        private AuthorizationQuery $authorization,
        private RouteResources $routeResources,
        private GuardedRouteCatalog $guardedRoutes,
    ) {}

    /**
     * 守りが成り立つことを確かめる(呼び手の業務処理のトランザクションの中で、書いた後に呼ぶ)。
     *
     * @param  string  $errorKey  外れたときのエラーのキー
     *
     * @throws ValidationException 守りに外れたとき
     */
    public function assertKept(AccessContext $context, string $errorKey): void
    {
        if (! $this->isKept($context)) {
            throw ValidationException::withMessages([$errorKey => [self::VIOLATION_MESSAGE]]);
        }
    }

    /**
     * 判定を求める者が d に、ルートの execute を許されるか(ルートの認可と同じ判定)。
     */
    public function allowsRoute(Requester $requester, string $routeName, DateTimeInterface $date): bool
    {
        $uri = $this->routeResources->uriOf($routeName);

        return $this->authorization->allowsUri($requester, $uri, ServiceAction::Execute->value, $date);
    }

    private function isKept(AccessContext $context): bool
    {
        $operator = Requester::fromAccessContext($context);

        foreach ([self::OVERVIEW_ROUTE, self::CELLS_ROUTE] as $routeName) {
            if (! $this->allowsRoute($operator, $routeName, $context->date)) {
                return false;
            }
        }

        // 守るルートの判定を求める者はゲストだけ(GuardedRoute が保証する)。判定する人の選択のゲストと同じ作り方(認可設定の編集.md 4 節)
        $guest = JudgeRequesters::forUserIn($context, null);

        foreach ($this->guardedRoutes->routes() as $guardedRoute) {
            if (! $this->allowsRoute($guest, $guardedRoute->routeName, $context->date)) {
                return false;
            }
        }

        return true;
    }
}
