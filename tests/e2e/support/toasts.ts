import type { Page } from '@playwright/test';

/** 画面のトースト(sonner) */
export const toasts = (page: Page) => page.locator('[data-sonner-toast]');
