import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: { success: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    is_admin: boolean;
    balance_nanos: number;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: string;
    status: GenerationStatus;
    status_detail: 'waiting_for_capacity' | 'retrying' | null;
    is_terminal: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_nanos: number;
    max_nanos: number;
    charged_nanos: number | null;
    usage: Record<string, number> | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error: { code: string; message: string; retryable: boolean } | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

export type TokenKind = 'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens';

export interface Estimate {
    tokens: Record<TokenKind, { expected: number; min: number; max: number }>;
    expected_nanos: number;
    min_nanos: number;
    max_nanos: number;
    balance_nanos: number;
    affordable: boolean;
    markup_percent: number;
    rates: Record<TokenKind, string>;
}

/** Laravel resource collection (paginated). */
export interface ResourcePage<T> {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
}

/** Plain Laravel paginator. */
export interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
}
