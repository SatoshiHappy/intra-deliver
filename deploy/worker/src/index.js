// 公開の入口の Worker。全要求を VPC サービス(Tunnel の先の app)へ中継する。設計 = 公開の配備の入口 5.2・5.3。
import { UNAVAILABLE_PAGE } from './unavailable-page.js';

/**
 * @typedef {object} Env
 * @property {{ fetch: (request: Request) => Promise<Response> }} APP VPC サービスの binding(wrangler.jsonc の vpc_services)
 */

// VPC サービスへ渡す宛先。接続先は VPC サービスの設定が決め、この host は Host ヘッダーになり、port は無視される。
const ORIGIN = 'http://app:8080';

// 案内ページに替える状態(中継の層・app の停止やメンテナンスモード)。app 自身のそれ以外の 4xx・5xx はそのまま返す。
const UNAVAILABLE_STATUSES = new Set([502, 503, 504]);

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
 * app が信じる転送ヘッダー(Laravel の trustProxies の For・Host・Proto)を、訪問者が付けてきた値に足さず置き換える。
 * app が信じるヘッダーを変えるときは、ここも変える。
 * @param {Request} request
 * @returns {Request}
 */
function toOriginRequest(request) {
    const url = new URL(request.url);
    const headers = new Headers(request.headers);
    const clientIp = request.headers.get('CF-Connecting-IP');

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
     * @returns {Promise<Response>}
     */
    async fetch(request, env) {
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
