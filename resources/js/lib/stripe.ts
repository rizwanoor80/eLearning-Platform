import type { Stripe } from '@stripe/stripe-js';
import { loadStripe } from '@stripe/stripe-js/pure';

// `/pure` does not touch the network on import: Stripe.js is fetched from Stripe the first time a page
// asks for it here, so no other page loads it and nothing is fetched at build time (R248).
export function stripeFor(publishableKey: string): Promise<Stripe | null> {
    return loadStripe(publishableKey);
}

export function xsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

export type JsonResult<T> = { ok: boolean; body: T & { message?: string } };

export async function postJson<T>(
    url: string,
    payload: Record<string, unknown>,
): Promise<JsonResult<T>> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    return {
        ok: response.ok,
        body: (await response.json().catch(() => ({}))) as T & {
            message?: string;
        },
    };
}
