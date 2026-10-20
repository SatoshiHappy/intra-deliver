// 印の生成の、ブラウザを使わない純粋な部分(正本の解析・TSX と favicon.svg の組み立て・ICO の書き出し・生成物の検査)。
// build.mjs(CLI)が呼び、build.test.mjs が単体で確かめる。モジュールの直下に副作用を置かない。
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

/** 主色と白。正本はこの 2 色(16 進 6 桁)だけで書く。 */
const PRIMARY = '#246747';
const PAPER = '#ffffff';
const COLOR_ATTRIBUTES = new Set(['fill', 'stroke']);

/** 描画の設定。PNG・ICO の中身を決めるので、指紋(inputsSha256)に含める。 */
export const RENDER_CONFIG = {
    appleSize: 180,
    appleBackground: '#e8f5ec',
    appleMarkRatio: 0.7,
    icoSize: 32,
};

/** 正本の色の値を TSX の値にする。2 色以外は失敗させる(正本の約束 = 2 色だけ)。 */
function jsxColor(value) {
    const color = value.toLowerCase();
    if (color === PRIMARY) return 'currentColor';
    if (color === PAPER) return 'var(--color-background)';
    if (color === 'none' || color === 'currentcolor') {
        return color === 'none' ? 'none' : 'currentColor';
    }
    throw new Error(
        `mark.svg: 色 ${value} は使えない(${PRIMARY}・${PAPER}・none・currentColor のいずれか。16 進は 6 桁で書く)`,
    );
}

/** SVG の属性名を JSX の名前にする。aria-・data- はそのまま、名前空間(:)入りは扱わない。 */
function jsxAttributeName(name) {
    if (name.includes(':')) {
        throw new Error(`mark.svg: 名前空間つきの属性 ${name} は扱わない`);
    }
    if (name.startsWith('aria-') || name.startsWith('data-')) return name;
    return name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
}

/**
 * 正本の SVG から viewBox と path の属性の並びを取り出す。path 以外の要素・2 色以外の色・fill の無い path は失敗させる。
 * body = コメントを除いた SVG(favicon.svg と指紋に使う)。
 */
export function parseMark(source) {
    const body = source.replace(/<!--[\s\S]*?-->/g, '').trim();
    const viewBox = body.match(/<svg\b[^>]*\sviewBox="([^"]+)"/)?.[1];
    const inner = body.match(/<svg\b[^>]*>([\s\S]*)<\/svg>/)?.[1];
    if (!viewBox || inner === undefined) {
        throw new Error('mark.svg: <svg viewBox="..."> が読めない');
    }
    if (inner.replace(/<(\w+)\b[^>]*?\/>/g, '').trim() !== '') {
        throw new Error('mark.svg: 自己終了の要素以外が含まれる');
    }
    const paths = [...inner.matchAll(/<(\w+)\b([^>]*?)\/>/g)].map(
        ([, tag, attributes]) => {
            if (tag !== 'path') {
                throw new Error(
                    `mark.svg: path 以外の要素 <${tag}> は扱わない`,
                );
            }
            const pairs = [...attributes.matchAll(/([\w:-]+)="([^"]*)"/g)].map(
                ([, name, value]) => [name, value],
            );
            if (!pairs.some(([name]) => name === 'fill')) {
                throw new Error('mark.svg: fill の無い path がある');
            }
            for (const [name, value] of pairs) {
                jsxAttributeName(name);
                if (COLOR_ATTRIBUTES.has(name)) jsxColor(value);
            }
            return pairs;
        },
    );
    return { viewBox, paths, body: `${body}\n` };
}

export function buildTsx({ viewBox, paths }) {
    const pathLines = paths
        .map((attributes) => {
            const lines = attributes.map(([name, value]) => {
                const jsxValue = COLOR_ATTRIBUTES.has(name)
                    ? jsxColor(value)
                    : value;
                return `                ${jsxAttributeName(name)}="${jsxValue}"`;
            });
            return `            <path\n${lines.join('\n')}\n            />`;
        })
        .join('\n');
    return `import type { SVGAttributes } from 'react';

/**
 * 生成物。直接編集しない。正本は resources/brand/mark.svg(生成は scripts/brand/build.mjs。docs の 設計/画面の意匠/骨格とヘッダー.md 3 節)。
 * 主色は currentColor(親の文字色を継承)、白は var(--color-background)。
 */
export function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="${viewBox}"
            aria-hidden="true"
            {...props}
        >
${pathLines}
        </svg>
    );
}
`;
}

