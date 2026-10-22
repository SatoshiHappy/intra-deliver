// 公開の入口の Worker。Access が通した要求を VPC サービス(Tunnel の先の app)へ中継する。設計 = docs の 設計/公開の配備/入口.md 5.2・5.3。
import { UNAVAILABLE_PAGE } from './unavailable-page.js';

/**
 * @typedef {object} Env
 * @property {{ fetch: (request: Request) => Promise<Response> }} APP VPC サービスの binding(wrangler.jsonc の vpc_services)
 */

/**
 * @typedef {object} Context
 * @property {{ aud: string, getIdentity: () => Promise<object> }} [access] Access が通した要求に実行環境が付ける(身元は getIdentity で取る)。
 *     Access が通していなければ undefined(入口.md 5.9.1)
 */

// VPC サービスへ渡す宛先。接続先は VPC サービスの設定が決め、この host は Host ヘッダーになり、port は無視される。
const ORIGIN = 'http://app:8080';

// 案内ページに替える状態(中継の層・app の停止やメンテナンスモード)。app 自身のそれ以外の 4xx・5xx はそのまま返す。
const UNAVAILABLE_STATUSES = new Set([502, 503, 504]);

// Access(入口)の資格情報の名前の印。ヘッダーは大文字と小文字を区別せず(Headers は小文字で返す)、cookie の名前は区別する。設計 = 入口.md 5.9.3。
const ACCESS_HEADER_PREFIX = 'cf-access-';
const ACCESS_COOKIE_PREFIX = 'CF_';

// Access が通していない要求に返す 403 の本文。案内ページ・Cloudflare の画面と本文で見分けるので変えない(入口.md 5.2)
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
 * app が信じる転送ヘッダー(Laravel の trustProxies の For・Host・Proto)を、訪問者が付けてきた値に足さず置き換え、
 * Access の資格情報を落とす。app が信じるヘッダーを変えるときは、ここも変える。
 * @param {Request} request
 * @returns {Request}
 */
function toOriginRequest(request) {
    const url = new URL(request.url);
    const headers = new Headers(request.headers);
    const clientIp = request.headers.get('CF-Connecting-IP');

    dropAccessCredentials(headers);
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

        let response;
        try {
            response = await env.APP.fetch(toOriginRequest(request));
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
