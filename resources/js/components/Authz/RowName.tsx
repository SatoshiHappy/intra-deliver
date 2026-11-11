import { TipButton } from '@/components/TipButton';

type Props = {
    name: string;
    /** 行の説明(null = 説明の無い行。「?」を出さない) */
    description: string | null;
};

/**
 * 先頭の列の行の名前。説明のある行は名前の右に「?」を置き、ポインタを合わせる・フォーカスを当てると吹き出しで説明を出す
 * (docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3 の「先頭の列(認可対象)」)。
 */
export function RowName({ name, description }: Props) {
    if (description === null) {
        return <span>{name}</span>;
    }

    return (
        <span className="inline-flex items-center gap-1">
            <span>{name}</span>
            <TipButton kind="help" name={name}>
                {description}
            </TipButton>
        </span>
    );
}