/** favicon.svg = 正本のコメントを除いた本体(16 進の色のまま。地は透明)。 */
export function buildFaviconSvg({ body }) {
    return body;
}

/** PNG を 1 枚入れた ICO(ヘッダー 6 バイト + エントリ 16 バイト + PNG)。古い環境(PNG 入りを読めない Windows など)は対象外。 */
export function buildIco(png, size) {
    const header = Buffer.alloc(22);
    header.writeUInt16LE(0, 0); // 予約
    header.writeUInt16LE(1, 2); // 種別 = アイコン
    header.writeUInt16LE(1, 4); // 枚数
    header.writeUInt8(size, 6); // 幅
    header.writeUInt8(size, 7); // 高さ
    header.writeUInt8(0, 8); // パレット数
    header.writeUInt8(0, 9); // 予約
    header.writeUInt16LE(1, 10); // 色プレーン
    header.writeUInt16LE(32, 12); // ビット数
    header.writeUInt32LE(png.length, 14); // データの大きさ
    header.writeUInt32LE(22, 18); // データの位置
    return Buffer.concat([header, png]);
}

/** PNG の IHDR から幅と高さを読む。PNG でなければ null。 */
export function pngSize(buffer) {
    const signature = '89504e470d0a1a0a';
    if (
        buffer.length < 24 ||
        buffer.subarray(0, 8).toString('hex') !== signature
    ) {
        return null;
    }
    return { width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20) };
}

/** PNG 1 枚入りの ICO の、中の PNG の幅と高さ。その形でなければ null。 */
export function icoSize(buffer) {
    if (
        buffer.length < 22 ||
        buffer.readUInt16LE(2) !== 1 ||
        buffer.readUInt16LE(4) !== 1
    ) {
        return null;
    }
    return pngSize(buffer.subarray(22));
}

/** 生成物を決める入力(正本の本体 + 描画の設定)の SHA-256。PNG・ICO の画素を比べない代わりに、入力の変更を見張る。 */
export function inputsSha256({ body }) {
    return createHash('sha256')
        .update(JSON.stringify({ mark: body, render: RENDER_CONFIG }))
        .digest('hex');
}

export function buildGeneratedJson(mark) {
    return `${JSON.stringify({ inputs_sha256: inputsSha256(mark) }, null, 4)}\n`;
}

/**
 * 生成物の検査。問題の文の配列を返す(空なら一致)。paths = { tsx, svg, apple, ico, generated }。
 * TSX と favicon.svg は文字列で比べる。PNG と ICO は、ブラウザの描画がプラットフォームで揺れうる(アンチエイリアスで画素が変わる)
 * ので、存在とサイズだけを確かめ、代わりに入力の指紋(generated.json)の一致を確かめる。
 */
export function check(paths, source) {
    const mark = parseMark(source);
    const problems = [];
    const same = (file, expected) => {
        if (!existsSync(file) || readFileSync(file, 'utf8') !== expected) {
            problems.push(`${file} が正本から作られる内容と違う`);
        }
    };
    same(paths.tsx, buildTsx(mark));
    same(paths.svg, buildFaviconSvg(mark));
    same(paths.generated, buildGeneratedJson(mark));
    const sized = (file, read, expected) => {
        const size = existsSync(file) ? read(readFileSync(file)) : null;
        if (size?.width !== expected || size?.height !== expected) {
            problems.push(`${file} が無いか ${expected}x${expected} でない`);
        }
    };
    sized(paths.apple, pngSize, RENDER_CONFIG.appleSize);
    sized(paths.ico, icoSize, RENDER_CONFIG.icoSize);
    return problems;
}
