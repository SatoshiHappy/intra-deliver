import type { SVGAttributes } from 'react';

/**
 * 生成物。直接編集しない。正本は resources/brand/mark.svg(生成は scripts/brand/build.mjs。docs の 設計/画面の意匠/骨格とヘッダー.md 3 節)。
 * 主色は currentColor(親の文字色を継承)、白は var(--color-background)。
 */
export function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 48 48"
            aria-hidden="true"
            {...props}
        >
            <path
                fill="currentColor"
                d="M11 10H28A9 9 0 0 1 37 19V29A9 9 0 0 1 28 38H11A9 9 0 0 1 2 29V19A9 9 0 0 1 11 10ZM18.25 4.5H20.75V11H18.25ZM16.5 4.5a3 3 0 1 1 6 0a3 3 0 1 1 -6 0Z"
            />
            <path
                fill="var(--color-background)"
                d="M10 20.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0ZM24 20.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0Z"
            />
            <path
                fill="none"
                stroke="var(--color-background)"
                strokeWidth="2"
                strokeLinecap="round"
                d="M16.75 28.8Q19.5 31.8 22.25 28.8"
            />
            <path
                fill="var(--color-background)"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinejoin="round"
                d="M28.5 27H40.5L46 32.5V46.5H28.5Z M40.5 27V32.5H46"
            />
            <path
                fill="currentColor"
                d="M37.25 32.25Q38.35 36.65 42.75 37.75Q38.35 38.85 37.25 43.25Q36.15 38.85 31.75 37.75Q36.15 36.65 37.25 32.25Z"
            />
            <path
                fill="currentColor"
                stroke="var(--color-background)"
                strokeWidth="2"
                paintOrder="stroke"
                d="M25 43a4 4 0 1 0 8 0a4 4 0 1 0 -8 0Z"
            />
        </svg>
    );
}
