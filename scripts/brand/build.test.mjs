// mark.mjs の単体テスト(node --test 'scripts/brand/*.test.mjs')。ブラウザは使わない。
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import {
    RENDER_CONFIG,
    buildFaviconSvg,
    buildGeneratedJson,
    buildIco,
    buildTsx,
    check,
    icoSize,
    parseMark,
    pngSize,
} from './mark.mjs';

const svg = (inner) =>
    `<!-- 正本のコメント -->\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">\n${inner}\n</svg>\n`;
const validPath =
    '<path fill="#246747" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" d="M0 0H1Z"/>';
const valid = svg(validPath);

/** IHDR だけの PNG(幅・高さの読み取りの検査用)。 */
function fakePng(size) {
    const buffer = Buffer.alloc(33);
    Buffer.from('89504e470d0a1a0a', 'hex').copy(buffer, 0);
    buffer.writeUInt32BE(13, 8);
    buffer.write('IHDR', 12, 'ascii');
    buffer.writeUInt32BE(size, 16);
    buffer.writeUInt32BE(size, 20);
    return buffer;
}

await test('path 以外の要素・fill の無い path は失敗する', () => {
    assert.throws(
        () => parseMark(svg('<circle fill="#246747" r="1"/>')),
        /path 以外/,
    );
    assert.throws(() => parseMark(svg('<path d="M0 0Z"/>')), /fill の無い/);
});

await test('ハイフン属性は camelCase になり、aria-・data- はそのまま、名前空間つきは失敗する', () => {
    const tsx = buildTsx(
        parseMark(
            svg(
                '<path fill="#246747" clip-rule="evenodd" aria-label="x" data-k="1" d="M0 0Z"/>',
            ),
        ),
    );
    assert.match(tsx, /clipRule="evenodd"/);
    assert.match(tsx, /aria-label="x"/);
    assert.match(tsx, /data-k="1"/);
    assert.throws(
        () => parseMark(svg('<path fill="#246747" xlink:href="#a" d="M0Z"/>')),
        /名前空間/,
    );
    assert.match(buildTsx(parseMark(valid)), /strokeLinecap="round"/);
});

await test('既知の 2 色は置換され、none と currentColor は残り、未知の色は失敗する', () => {
    const tsx = buildTsx(parseMark(valid));
    assert.match(tsx, /fill="currentColor"/);
    assert.match(tsx, /stroke="var\(--color-background\)"/);
    assert.doesNotMatch(tsx, /#/);
    const kept = buildTsx(
        parseMark(svg('<path fill="none" stroke="currentColor" d="M0 0Z"/>')),
    );
    assert.match(kept, /fill="none"/);
    assert.match(kept, /stroke="currentColor"/);
    for (const color of ['#ff0000', '#fff', 'red']) {
        assert.throws(
            () => parseMark(svg(`<path fill="${color}" d="M0 0Z"/>`)),
            /使えない/,
        );
    }
});

await test('favicon.svg の本体に正本のコメントが残らない', () => {
    const favicon = buildFaviconSvg(parseMark(valid));
    assert.doesNotMatch(favicon, /<!--/);
    assert.match(favicon, /^<svg /);
    assert.match(favicon, /#246747/);
});

await test('ICO のヘッダーに幅・高さ・枚数と PNG の位置・大きさが入る', () => {
    const png = fakePng(32);
    const ico = buildIco(png, 32);
    assert.equal(ico.readUInt16LE(2), 1);
    assert.equal(ico.readUInt16LE(4), 1);
    assert.equal(ico.readUInt8(6), 32);
    assert.equal(ico.readUInt8(7), 32);
    assert.equal(ico.readUInt32LE(14), png.length);
    assert.equal(ico.readUInt32LE(18), 22);
    assert.deepEqual(ico.subarray(22), png);
    assert.deepEqual(icoSize(ico), { width: 32, height: 32 });
    assert.deepEqual(pngSize(png), { width: 32, height: 32 });
    assert.equal(pngSize(Buffer.from('not a png at all, not at all')), null);
});

await test('check: 一致なら問題なし、指紋・サイズ・本文が違えば問題を返す', () => {
    const dir = mkdtempSync(join(tmpdir(), 'brand-'));
    try {
        const paths = {
            tsx: join(dir, 'AppLogoIcon.tsx'),
            svg: join(dir, 'favicon.svg'),
            apple: join(dir, 'apple.png'),
            ico: join(dir, 'favicon.ico'),
            generated: join(dir, 'generated.json'),
        };
        const mark = parseMark(valid);
        writeFileSync(paths.tsx, buildTsx(mark));
        writeFileSync(paths.svg, buildFaviconSvg(mark));
        writeFileSync(paths.apple, fakePng(RENDER_CONFIG.appleSize));
        writeFileSync(
            paths.ico,
            buildIco(fakePng(RENDER_CONFIG.icoSize), RENDER_CONFIG.icoSize),
        );
        writeFileSync(paths.generated, buildGeneratedJson(mark));
        assert.deepEqual(check(paths, valid), []);

        // 正本を変えて生成し直さない(指紋が古い)
        const changed = valid.replace('M0 0H1Z', 'M0 0H2Z');
        assert.equal(
            check(paths, changed).some((p) => p.includes('generated.json')),
            true,
        );

        writeFileSync(paths.apple, fakePng(100));
        assert.equal(
            check(paths, valid).some((p) => p.includes('apple.png')),
            true,
        );
    } finally {
        rmSync(dir, { recursive: true });
    }
});
