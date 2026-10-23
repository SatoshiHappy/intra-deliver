// 公開の入口の Worker。Access が通した要求を VPC サービス(Tunnel の先の app)へ中継する。
// 設計 = docs の 設計/公開の配備/入口/README.md 5.2・5.3、入口/Access.md 5.9。
import { UNAVAILABLE_PAGE } from './unavailable-page.js';

/**
 * @typedef {object} Env
 * @property {{ fetch: (request: Request) => Promise<Response> }} APP VPC サービスの binding(wrangler.jsonc の vpc_services)
 */

/**
 * @typedef {object} Identity Access の身元(使う項目だけ)
 * @property {unknown} [user_uuid] Access の利用者の ID
 * @property {unknown} [service_token_status] サービストークンで通った要求なら真
 */

/**
 * @typedef {object} Context
 * @property {{ aud: string, getIdentity: () => Promise<Identity | undefined> }} [access] Access が通した要求に実行環境が付ける
 *     (身元は getIdentity で取る)。Access が通していなければ undefined(入口/Access.md 5.9.1)
 */

// VPC サービスへ渡す宛先。接続先は VPC サービスの設定が決め、この host は Host ヘッダーになり、port は無視される。
const ORIGIN = 'http://app:8080';

// 案内ページに替える状態(中継の層・app の停止やメンテナンスモード)。app 自身のそれ以外の 4xx・5xx はそのまま返す。
const UNAVAILABLE_STATUSES = new Set([502, 503, 504]);

// Access(入口)の資格情報の名前の印。ヘッダーは大文字と小文字を区別せず(Headers は小文字で返す)、cookie の名前は区別する。設計 = 入口/Access.md 5.9.3。
const ACCESS_HEADER_PREFIX = 'cf-access-';
const ACCESS_COOKIE_PREFIX = 'CF_';

// 訪問者の ID を app へ渡すヘッダー。訪問者が付けた値は必ず消し、Worker が取れたときだけ置く(入口/README.md 5.2、入口/Access.md 5.9.6)
const VISITOR_ID_HEADER = 'X-Visitor-Id';

// 身元を取らないメソッド(画面の表示と静的ファイルの要求を待たせない。AI の依頼の口を呼ぶ HTTP の口はこれらにしない = 入口/Access.md 5.9.6)
const METHODS_WITHOUT_IDENTITY = new Set(['GET', 'HEAD']);

// getIdentity() の待ちの上限(ms)。超えたら ID を付けずに中継する(ID の取得で中継を止めない = 入口/Access.md 5.9.6)
const IDENTITY_TIMEOUT_MS = 2000;

// Access が通していない要求に返す 403 の本文。案内ページ・Cloudflare の画面と本文で見分けるので変えない(入口/README.md 5.2)
const FORBIDDEN_TEXT =
    'この要求は入口(ログイン)を通っていないため、受け付けません。';

/** @returns {Response} */
function forbidden() {
    return new Response(FORBIDDEN_TEXT, {
        status: 403,
        headers: {
            'Content-Type': 'text/plain; charset=utf-8',
            'Cache-Control': 'no-store',
        },
    });
}

/** @returns {Response} */
function unavailable() {
    return new Response(UNAVAILABLE_PAGE, {
        status: 503,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
        },
    });
}

/**
 * Access の資格情報(身元の JWT・サービストークン・Access の cookie)を落とす。app は Access を使わず、
 * 落とさないと採用担当のメールアドレスを持つ JWT とサービストークンの秘密が app のアクセスログに残る。
 * @param {Headers} headers
 */
function dropAccessCredentials(headers) {
    // 消しながら Headers を回さない(回している途中の削除で、次の名前を飛ばしうる)。名前を先に集める
    const accessHeaders = Array.from(headers.keys()).filter((name) =>
        name.startsWith(ACCESS_HEADER_PREFIX),
    );
    for (const name of accessHeaders) {
        headers.delete(name);
    }

    const cookie = headers.get('Cookie');
    if (cookie === null) {
        return;
    }
    const kept = cookie
        .split(';')
        .map((pair) => pair.trim())
        .filter(
            (pair) => pair !== '' && !pair.startsWith(ACCESS_COOKIE_PREFIX),
        );
    if (kept.length === 0) {
        headers.delete('Cookie');
    } else {
        headers.set('Cookie', kept.join('; '));
    }
}

