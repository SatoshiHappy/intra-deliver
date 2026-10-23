import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';

/** 検索の語の文字数の上限(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節) */
const MAX_LENGTH = 100;

/** 欄の名前と、空のときに出す文 */
const LABEL = '申請番号・申請名で検索';

/**
 * 申請一覧の検索の欄(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節: タブの下)。描くだけで、語と待ちは呼び手が持つ
 * (useCaseSearch)。onSubmit = Enter。
 */
export function CaseSearchField({
    value,
    onChange,
    onSubmit,
}: {
    value: string;
    onChange: (next: string) => void;
    onSubmit: () => void;
}) {
    return (
        <form
            role="search"
            className="relative max-w-sm"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
        >
            <Search
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                type="search"
                aria-label={LABEL}
                placeholder={LABEL}
                maxLength={MAX_LENGTH}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="pl-9"
            />
        </form>
    );
}
