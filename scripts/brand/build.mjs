// 製品の印の正本(resources/brand/mark.svg)から、画面とブラウザに出す印を生成する。追加の依存は無い(Node 標準 + web/ の Playwright)。
// 使い方(web/ で実行): node scripts/brand/build.mjs           … 生成物を書き出す
//                      node scripts/brand/build.mjs --check   … 生成せず、生成物が正本と一致するかを確かめる(違えば終了コード 1)
// 差し替え: resources/brand/mark.svg を置き換え → このスクリプトを実行 → scripts/ci/front を通す。
// 純粋な部分(解析・組み立て・検査)は mark.mjs、単体テストは build.test.mjs。このファイルは引数・ブラウザ・ファイルの書き出しだけの CLI。
// 生成物(正本に書いた 2 色以外・path 以外の要素は失敗する。詳細は mark.mjs):
//   resources/js/components/AppLogoIcon.tsx  ヘッダーの印の React 部品(主色 #246747 → currentColor、白 #ffffff → var(--color-background))
//   public/favicon.svg                        正本のコメントを除いた本体(16 進の色。地は透明)
//   public/apple-touch-icon.png               180x180。淡い緑 #e8f5ec の地に印を幅の約 70% で中央に置く(角丸は iOS が付ける)
//   public/favicon.ico                        32x32 の PNG を 1 枚入れた ICO(地は透明。PNG 入りを読めない古い環境は対象外)
//   resources/brand/generated.json            入力(正本の本体 + 描画の設定)の SHA-256。--check が PNG・ICO の作り忘れを見つけるのに使う
// PNG は Playwright の Chromium で HTML を開いて screenshot する(ブラウザの追加インストールはしない)。
// 書き出しは、PNG と文字列をすべてそろえてから、TSX → favicon.svg → PNG → ICO → generated.json の順(途中で落ちても「指紋だけ新しい」状態にしない)。
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    RENDER_CONFIG,
    buildFaviconSvg,
    buildGeneratedJson,
    buildIco,
    buildTsx,
    check,
    parseMark,
} from './mark.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const path = {
    mark: join(root, 'resources/brand/mark.svg'),
    tsx: join(root, 'resources/js/components/AppLogoIcon.tsx'),
    svg: join(root, 'public/favicon.svg'),
    ico: join(root, 'public/favicon.ico'),
    apple: join(root, 'public/apple-touch-icon.png'),
    generated: join(root, 'resources/brand/generated.json'),
};

/** 正本を PNG にする HTML を作り、Chromium で screenshot する。画像が読めなければ失敗する。 */
async function renderPng(browser, markSvg, { size, markSize, background }) {
    const page = await browser.newPage({
        viewport: { width: size, height: size },
        deviceScaleFactor: 1,
    });
    try {
        const dataUri = `data:image/svg+xml;base64,${Buffer.from(markSvg).toString('base64')}`;
        await page.setContent(
            `<!doctype html><html><body style="margin:0;width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center;background:${background}">` +
                `<img src="${dataUri}" width="${markSize}" height="${markSize}" style="display:block"></body></html>`,
        );
        const naturalWidth = await page.evaluate(async () => {
            const img = document.images[0];
            await img.decode();
            return img.naturalWidth;
        });
        if (!(naturalWidth > 0)) {
            throw new Error('mark.svg を画像として読めない');
        }
        return await page.screenshot({
            omitBackground: background === 'transparent',
            clip: { x: 0, y: 0, width: size, height: size },
        });
    } finally {
        await page.close();
    }
}

async function generate(mark) {
    const { appleSize, appleBackground, appleMarkRatio, icoSize } =
        RENDER_CONFIG;
    const { chromium } = await import('@playwright/test');
    const browser = await chromium.launch();
    let applePng;
    let icoPng;
    try {
        applePng = await renderPng(browser, mark.body, {
            size: appleSize,
            markSize: Math.round(appleSize * appleMarkRatio),
            background: appleBackground,
        });
        icoPng = await renderPng(browser, mark.body, {
            size: icoSize,
            markSize: icoSize,
            background: 'transparent',
        });
    } finally {
        await browser.close();
    }
    writeFileSync(path.tsx, buildTsx(mark));
    writeFileSync(path.svg, buildFaviconSvg(mark));
    writeFileSync(path.apple, applePng);
    writeFileSync(path.ico, buildIco(icoPng, icoSize));
    writeFileSync(path.generated, buildGeneratedJson(mark));
    console.log(
        '印を生成した: AppLogoIcon.tsx / favicon.svg / apple-touch-icon.png / favicon.ico / generated.json',
    );
}

async function main(args) {
    if (args.length > 1 || (args.length === 1 && args[0] !== '--check')) {
        console.error('使い方: node scripts/brand/build.mjs [--check]');
        return 2;
    }
    const source = readFileSync(path.mark, 'utf8');
    if (args[0] === '--check') {
        const problems = check(path, source);
        if (problems.length > 0) {
            console.error(problems.join('\n'));
            console.error('node scripts/brand/build.mjs で生成し直す');
            return 1;
        }
        console.log('印の生成物は正本と一致');
        return 0;
    }
    await generate(parseMark(source));
    return 0;
}

process.exit(await main(process.argv.slice(2)));
