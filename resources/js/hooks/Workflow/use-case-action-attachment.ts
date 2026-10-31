import { useState } from 'react';
import { filesOperationsOf } from '@/hooks/Workflow/use-apply-attachment';
import { filesToSendOf } from '@/lib/Workflow/applyAttachment';

/**
 * 処理のダイアログの今回追加するファイル(docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節)。使えなくなっても選んだファイルを消さず、
 * 見せる・送るものを今の可否から導く(処理者・操作を替えても、送信の誤りの戻りの後も、選んだものを保つため)。
 */
export function useCaseActionAttachment(
    attachable: boolean,
    onFilesChanged: () => void,
) {
    const [chosen, setChosen] = useState<File[]>([]);
    const operations = filesOperationsOf({
        setFiles: setChosen,
        onFilesChanged,
    });

    return {
        files: filesToSendOf({ enabled: attachable }, chosen),
        addFiles: operations.add,
        removeFile: operations.remove,
    };
}
