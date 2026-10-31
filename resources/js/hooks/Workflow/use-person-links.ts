import { createContext, useContext } from 'react';
import { NO_PERSON_LINKS } from '@/lib/Workflow/personLinks';
import type { WorkflowPersonLinks } from '@/types';

/**
 * 名前の札の「…」のリンク(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1)。札を出すページが props の links で包む
 * (ダイアログの奥の札まで渡すため)。包まない画面は既定 = どちらも出さない。
 */
export const PersonLinksContext =
    createContext<WorkflowPersonLinks>(NO_PERSON_LINKS);

/**
 * 今のページの名前の札の「…」のリンク。
 */
export function usePersonLinks(): WorkflowPersonLinks {
    return useContext(PersonLinksContext);
}
