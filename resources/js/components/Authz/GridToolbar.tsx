import { AddColumnDialog } from '@/components/Authz/AddColumnDialog';
import { Input } from '@/components/ui/input';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

type Props = {
    query: string;
    onQueryChange: (query: string) => void;
    /** 「列を追加」を出すか(編集中で addSubject が真のとき) */
    canAddColumn: boolean;
};

/**
 * 表の上の行(左 = 札「設定」「判定」、右端 = 「列を追加」(編集中だけ)と検索)。Tabs の中に置く。
 */
export function GridToolbar({ query, onQueryChange, canAddColumn }: Props) {
    return (
        <div className="flex items-center justify-between gap-4">
            <TabsList
                variant="line"
                className="h-auto max-w-full flex-wrap justify-start"
            >
                <TabsTrigger value="settings" className="flex-none">
                    設定
                </TabsTrigger>
                <TabsTrigger value="judgements" className="flex-none">
                    判定
                </TabsTrigger>
            </TabsList>
            <div className="flex items-center gap-2">
                {canAddColumn && <AddColumnDialog />}
                <Input
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    placeholder="リソース名で検索"
                    aria-label="リソース名で検索"
                    className="max-w-64"
                />
            </div>
        </div>
    );
}
