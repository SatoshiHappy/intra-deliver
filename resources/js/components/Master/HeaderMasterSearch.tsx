import { MasterSearch } from '@/components/Master/MasterSearch';

type Props = {
    visible: boolean;
};

/**
 * ヘッダーの共通マスタの検索欄の場所(docs の 設計/共通マスタ/検索と組織図.md 3.1、設計/画面の意匠/骨格とヘッダー.md 3 節の 4)。
 * 組織図を許されない人(骨格の共有プロパティ appNavigation の showSearch が偽)には欄を描かず、場所は空けたまま(右の箱は右端のまま)。
 * 左の間隔 28px = ヘッダーの並びの gap-3(12px)+ ml-4(16px)。最小の幅 240px(min-w-60)は、パンくずが長いとき欄を押し出さないため。
 */
export function HeaderMasterSearch({ visible }: Props) {
    return (
        <div className="ml-4 flex max-w-[480px] min-w-60 flex-1">
            {visible && <MasterSearch />}
        </div>
    );
}
