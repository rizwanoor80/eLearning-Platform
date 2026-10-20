<?php

use App\Actions\Lessons\ResolveDispute;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Event;

/**
 * 23e (R257): the parent's receipts. Read-only; no VAT line (D-04) and no email receipt yet.
 */
beforeEach(function () {
    $this->parent = User::factory()->create(['timezone' => 'Asia/Dubai']);
});

/** A paid lesson (price 10000) for $this->parent. */
function rcPaid(User $payer, array $payment = [], array $lesson = []): Payment
{
    $lesson = Lesson::factory()->create(['price' => 10000, 'booked_by_user_id' => $payer->id, ...$lesson]);

    return Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $payer->id, 'gateway' => 'stripe', 'gateway_ref' => 'pi_receipt_'.$lesson->id, ...$payment]);
}

it('lists each paid lesson with date, tutor, learner, amount in AED and the Stripe reference', function () {
    $payment = rcPaid($this->parent, ['amount' => 12345]);
    $lesson = $payment->lesson;

    $this->actingAs($this->parent)->get(route('receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('receipts/Index')
            ->has('receipts', 1)
            ->where('receipts.0.id', $payment->id)
            ->where('receipts.0.tutor_display_name', $lesson->tutorProfile->displayName())
            ->where('receipts.0.learner_display_name', $lesson->learner->display_name)
            ->where('receipts.0.amount', 'AED 123.45')
            ->where('receipts.0.reference', $payment->gateway_ref)
            ->where('receipts.0.refunded', null)
            ->where('receipts.0.lesson_on', $lesson->starts_at->setTimezone('Asia/Dubai')->format('j M Y')));
});

it('lists only the caller\'s payments that took money', function () {
    $mine = rcPaid($this->parent);
    rcPaid($this->parent, ['status' => PaymentStatus::Pending]);
    rcPaid($this->parent, ['status' => PaymentStatus::Failed, 'gateway_ref' => null]);
    rcPaid($this->parent, ['gateway_ref' => null]);
    rcPaid(User::factory()->create());

    $this->actingAs($this->parent)->get(route('receipts.index'))
        ->assertInertia(fn ($page) => $page->has('receipts', 1)->where('receipts.0.id', $mine->id));
});

it('includes refunded and partly refunded payments, with the refunded amount', function () {
    rcPaid($this->parent, ['status' => PaymentStatus::Refunded, 'refunded_amount' => 10000]);
    rcPaid($this->parent, ['status' => PaymentStatus::PartiallyRefunded, 'refunded_amount' => 3700]);

    $this->actingAs($this->parent)->get(route('receipts.index'))
        ->assertInertia(fn ($page) => $page->has('receipts', 2)
            ->where('receipts.0.refunded', 'AED 37.00')
            ->where('receipts.1.refunded', 'AED 100.00'));
});

it('paginates the list, newest first', function () {
    foreach (range(1, 22) as $i) {
        rcPaid($this->parent, ['created_at' => now()->subMinutes(30 - $i)]);
    }

    $this->actingAs($this->parent)->get(route('receipts.index'))
        ->assertInertia(fn ($page) => $page->has('receipts', 20)->whereNot('next_page_url', null)->where('previous_page_url', null));

    $this->get(route('receipts.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('receipts', 2)->where('next_page_url', null)->whereNot('previous_page_url', null));
});

it('shows an empty state with no receipts', function () {
    $this->actingAs($this->parent)->get(route('receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('receipts', 0));
});

it('renders one printable receipt per payment with no VAT line', function () {
    $payment = rcPaid($this->parent, ['amount' => 10000]);
    $lesson = $payment->lesson;

    $response = $this->actingAs($this->parent)->get(route('receipts.show', $payment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('receipts/Show')
            ->where('receipt.number', 'R-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
            ->where('receipt.amount', 'AED 100.00')
            ->where('receipt.net', 'AED 100.00')
            ->where('receipt.reference', $payment->gateway_ref)
            ->where('receipt.tutor_display_name', $lesson->tutorProfile->displayName())
            ->where('receipt.learner_display_name', $lesson->learner->display_name)
            ->where('receipt.payer_name', $this->parent->name)
            ->where('receipt.timezone', 'Asia/Dubai')
            ->where('receipt.lesson_at', $lesson->starts_at->setTimezone('Asia/Dubai')->format('D, j M Y, g:i A'))
            ->where('receipt.refunds', [])
            ->missing('receipt.vat'));

    expect(json_encode($response->viewData('page')['props']['receipt']))->not->toMatch('/vat/i')
        ->and(file_get_contents(resource_path('js/pages/receipts/Show.vue')))->not->toMatch('/\bVAT\b/i');
});

it('converts times to the viewer\'s timezone', function () {
    $viewer = User::factory()->create(['timezone' => 'America/New_York']);
    $payment = rcPaid($viewer);

    $this->actingAs($viewer)->get(route('receipts.show', $payment))
        ->assertInertia(fn ($page) => $page
            ->where('receipt.lesson_at', $payment->lesson->starts_at->setTimezone('America/New_York')->format('D, j M Y, g:i A'))
            ->where('receipt.timezone', 'America/New_York'));
});

it('lists refund lines from the ledger, marking the ones the gateway has returned', function () {
    $payment = rcPaid($this->parent, ['status' => PaymentStatus::PartiallyRefunded, 'refunded_amount' => 3700], ['status' => LessonStatus::Disputed]);
    $lesson = $payment->lesson;
    app(LedgerService::class)->hold($lesson);
    $dispute = Dispute::factory()->for($lesson)->create();

    Event::fake([LessonStatusChanged::class]);
    app(ResolveDispute::class)(User::factory()->admin()->create(), $dispute, 37, 63, 'Split.');

    $entry = LedgerEntry::query()->where('lesson_id', $lesson->id)->where('account', LedgerAccount::Refund->value)->sole();

    // Ledger owes the refund but the gateway has not confirmed it yet.
    $this->actingAs($this->parent)->get(route('receipts.show', $payment))
        ->assertInertia(fn ($page) => $page
            ->has('receipt.refunds', 1)
            ->where('receipt.refunds.0.amount', 'AED 37.00')
            ->where('receipt.refunds.0.returned', false)
            ->where('receipt.net', 'AED 63.00'));

    $payment->update(['raw_response' => ['refunds' => [(string) $entry->id => ['id' => 're_1', 'amount_fils' => 3700]]]]);

    $this->get(route('receipts.show', $payment))
        ->assertInertia(fn ($page) => $page->where('receipt.refunds.0.returned', true));
});

it('404s a receipt that is another account\'s or never took money', function () {
    $other = rcPaid(User::factory()->create());
    $pending = rcPaid($this->parent, ['status' => PaymentStatus::Pending]);
    $failed = rcPaid($this->parent, ['status' => PaymentStatus::Failed, 'gateway_ref' => null]);

    $this->actingAs($this->parent);

    $this->get(route('receipts.show', $other))->assertNotFound();
    $this->get(route('receipts.show', $pending))->assertNotFound();
    $this->get(route('receipts.show', $failed))->assertNotFound();
    $this->get('/receipts/999999')->assertNotFound();
});

it('sends a guest to log in and keeps tutors out of the parent area', function () {
    $payment = rcPaid($this->parent);

    $this->get(route('receipts.index'))->assertRedirect(route('login'));
    $this->get(route('receipts.show', $payment))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->tutor()->create())->get(route('receipts.index'))->assertForbidden();
});

it('links to the receipts from the dashboard', function () {
    expect(file_get_contents(resource_path('js/pages/Dashboard.vue')))->toContain('href="/receipts"');
});
