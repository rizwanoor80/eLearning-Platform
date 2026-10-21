// 24d (R262): what the booking page remembers across a redirect to the bank (3-D Secure) and back.
// Stripe sends the browser back to `return_url` with the intent's id, its client secret and a status in the
// query string; the form's own choices (learner, subject) are lost with the page, so the page keeps them in
// sessionStorage under the intent's id just before it confirms. Nothing here is a secret or personal data,
// and every storage call is wrapped: a browser that blocks storage simply falls back to asking again.

export type PendingChoice = { learner: number; subject: string };

export type StripeReturn = {
    paymentIntentId: string;
    clientSecret: string;
    redirectStatus: string | null;
};

const PARAMS = [
    'payment_intent',
    'payment_intent_client_secret',
    'redirect_status',
] as const;

const storageKey = (paymentIntentId: string) =>
    `booking-choice:${paymentIntentId}`;

/** The return Stripe appended to the URL, or null when this is an ordinary visit. */
export function readStripeReturn(search: string): StripeReturn | null {
    const query = new URLSearchParams(search);
    const paymentIntentId = query.get('payment_intent');
    const clientSecret = query.get('payment_intent_client_secret');

    if (
        !paymentIntentId ||
        !/^pi_[A-Za-z0-9_]+$/.test(paymentIntentId) ||
        !clientSecret
    ) {
        return null;
    }

    return {
        paymentIntentId,
        clientSecret,
        redirectStatus: query.get('redirect_status'),
    };
}

/** The same URL without Stripe's parameters, so a reload does not replay the return. */
export function withoutStripeReturn(url: URL): string {
    const clean = new URL(url.toString());

    PARAMS.forEach((name) => clean.searchParams.delete(name));

    return `${clean.pathname}${clean.search}${clean.hash}`;
}

export function rememberChoice(
    paymentIntentId: string,
    choice: PendingChoice,
): void {
    try {
        window.sessionStorage.setItem(
            storageKey(paymentIntentId),
            JSON.stringify(choice),
        );
    } catch {
        // Storage blocked: the return will ask for the choice again.
    }
}

export function recallChoice(paymentIntentId: string): PendingChoice | null {
    try {
        const raw = window.sessionStorage.getItem(storageKey(paymentIntentId));
        const parsed = raw === null ? null : JSON.parse(raw);

        if (
            parsed !== null &&
            typeof parsed === 'object' &&
            Number.isInteger(parsed.learner) &&
            typeof parsed.subject === 'string'
        ) {
            return { learner: parsed.learner, subject: parsed.subject };
        }
    } catch {
        // Unreadable or blocked: treated as nothing remembered.
    }

    return null;
}

export function forgetChoice(paymentIntentId: string): void {
    try {
        window.sessionStorage.removeItem(storageKey(paymentIntentId));
    } catch {
        // Nothing to clean up.
    }
}
