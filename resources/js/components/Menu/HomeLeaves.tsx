import { cn } from '@/lib/utils';

/** lucide の leaf の外形(葉脈の線は描かない。docs の 設計/メニュー/ホーム.md 6.4) */
const LEAF_PATH =
    'M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10Z';

/** 3 枚の大きさ・位置・角度・不透明度(モックの塗りの形 data-deco="fill") */
const LEAVES = [
    'top-[-70px] right-[230px] w-[220px] rotate-18 opacity-22',
    'right-[300px] bottom-[-70px] w-[150px] -rotate-30 opacity-14',
    'right-[170px] bottom-[-22px] w-[90px] rotate-60 opacity-18',
];

/**
 * あいさつ欄の飾りの葉(塗り。トークン --leaf。読み上げず、押せない。親は relative・overflow-hidden)。
 */
export function HomeLeaves() {
    return (
        <>
            {LEAVES.map((position) => (
                <svg
                    key={position}
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    className={cn(
                        'pointer-events-none absolute fill-leaf',
                        position,
                    )}
                >
                    <path d={LEAF_PATH} />
                </svg>
            ))}
        </>
    );
}