/**
 * 待ちの上限を置いて getIdentity() を呼ぶ。上限を超えたら例外にする。
 * @param {NonNullable<Context['access']>} access
 * @returns {Promise<Identity | undefined>}
 */
async function identityWithin(access) {
    /** @type {ReturnType<typeof setTimeout> | undefined} */
    let timer;
    const timeout = new Promise((_resolve, reject) => {
        timer = setTimeout(
            () =>
                reject(
                    new Error(
                        `getIdentity() が ${IDENTITY_TIMEOUT_MS} ms の内に返りませんでした`,
                    ),
                ),
            IDENTITY_TIMEOUT_MS,
        );
    });
    try {
        return await Promise.race([access.getIdentity(), timeout]);
    } finally {
        clearTimeout(timer);
    }
}

/**
 * 訪問者の ID(Access の利用者の ID)。取れなければ null で、中継は続ける。ログに ID の値を出さない(入口/Access.md 5.9.6)。
 * @param {Request} request
 * @param {NonNullable<Context['access']>} access
 * @returns {Promise<string | null>}
 */
async function visitorIdOf(request, access) {
    if (METHODS_WITHOUT_IDENTITY.has(request.method)) {
        return null;
    }

    const startedAt = performance.now();
    /** @type {Identity | undefined} */
    let identity;
    try {
        identity = await identityWithin(access);
    } catch (error) {
        console.error('訪問者の身元を取れませんでした', error);
        return null;
    } finally {
        console.log(
            `訪問者の身元の待ち ${Math.round(performance.now() - startedAt)} ms`,
        );
    }

    const userUuid = identity?.user_uuid;
    if (typeof userUuid === 'string' && userUuid !== '') {
        return userUuid;
    }
    // サービストークンの要求でも user_uuid が空になりうる(入口/Access.md 5.9.6 の検証点 19)
    if (identity?.service_token_status !== true) {
        console.warn('訪問者の身元の user_uuid が空でした');
    }
    return null;
}

/**
 * app が信じる転送ヘッダー(Laravel の trustProxies の For・Host・Proto)と訪問者の ID を、訪問者が付けてきた値に足さず置き換え、
 * Access の資格情報を落とす。app が信じるヘッダーを変えるときは、ここも変える。
 * @param {Request} request
 * @param {string | null} visitorId
 * @returns {Request}
 */
function toOriginRequest(request, visitorId) {
    const url = new URL(request.url);
    const headers = new Headers(request.headers);
    const clientIp = request.headers.get('CF-Connecting-IP');

    dropAccessCredentials(headers);
    headers.delete(VISITOR_ID_HEADER);
    if (visitorId !== null) {
        headers.set(VISITOR_ID_HEADER, visitorId);
    }
    headers.set('X-Forwarded-Host', url.host);
    headers.set('X-Forwarded-Proto', 'https');
    if (clientIp === null) {
        headers.delete('X-Forwarded-For');
    } else {
        headers.set('X-Forwarded-For', clientIp);
    }

    return new Request(`${ORIGIN}${url.pathname}${url.search}`, {
        method: request.method,
        headers,
        body: request.body,
        redirect: 'manual',
    });
}

export default {
    /**
     * @param {Request} request
     * @param {Env} env
     * @param {Context} [ctx]
     * @returns {Promise<Response>}
     */
    async fetch(request, env, ctx) {
        // Access の設定が外れても app へ中継しない(閉じる側に倒す。ctx が無い呼び出しも同じ)
        if (!ctx?.access) {
            return forbidden();
        }

        const visitorId = await visitorIdOf(request, ctx.access);

        let response;
        try {
            response = await env.APP.fetch(toOriginRequest(request, visitorId));
        } catch (error) {
            console.error('中継に失敗しました', error);
            return unavailable();
        }

        if (UNAVAILABLE_STATUSES.has(response.status)) {
            // 本文は使わないので読み捨てる。捨てるのに失敗しても、案内ページを返すことは変えない(記録だけ残す)
            try {
                await response.body?.cancel();
            } catch (error) {
                console.error('中継の応答の本文を捨てられませんでした', error);
            }
            return unavailable();
        }

        return response;
    },
};
