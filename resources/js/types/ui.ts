export type Appearance = 'light' | 'dark' | 'system';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};
