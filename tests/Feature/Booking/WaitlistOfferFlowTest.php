<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use App\Actions\Booking\OfferReleasedSlotAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Automatic slot offers for waitlisted clients (part 1 of the booking/waitlist change).
 *
 * Rules under test:
 *  - When a booking that HELD a paid slot is cancelled, the slot is offered automatically to the
 *    oldest eligible waitlisted booking whose schedule overlaps (session + buffer).
 *  - An offer is Confirmed + payment pending + slot_offered_at, but the client may only pay after
 *    explicitly accepting it (slot_offer_accepted_at).
 *  - Only ONE offer is outstanding per slot. Decline / lapse / client-cancel moves on to the next
 *    client and never re-offers to someone who declined or let it lapse.
 *  - Acceptance and offering re-check availability under the photographer row lock.
 *
 * Note: PHPUnit runs requests one after another and RefreshDatabase wraps each test in a
 * transaction, so "concurrent" cases here are deterministic back-to-back / stale-model races. They prove
 * the actions re-read state under the lock; real parallel locking is verified against MySQL separately
 * (see the guide, "MySQL concurrency check").
 */
class WaitlistOfferFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private static int $refCounter = 2000000000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($this->photographer)->approved()->create();
        PhotographerPaymentConfig::factory()->for($this->photographer)->create();
    }

    // -- helpers --------------------------------------------------------------------------

    private function date(): string
    {
        return now()->addDays(10)->format('Y-m-d');
    }

    private function accepted(string $start, string $end, array $extra = []): Booking
    {
        return Booking::factory()->accepted()->create(array_merge([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => $start,
            'end_time' => $end,
            'total_price' => 5000,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Pending,
            'hold_expires_at' => now()->addHours(24),
        ], $extra));
    }

    private function submit(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => (string) (self::$refCounter++),
            'payer_name' => 'Test Client',
            'amount' => $booking->total_price,
            'payment_date' => now()->format('Y-m-d'),
        ]);
    }

    private function verify(Booking $booking)
    {
        $payment = Payment::where('booking_id', $booking->id)->latest('id')->firstOrFail();
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/payments/{$payment->id}/verify", ['notes' => 'Received in GCash']);
    }

    private function requestCancellation(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'Change of plans']);
    }

    private function approveCancellation(Booking $booking)
    {
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve");
    }

    private function acceptOffer(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/accept-offer");
    }

    private function declineOffer(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/decline-offer");
    }

    private function manualOffer(Booking $released, Booking $candidate)
    {
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/bookings/{$released->id}/accommodate", [
            'candidate_booking_id' => $candidate->id,
        ]);
    }

    private function lapse(Booking $booking): void
    {
        $booking->fresh()->update(['hold_expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();
    }

    /** A pays and is verified. Waitlisted backups are created OLDEST FIRST in the order given. */
    private function paidWithBackups(array $backupWindows): array
    {
        $a = $this->accepted('09:00', '11:00');

        $backups = [];
        foreach ($backupWindows as $i => [$start, $end]) {
            $backups[] = $this->accepted($start, $end, [
                'created_at' => now()->subHours(10 - $i), // explicit, strictly increasing
            ]);
        }

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        foreach ($backups as $backup) {
            $this->assertEquals('waitlisted', $backup->fresh()->status->value);
        }

        return array_merge([$a->fresh()], array_map(fn ($b) => $b->fresh(), $backups));
    }

    private function assertOffered(Booking $booking): Booking
    {
        $booking = $booking->fresh();

        $this->assertEquals('confirmed', $booking->status->value);
        $this->assertEquals('pending', $booking->payment_status->value);
        $this->assertNotNull($booking->slot_offered_at);
        $this->assertNull($booking->slot_offer_accepted_at);
        $this->assertTrue($booking->hold_expires_at->isFuture());

        return $booking;
    }

    private function assertStillWaiting(Booking $booking): void
    {
        $booking = $booking->fresh();

        $this->assertEquals('waitlisted', $booking->status->value);
        $this->assertNull($booking->slot_offered_at);
    }

    private function outstandingOfferCount(): int
    {
        return Booking::where('photographer_id', $this->photographer->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('slot_offered_at')
            ->whereIn('payment_status', [BookingPaymentStatus::Pending, BookingPaymentStatus::PendingVerification])
            ->count();
    }

    private function paidCount(): int
    {
        return Booking::where('photographer_id', $this->photographer->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
            ->count();
    }

    // -- 1. Automatic offer on release ----------------------------------------------------

    public function test_approved_cancellation_offers_the_slot_to_the_oldest_waitlisted_booking(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);

        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->assertOffered($b);
        $this->assertStillWaiting($c);
        $this->assertEquals(1, $this->outstandingOfferCount());

        $this->assertDatabaseHas('booking_slot_offers', [
            'booking_id' => $b->id,
            'released_booking_id' => $a->id,
            'status' => 'offered',
        ]);
        $this->assertDatabaseMissing('booking_slot_offers', ['booking_id' => $c->id]);

        $this->assertTrue($b->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.accommodated'));
    }

    public function test_photographer_cancelling_a_paid_booking_also_offers_the_slot(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/bookings/{$a->id}/cancel", ['reason' => 'Emergency'])->assertOk();

        $this->assertOffered($b);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'released_booking_id' => $a->id, 'status' => 'offered']);
    }

    public function test_cancelling_an_unpaid_booking_releases_nothing_and_offers_nothing(): void
    {
        // A never paid, so it never held the slot: B was not waitlisted because of it.
        $a = $this->accepted('09:00', '11:00');
        $b = Booking::factory()->waitlisted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:30',
            'end_time' => '11:30',
        ]);

        $this->requestCancellation($a)->assertOk();

        $this->assertEquals('cancelled', $a->fresh()->status->value);
        $this->assertStillWaiting($b);
        $this->assertDatabaseCount('booking_slot_offers', 0);
    }

    public function test_only_overlapping_waitlisted_bookings_are_offered_the_slot(): void
    {
        [$a, $overlapping] = $this->paidWithBackups([['10:00', '12:00']]);

        // Back-to-back (starts exactly when A ends, no buffer) and a different time: neither overlaps.
        $touching = Booking::factory()->waitlisted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '11:00',
            'end_time' => '12:00',
            'created_at' => now()->subDays(5), // OLDER than the overlapping one, must still be skipped
        ]);
        $otherDay = Booking::factory()->waitlisted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => now()->addDays(20)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'created_at' => now()->subDays(5),
        ]);

        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->assertOffered($overlapping);
        $this->assertStillWaiting($touching);
        $this->assertStillWaiting($otherDay);
    }

    public function test_bookings_that_are_not_waitlisted_are_never_offered_the_slot(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);

        $cancelledByClient = Booking::factory()->rejected()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:30',
            'end_time' => '11:30',
            'created_at' => now()->subDays(5),
        ]);
        $expired = Booking::factory()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:30',
            'end_time' => '11:30',
            'status' => BookingStatus::Expired,
            'created_at' => now()->subDays(5),
        ]);

        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->assertOffered($b);
        $this->assertEquals('cancelled', $cancelledByClient->fresh()->status->value);
        $this->assertEquals('expired', $expired->fresh()->status->value);
    }

    public function test_a_pending_cancellation_request_does_not_release_the_slot(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);

        $this->requestCancellation($a)->assertOk();

        $this->assertEquals('confirmed', $a->fresh()->status->value);
        $this->assertStillWaiting($b);
        $this->assertDatabaseCount('booking_slot_offers', 0);
    }

    // -- 2. Accept before paying ----------------------------------------------------------

    public function test_offered_client_cannot_pay_until_they_accept(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $b = $this->assertOffered($b);

        Sanctum::actingAs($b->client);
        $this->getJson("/api/client/bookings/{$b->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_actions.can_submit_online_payment', false)
            ->assertJsonPath('data.slot_offer_accepted_at', null);
        $this->getJson("/api/client/bookings/{$b->id}/payment-info")
            ->assertOk()
            ->assertJsonPath('data.payment_actions.can_submit_online_payment', false);

        $this->submit($b)->assertStatus(422);
        $this->assertEquals(0, Payment::where('booking_id', $b->id)->count());
        $this->assertEquals('pending', $b->fresh()->payment_status->value);
    }

    public function test_accepting_the_offer_unlocks_payment_and_only_one_booking_ends_up_paid(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->acceptOffer($b)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $b = $b->fresh();
        $this->assertNotNull($b->slot_offer_accepted_at);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'accepted']);

        Sanctum::actingAs($b->client);
        $this->getJson("/api/client/bookings/{$b->id}/payment-info")
            ->assertJsonPath('data.payment_actions.can_submit_online_payment', true);

        $this->submit($b)->assertCreated();
        $this->verify($b)->assertOk();

        $this->assertEquals('fully_paid', $b->fresh()->payment_status->value);
        $this->assertEquals(1, $this->paidCount());
    }

    public function test_accepting_an_offer_twice_is_refused(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->acceptOffer($b)->assertOk();
        $firstAcceptedAt = $b->fresh()->slot_offer_accepted_at;

        $this->acceptOffer($b)->assertStatus(422);
        $this->assertEquals($firstAcceptedAt->timestamp, $b->fresh()->slot_offer_accepted_at->timestamp);
    }

    public function test_only_the_offered_client_can_accept_the_offer(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);
        $this->postJson("/api/client/bookings/{$b->id}/accept-offer")->assertForbidden();

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/client/bookings/{$b->id}/accept-offer")->assertForbidden();

        $this->assertNull($b->fresh()->slot_offer_accepted_at);
    }

    public function test_a_booking_without_an_offer_cannot_accept_one(): void
    {
        [, $waitlisted] = $this->paidWithBackups([['09:30', '11:30']]);
        $ordinary = $this->accepted('15:00', '16:00');

        $this->acceptOffer($waitlisted)->assertStatus(422);
        $this->acceptOffer($ordinary)->assertStatus(422);

        $this->assertEquals('waitlisted', $waitlisted->fresh()->status->value);
        $this->assertNull($ordinary->fresh()->slot_offer_accepted_at);
    }

    public function test_an_offer_whose_deadline_has_passed_cannot_be_accepted(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $b->fresh()->update(['hold_expires_at' => now()->subMinute()]);

        $this->acceptOffer($b)->assertStatus(422);
        $this->assertNull($b->fresh()->slot_offer_accepted_at);
        $this->submit($b)->assertStatus(422);
    }

    public function test_accepting_rechecks_availability_and_waitlists_the_booking_if_the_slot_was_taken(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->assertOffered($b);

        // Something else takes the released slot (paid and confirmed) before B accepts.
        $d = Booking::factory()->confirmed()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        $this->acceptOffer($b)->assertStatus(422);

        $b = $b->fresh();
        $this->assertEquals('waitlisted', $b->status->value);
        $this->assertEquals($d->id, $b->superseded_by_booking_id);
        $this->assertNull($b->slot_offered_at);
        $this->assertNull($b->slot_offer_accepted_at);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'withdrawn']);
        $this->assertEquals(1, $this->paidCount());
    }

    public function test_the_status_notice_tells_the_client_to_accept_the_offer(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        Sanctum::actingAs($b->client);
        $notice = $this->getJson("/api/client/bookings/{$b->id}")->json('data.status_notice');

        $this->assertStringContainsString('available again', $notice);
        $this->assertStringContainsStringIgnoringCase('accept', $notice);
    }

    // -- 3. Decline / lapse / cancel cascade ----------------------------------------------

    public function test_declining_an_offer_passes_it_to_the_next_client_and_never_back_to_the_decliner(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->assertOffered($b);

        $this->declineOffer($b)->assertOk();

        $this->assertStillWaiting($b);
        $this->assertOffered($c);
        $this->assertEquals(1, $this->outstandingOfferCount());
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'declined']);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $c->id, 'released_booking_id' => $a->id, 'status' => 'offered']);

        // C also lets it lapse: B declined earlier, so B is NOT re-offered automatically.
        $this->lapse($c);

        $this->assertStillWaiting($b);
        $this->assertStillWaiting($c);
        $this->assertEquals(0, $this->outstandingOfferCount());
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $c->id, 'status' => 'lapsed']);
    }

    public function test_the_photographer_can_still_offer_manually_after_everyone_declined_or_lapsed(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->declineOffer($b)->assertOk();
        $this->lapse($c);

        $this->manualOffer($a, $b)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertOffered($b);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'released_booking_id' => $a->id, 'status' => 'offered']);
    }

    public function test_a_lapsed_offer_is_passed_to_the_next_client(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->assertOffered($b);

        $this->lapse($b);

        $this->assertStillWaiting($b);
        $this->assertOffered($c);
        $this->assertEquals(1, $this->outstandingOfferCount());
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'lapsed']);
        $this->assertTrue($b->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.offer_lapsed'));
    }

    public function test_an_accepted_offer_that_is_not_paid_in_time_also_lapses_and_moves_on(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->acceptOffer($b)->assertOk();
        $this->lapse($b);

        $this->assertStillWaiting($b);
        $this->assertNull($b->fresh()->slot_offer_accepted_at);
        $this->assertOffered($c);
    }

    public function test_an_offer_with_a_payment_under_review_never_lapses(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->acceptOffer($b)->assertOk();
        $this->submit($b->fresh())->assertCreated();

        $b->fresh()->update(['hold_expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('confirmed', $b->fresh()->status->value);
        $this->assertEquals('pending_verification', $b->fresh()->payment_status->value);
        $this->assertStillWaiting($c);
    }

    public function test_when_the_offered_client_cancels_the_slot_moves_to_the_next_client(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->assertOffered($b);

        // Nothing paid yet, so the cancellation is instant.
        $this->requestCancellation($b)->assertOk();

        $this->assertEquals('cancelled', $b->fresh()->status->value);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'withdrawn']);
        $this->assertOffered($c);
        $this->assertEquals(1, $this->outstandingOfferCount());
    }

    public function test_a_waitlisted_booking_that_expires_is_skipped_for_the_next_one(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);

        // B's own event time has passed while it waited; the expiry job closes it before the release.
        $b->update(['event_date' => now()->subDay()->format('Y-m-d')]);
        app(ExpireStaleBookingHoldsAction::class)->execute();
        $this->assertEquals('expired', $b->fresh()->status->value);

        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->assertOffered($c);
    }

    // -- 4. Only one offer / exclusive resource -------------------------------------------

    public function test_a_second_release_pass_never_creates_a_second_offer(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();
        $this->assertOffered($b);

        // Two workers act on the same cancellation, one holding a stale copy of the booking.
        $stale = Booking::find($a->id);
        $action = app(OfferReleasedSlotAction::class);

        $this->assertNull($action->execute($a->fresh()));
        $this->assertNull($action->execute($stale));

        $this->assertEquals(1, $this->outstandingOfferCount());
        $this->assertStillWaiting($c);
        $this->assertDatabaseCount('booking_slot_offers', 1);
    }

    public function test_manual_offer_is_refused_while_another_offer_is_outstanding(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->manualOffer($a, $c)->assertStatus(422);

        $this->assertOffered($b);
        $this->assertStillWaiting($c);
    }

    public function test_two_clients_can_never_both_hold_a_payable_offer_for_the_same_slot(): void
    {
        [$a, $b, $c] = $this->paidWithBackups([['09:30', '11:30'], ['10:00', '12:00']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        // C is waitlisted: it can neither accept an offer it does not have nor pay.
        $this->acceptOffer($c)->assertStatus(422);
        $this->submit($c)->assertStatus(422);

        $this->acceptOffer($b)->assertOk();
        $this->submit($b->fresh())->assertCreated();
        $this->verify($b)->assertOk();

        $this->assertEquals(1, $this->paidCount());
        $this->assertEquals(0, Payment::where('booking_id', $c->id)->count());
        $this->assertEquals($b->id, $c->fresh()->superseded_by_booking_id);
    }

    public function test_a_stale_second_payment_verification_for_the_same_slot_is_refused(): void
    {
        // Two accepted bookings both submitted payments; only the first verification may win.
        $x = $this->accepted('09:00', '11:00');
        $y = $this->accepted('10:00', '12:00');
        $this->submit($x)->assertCreated();
        $this->submit($y)->assertCreated();

        $this->verify($x)->assertOk();
        $this->verify($y)->assertStatus(422);

        $this->assertEquals(1, $this->paidCount());
        $this->assertEquals('waitlisted', $y->fresh()->status->value);
    }

    // -- 5. Already-submitted payment and authorisation -----------------------------------

    public function test_an_offer_to_a_client_with_a_submitted_reference_counts_as_accepted(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('09:30', '11:30');

        $this->submit($b)->assertCreated();
        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $b = $b->fresh();
        $this->assertEquals('confirmed', $b->status->value);
        $this->assertEquals('pending_verification', $b->payment_status->value);
        $this->assertNotNull($b->slot_offered_at);
        $this->assertNotNull($b->slot_offer_accepted_at);
        $this->assertNull($b->hold_expires_at);
        $this->assertEquals(1, Payment::where('booking_id', $b->id)->count());

        $this->verify($b)->assertOk();
        $this->assertEquals(1, $this->paidCount());
    }

    public function test_the_offer_state_is_visible_only_to_the_two_parties_of_the_booking(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/client/bookings/{$b->id}")->assertForbidden();

        $otherPhotographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($otherPhotographer)->approved()->create();
        Sanctum::actingAs($otherPhotographer);
        $this->getJson("/api/photographer/bookings/{$b->id}")->assertForbidden();
    }

    public function test_a_declined_offer_cannot_be_accepted_afterwards(): void
    {
        [$a, $b] = $this->paidWithBackups([['09:30', '11:30']]);
        $this->requestCancellation($a)->assertOk();
        $this->approveCancellation($a)->assertOk();

        $this->declineOffer($b)->assertOk();

        $this->acceptOffer($b)->assertStatus(422);
        $this->assertStillWaiting($b);
    }
}