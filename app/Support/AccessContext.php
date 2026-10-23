<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 要求ごとの文脈(判定の入力と、判定に使わない訪問者の ID)を決める 1 か所(本家のアクセスコンテキストに当たる値。
 * docs の 設計/メニュー/判定の入力.md 5 節)。判定の入力 = ルートの認可とメニューの表示判定に渡す、判定を求める者・基準日・ロケール。
 * ユーザーと選んだ組織の取り方 = 設計/デモユーザー切替/今の利用者.md 5.4。
 *
 * 拡張点(判定に使う値を足すときは、ここと Requester::fromAccessContext()・Requester・JudgeRequesters::forUserIn() に足す。
 * いずれも App\Queries\Authz の下)。
 */
final readonly class AccessContext
{
    /** 「今日」を決めるタイムゾーン(アプリと DB のタイムゾーンは UTC のまま) */
    public const TIMEZONE = 'Asia/Tokyo';

    /** 表示のロケール(多言語は対象外) */
    public const LOCALE_CODE = 'ja';

    /** 利用者が選んだ組織(departments.id)を持つセッションの鍵(docs の 設計/デモユーザー切替/今の利用者.md 5.5) */
    public const CURRENT_DEPARTMENT_SESSION_KEY = 'current_department_id';

    /** 訪問者の ID を渡す要求のヘッダー(公開の入口の Worker が付け直す = docs の 設計/公開の配備/入口/README.md 5.2) */
    public const VISITOR_ID_HEADER = 'X-Visitor-Id';

    /** 基準日 d だけを覚える要求の属性の鍵({@see self::of()} が覚える全体の鍵 = self::class とは別) */
    private const DATE_ATTRIBUTE = self::class.'::date';

    /**
     * @param  int|null  $userId  判定を求める者のユーザー(users.id)。null = ゲスト
     * @param  string|null  $ipv4Address  接続元の IPv4 アドレス。null = 無い
     * @param  int|null  $selectedDepartmentId  利用者が選んだ組織(departments.id。カレント組織を決める入力)。null = 選んでいない
     * @param  CarbonImmutable  $date  基準日 d(その日の 0 時。日付だけを使う)
     * @param  string  $localeCode  表示のロケールのコード
     * @param  string|null  $visitorId  訪問者の ID(UUID。判定には使わず、AI の依頼の口だけが使う)。null = 無い
     */
    public function __construct(
        public ?int $userId,
        public ?string $ipv4Address,
        public ?int $selectedDepartmentId,
        public CarbonImmutable $date,
        public string $localeCode,
        public ?string $visitorId = null,
    ) {}

    /**
     * 要求ごとの文脈。要求ごとに 1 回だけ作って要求の属性に覚え、同じ要求では同じ値を返す
     * (日付の変わり目で、入口と読み取りの基準日が食い違わないように)。
     *
     * ユーザーは {@see self::userIdOf()}。選んだ組織はセッションの値で、セッションの無い要求(web グループの外)では null。どちらも整数でなければ null。
     * 訪問者の ID は {@see self::visitorIdOf()}。
     */
    public static function of(Request $request): self
    {
        $remembered = $request->attributes->get(self::class);

        if ($remembered instanceof self) {
            return $remembered;
        }

        $selectedDepartmentId = $request->hasSession() ? $request->session()->get(self::CURRENT_DEPARTMENT_SESSION_KEY) : null;

        $context = new self(
            userId: self::userIdOf($request),
            ipv4Address: null,
            selectedDepartmentId: is_int($selectedDepartmentId) ? $selectedDepartmentId : null,
            date: self::dateOf($request),
            localeCode: self::LOCALE_CODE,
            visitorId: self::visitorIdOf($request),
        );
        $request->attributes->set(self::class, $context);

        return $context;
    }

    /**
     * 要求のユーザー(ガードが引けた利用者の識別子。整数でなければ null = ゲスト)を決める 1 か所。要求の属性には覚えない
     * (覚えると、今日有効でない利用者をガードから外すミドルウェアが外す前の利用者を {@see self::of()} に残すため)。
     * Auth::id() は使わない(利用者の行が引けないときもセッションの ID を返すため)。入口はモデル(ガードの利用者)を持たず、これで識別子だけを得る。
     */
    public static function userIdOf(Request $request): ?int
    {
        $userId = $request->user()?->getAuthIdentifier();

        return is_int($userId) ? $userId : null;
    }

    /**
     * 要求のヘッダーの訪問者の ID(読み方 = docs の 設計/メニュー/判定の入力.md 5 節。warning は入口が付ける値の誤りに気づくため)。
     */
    private static function visitorIdOf(Request $request): ?string
    {
        $visitorId = $request->headers->get(self::VISITOR_ID_HEADER);

        if ($visitorId === null || Str::isUuid($visitorId)) {
            return $visitorId;
        }

        Log::warning('要求の訪問者の ID(X-Visitor-Id)が UUID の形でないので、無いものとして扱う');

        return null;
    }

    /**
     * 要求の基準日 d(Asia/Tokyo の当日)を決める 1 か所。要求ごとに 1 回だけ決めて要求の属性に覚え、同じ要求では同じ d を返す。
     * {@see self::of()} は d をこれで得るので、of を呼ぶ前に d が要る入口(今日有効でない利用者をガードから外すミドルウェア)も同じ d を使う
     * (docs の 設計/デモユーザー切替/今の利用者.md 5.4)。
     */
    public static function dateOf(Request $request): CarbonImmutable
    {
        $remembered = $request->attributes->get(self::DATE_ATTRIBUTE);

        if ($remembered instanceof CarbonImmutable) {
            return $remembered;
        }

        $date = CarbonImmutable::today(self::TIMEZONE);
        $request->attributes->set(self::DATE_ATTRIBUTE, $date);

        return $date;
    }
}
