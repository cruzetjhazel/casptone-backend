<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use App\Services\Photographer\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conflicting bookings: accepted bookings that overlap are waitlisted (never cancelled)
 * when another booking's payment is verified, and can be offered the slot again if the
 * paying booking is cancelled.
 *
 * Note: PHPUnit runs requests one after another, so "simultaneous" cases are covered by
 * running the conflicting actions back to back — the second one must be refused. This does
 * not simulate true parallel requests; the database locks are what protect those.
 */
class WaitlistConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($this->photographer)->approved()->create();
        PhotographerPaymentConfig::factory()->for($this->photographer)->create();
    }

    private function date(): string
    {
        return now()->addDays(10)->format('Y-m-d');
    }

    /** An accepted booking: Confirmed, waiting for payment, 24h payment window. */
    private function accepted(string $start = '09:00', string $end = '11:00', array $extra = []): Booking
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

    private static int $refCounter = 1000000000000;

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

    private function paymentOf(Booking $booking): Payment
    {
        return Payment::where('booking_id', $booking->id)->latest('id')->firstOrFail();
    }

    private function verify(Booking $booking)
    {
        $payment = $this->paymentOf($booking);
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/payments/{$payment->id}/verify", ['notes' => 'Received in GCash']);
    }

    /** Client A pays and the photographer verifies it; B (accepted, overlapping) is waitlisted. */
    private function paidWinnerAndWaitlisted(): array
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('10:00', '12:00');

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        return [$a->fresh(), $b->fresh()];
    }

    private function requestCancellation(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'Change of plans']);
    }

    private function decideCancellation(Booking $booking, string $decision)
    {
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/{$decision}");
    }

    private function offer(Booking $cancelled, Booking $candidate)
    {
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/bookings/{$cancelled->id}/accommodate", [
            'candidate_booking_id' => $candidate->id,
        ]);
    }

    /** The client explicitly accepts the schedule that was offered to them (required before paying). */
    private function acceptOffer(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/accept-offer");
    }

    /** The released slot was offered automatically: Confirmed + unpaid, but NOT yet accepted by the client. */
    private function assertAutoOffered(Booking $booking): Booking
    {
        $booking = $booking->fresh();

        $this->assertEquals('confirmed', $booking->status->value);
        $this->assertEquals('pending', $booking->payment_status->value);
        $this->assertNotNull($booking->slot_offered_at);
        $this->assertNull($booking->slot_offer_accepted_at);
        $this->assertTrue($booking->hold_expires_at->isFuture());
        $this->assertNull($booking->superseded_by_booking_id);

        return $booking;
    }

    private function paidOverlapCount(): int
    {
        return Booking::where('photographer_id', $this->photographer->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
            ->count();
    }

    // A ---------------------------------------------------------------------------------

    public function test_verified_payment_waitlists_the_overlapping_booking_instead_of_cancelling_it(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();

        $this->assertEquals('fully_paid', $a->payment_status->value);
        $this->assertEquals('waitlisted', $b->status->value);
        $this->assertEquals($a->id, $b->superseded_by_booking_id);
        $this->assertNull($b->hold_expires_at);
        $this->assertNull($b->cancellation_reason);
        $this->assertEquals(1, $this->paidOverlapCount());
    }

    public function test_non_overlapping_bookings_are_not_waitlisted(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $c = $this->accepted('13:00', '15:00');

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $this->assertEquals('confirmed', $c->fresh()->status->value);
    }

    // L: messages / notifications / what the client sees ------------------------------

    public function test_waitlisted_client_sees_an_accurate_message_and_cannot_pay(): void
    {
        [, $b] = $this->paidWinnerAndWaitlisted();

        Sanctum::actingAs($b->client);
        $this->getJson("/api/client/bookings/{$b->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'waitlisted')
            ->assertJsonPath('data.payment_actions.can_submit_online_payment', false);

        $notice = $this->getJson("/api/client/bookings/{$b->id}")->json('data.status_notice');
        $this->assertStringContainsString('currently holds this schedule', $notice);

        $notified = $b->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.waitlisted');
        $this->assertTrue($notified);
    }

    // B ---------------------------------------------------------------------------------

    public function test_waitlisted_booking_cannot_submit_a_payment(): void
    {
        [, $b] = $this->paidWinnerAndWaitlisted();

        $this->submit($b)->assertStatus(422);

        $this->assertEquals(0, Payment::where('booking_id', $b->id)->count());
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
    }

    public function test_while_cancellation_is_pending_the_slot_is_not_released(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();

        $this->requestCancellation($a)->assertOk();

        $this->assertEquals('confirmed', $a->fresh()->status->value);
        $this->assertNotNull($a->fresh()->cancellation_requested_at);
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
        $this->submit($b)->assertStatus(422);
    }

    // C ---------------------------------------------------------------------------------

    public function test_rejecting_the_cancellation_keeps_the_booking_and_the_waitlist(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'reject')->assertOk();

        $this->assertEquals('confirmed', $a->fresh()->status->value);
        $this->assertEquals('fully_paid', $a->fresh()->payment_status->value);
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
    }

    // D ---------------------------------------------------------------------------------

    public function test_approving_the_cancellation_releases_the_slot_offers_it_and_keeps_the_payment_record(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        $a = $a->fresh();
        $this->assertEquals('cancelled', $a->status->value);

        // The payment record is never deleted and no refund is claimed automatically.
        $payment = $this->paymentOf($a);
        $this->assertEquals('manually_verified', $payment->matching_status->value);
        $this->assertEquals('none', $payment->refund_status->value);

        // CHANGED: the released slot is now offered automatically to the oldest waitlisted client.
        $this->assertAutoOffered($b);
        $this->assertDatabaseHas('booking_slot_offers', [
            'booking_id' => $b->id,
            'released_booking_id' => $a->id,
            'status' => 'offered',
        ]);

        $told = $b->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.accommodated');
        $this->assertTrue($told);

        // Nobody is left waiting, so the photographer's candidate list is empty.
        Sanctum::actingAs($this->photographer);
        $this->assertEquals([], $this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'));
    }

    // E ---------------------------------------------------------------------------------

    public function test_offered_client_must_accept_before_paying_and_only_one_booking_ends_up_paid(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        // CHANGED: offered automatically; payment is NOT possible until the client accepts.
        $b = $this->assertAutoOffered($b);
        $this->assertStringContainsString('available again', $b->statusNotice());

        $this->submit($b)->assertStatus(422);
        $this->assertEquals(0, Payment::where('booking_id', $b->id)->count());

        $this->acceptOffer($b)->assertOk();
        $this->assertNotNull($b->fresh()->slot_offer_accepted_at);

        $this->submit($b->fresh())->assertCreated();
        $this->verify($b)->assertOk();

        $this->assertEquals('fully_paid', $b->fresh()->payment_status->value);
        $this->assertEquals(1, $this->paidOverlapCount());
    }

    // F ---------------------------------------------------------------------------------

    public function test_with_no_backup_the_released_slot_is_open_for_new_requests(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        Sanctum::actingAs($this->photographer);
        $this->assertEquals([], $this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'));

        $slots = app(AvailabilityService::class)->getAvailableStartTimes($this->photographer, $this->date(), 120);
        $this->assertContains('09:00', $slots);
    }

    // G ---------------------------------------------------------------------------------

    public function test_with_several_backups_only_one_is_offered_at_a_time(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('09:30', '11:30');
        $c = $this->accepted('10:00', '12:00');

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $this->assertEquals('waitlisted', $b->fresh()->status->value);
        $this->assertEquals('waitlisted', $c->fresh()->status->value);

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        // CHANGED: the oldest request (B) is offered automatically; C keeps waiting.
        $this->assertAutoOffered($b);
        $this->assertEquals('waitlisted', $c->fresh()->status->value);

        Sanctum::actingAs($this->photographer);
        $ids = collect($this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'))->pluck('id')->all();
        $this->assertEquals([$c->id], $ids);

        // Manual override is still refused while another offer is outstanding.
        $this->offer($a, $c)->assertStatus(422);
        $this->assertEquals('waitlisted', $c->fresh()->status->value);

        // When the offered client accepts and pays, the other backup now waits behind THAT booking.
        $this->acceptOffer($b)->assertOk();
        $this->submit($b->fresh())->assertCreated();
        $this->verify($b)->assertOk();

        $this->assertEquals('waitlisted', $c->fresh()->status->value);
        $this->assertEquals($b->id, $c->fresh()->superseded_by_booking_id);
        $this->assertEquals(1, $this->paidOverlapCount());
    }

    public function test_if_the_offer_lapses_the_next_waitlisted_client_is_offered_the_slot_automatically(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('09:30', '11:30');
        $c = $this->accepted('10:00', '12:00');

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        $this->assertAutoOffered($b);
        $b->fresh()->update(['hold_expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();

        // CHANGED: B returns to the waitlist and C is offered the slot without photographer action.
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
        $this->assertNull($b->fresh()->slot_offered_at);
        $this->assertAutoOffered($c);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'lapsed']);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $c->id, 'status' => 'offered']);
    }

    // H ---------------------------------------------------------------------------------

    public function test_a_waitlisted_booking_that_the_client_cancelled_is_never_offered_the_slot(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();

        $this->requestCancellation($b)->assertOk();
        $this->assertEquals('cancelled', $b->fresh()->status->value);

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        Sanctum::actingAs($this->photographer);
        $this->assertEquals([], $this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'));
        $this->offer($a, $b)->assertStatus(422);
        $this->assertEquals('cancelled', $b->fresh()->status->value);
    }

    public function test_a_waitlisted_booking_expires_when_its_event_date_passes(): void
    {
        $past = Booking::factory()->waitlisted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $past = $past->fresh();
        $this->assertEquals('expired', $past->status->value);
        $this->assertNotNull($past->cancellation_reason);
    }

    public function test_an_already_expired_booking_cannot_be_offered_the_slot(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();
        $b->update(['status' => BookingStatus::Expired]);

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        $this->offer($a, $b)->assertStatus(422);
        $this->assertEquals('expired', $b->fresh()->status->value);
    }

    // I ---------------------------------------------------------------------------------

    public function test_a_second_conflicting_verification_is_refused_and_only_one_booking_is_paid(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('10:00', '12:00');

        $this->submit($a)->assertCreated();
        $this->submit($b)->assertCreated();

        $this->verify($a)->assertOk();
        $this->verify($b)->assertStatus(422);

        $this->assertEquals(1, $this->paidOverlapCount());
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
    }

    public function test_a_cancellation_cannot_be_approved_twice(): void
    {
        [$a] = $this->paidWinnerAndWaitlisted();

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();
        $this->decideCancellation($a, 'approve')->assertStatus(422);
    }

    // J ---------------------------------------------------------------------------------

    public function test_a_payment_reference_submitted_before_losing_the_slot_is_kept(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('10:00', '12:00');

        $this->submit($b)->assertCreated();
        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $b = $b->fresh();
        $this->assertEquals('waitlisted', $b->status->value);
        $this->assertEquals('pending_verification', $b->payment_status->value);

        $payment = $this->paymentOf($b);
        $this->assertEquals('not_matched', $payment->matching_status->value);

        // Verifying it while the slot is held is refused, with the payment left on record.
        $this->verify($b)->assertStatus(422);
        $this->assertEquals('not_matched', $payment->fresh()->matching_status->value);
        $this->assertEquals('waitlisted', $b->fresh()->status->value);
    }

    public function test_an_offered_booking_keeps_its_already_submitted_payment_for_review(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('10:00', '12:00');

        $this->submit($b)->assertCreated();
        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();

        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        // CHANGED: offered automatically. The client already submitted a reference, so the
        // offer counts as accepted and the photographer simply reviews that payment.
        $b = $b->fresh();
        $this->assertEquals('confirmed', $b->status->value);
        $this->assertEquals('pending_verification', $b->payment_status->value);
        $this->assertNotNull($b->slot_offered_at);
        $this->assertNotNull($b->slot_offer_accepted_at);
        $this->assertEquals(1, Payment::where('booking_id', $b->id)->count());

        $this->verify($b)->assertOk();
        $this->assertEquals('fully_paid', $b->fresh()->payment_status->value);
        $this->assertEquals(1, $this->paidOverlapCount());
    }

    // Accepting while the slot is already held -------------------------------------------

    public function test_accepting_a_pending_request_for_a_held_slot_waitlists_it(): void
    {
        [$a] = $this->paidWinnerAndWaitlisted();

        $pending = Booking::factory()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '10:30',
            'end_time' => '12:30',
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->addHours(24),
        ]);

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/bookings/{$pending->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'waitlisted');

        $this->assertEquals($a->id, $pending->fresh()->superseded_by_booking_id);
    }

    // K ---------------------------------------------------------------------------------

    public function test_clients_cannot_use_photographer_endpoints_to_change_a_booking(): void
    {
        [, $b] = $this->paidWinnerAndWaitlisted();

        Sanctum::actingAs($b->client);
        $this->postJson("/api/photographer/bookings/{$b->id}/accept")->assertStatus(403);
        $this->postJson("/api/photographer/bookings/{$b->id}/accommodate", ['candidate_booking_id' => $b->id])->assertStatus(403);

        $this->assertEquals('waitlisted', $b->fresh()->status->value);
    }

    public function test_a_client_cannot_pay_after_the_payment_window_has_passed(): void
    {
        $late = $this->accepted('15:00', '17:00', ['hold_expires_at' => now()->subMinute()]);

        $this->submit($late)->assertStatus(422);

        $this->assertEquals(0, Payment::where('booking_id', $late->id)->count());
    }

    public function test_a_client_cannot_verify_their_own_payment(): void
    {
        $a = $this->accepted();
        $this->submit($a)->assertCreated();
        $payment = $this->paymentOf($a);

        Sanctum::actingAs($a->client);
        $this->postJson("/api/photographer/payments/{$payment->id}/verify", ['notes' => 'x'])->assertStatus(403);

        $this->assertEquals('pending_verification', $a->fresh()->payment_status->value);
    }

    // Authorisation / candidate rules carried over from the old accommodate tests ---------

    public function test_another_photographer_cannot_view_or_use_the_candidates(): void
    {
        [$a, $b] = $this->paidWinnerAndWaitlisted();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        $other = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($other)->approved()->create();

        Sanctum::actingAs($other);
        $this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->assertStatus(403);
        $this->postJson("/api/photographer/bookings/{$a->id}/accommodate", ['candidate_booking_id' => $b->id])->assertStatus(403);
    }

    public function test_an_unrelated_booking_is_not_a_valid_candidate(): void
    {
        [$a] = $this->paidWinnerAndWaitlisted();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        $unrelated = Booking::factory()->waitlisted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => now()->addDays(20)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        $this->offer($a, $unrelated)->assertStatus(422);
    }

    public function test_an_ordinary_declined_booking_is_never_a_candidate(): void
    {
        $a = $this->accepted('09:00', '11:00');
        Booking::factory()->rejected()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:30',
            'end_time' => '11:30',
        ]);

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        Sanctum::actingAs($this->photographer);
        $this->assertEquals([], $this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'));
    }

    // J: declining / lapsing an offer ----------------------------------------------------

    /** A paid, cancelled; B offered the released slot (unpaid). */
    private function offeredBooking(): array
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('09:30', '11:30');

        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();
        // CHANGED: no manual offer step; approving the cancellation offers the slot to B.
        $this->assertAutoOffered($b);

        return [$a->fresh(), $b->fresh()];
    }

    public function test_client_can_decline_an_offer_and_returns_to_the_waitlist(): void
    {
        [$a, $b] = $this->offeredBooking();

        Sanctum::actingAs($b->client);
        $this->postJson("/api/client/bookings/{$b->id}/decline-offer")->assertOk();

        $b = $b->fresh();
        $this->assertEquals('waitlisted', $b->status->value);
        $this->assertNull($b->slot_offered_at);
        $this->assertNull($b->hold_expires_at);
        $this->assertNull($b->slot_offer_accepted_at);
        $this->assertDatabaseHas('booking_slot_offers', ['booking_id' => $b->id, 'status' => 'declined']);
        // No other waitlisted client exists, so nobody else is offered the slot...
        $this->assertEquals(0, Booking::where('photographer_id', $this->photographer->id)
            ->where('status', BookingStatus::Confirmed)->where('id', '!=', $a->id)->count());
        // ...and B is NOT re-offered automatically, but the photographer can still offer it manually.
        $this->assertTrue($a->accommodationCandidates()->where('id', $b->id)->exists());
    }

    public function test_an_offer_cannot_be_declined_after_a_payment_was_submitted(): void
    {
        [$a, $b] = $this->offeredBooking();
        $this->acceptOffer($b)->assertOk();
        $this->submit($b->fresh())->assertCreated();

        Sanctum::actingAs($b->client);
        $this->postJson("/api/client/bookings/{$b->id}/decline-offer")->assertStatus(422);

        $this->assertEquals('confirmed', $b->fresh()->status->value);
    }

    public function test_an_ordinary_accepted_booking_has_no_offer_to_decline(): void
    {
        $c = $this->accepted('14:00', '15:00');

        Sanctum::actingAs($c->client);
        $this->postJson("/api/client/bookings/{$c->id}/decline-offer")->assertStatus(422);
    }

    public function test_another_client_cannot_decline_someone_elses_offer(): void
    {
        [$a, $b] = $this->offeredBooking();

        Sanctum::actingAs($a->client);
        $this->postJson("/api/client/bookings/{$b->id}/decline-offer")->assertForbidden();

        $this->assertEquals('confirmed', $b->fresh()->status->value);
    }

    public function test_an_ordinary_unpaid_acceptance_still_expires_when_its_deadline_passes(): void
    {
        $c = $this->accepted('14:00', '15:00', ['hold_expires_at' => now()->subMinute()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('expired', $c->fresh()->status->value);
    }

    public function test_a_booking_with_a_submitted_payment_is_never_expired_by_the_job(): void
    {
        [$a, $b] = $this->offeredBooking();
        $this->acceptOffer($b)->assertOk();
        $this->submit($b->fresh())->assertCreated();
        $b->fresh()->update(['hold_expires_at' => now()->subMinute()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('confirmed', $b->fresh()->status->value);
        $this->assertEquals('pending_verification', $b->fresh()->payment_status->value);
    }

    public function test_rejecting_the_reference_of_an_offered_booking_starts_a_payment_deadline(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('09:30', '11:30');

        // B submits a reference first, then A pays and takes the slot: B keeps its reference.
        $this->submit($b)->assertCreated();
        $this->submit($a)->assertCreated();
        $this->verify($a)->assertOk();
        $this->requestCancellation($a)->assertOk();
        $this->decideCancellation($a, 'approve')->assertOk();

        // CHANGED: offered automatically; already-submitted reference means the offer is accepted.
        $b = $b->fresh();
        $this->assertNotNull($b->slot_offered_at);
        $this->assertNotNull($b->slot_offer_accepted_at);
        $this->assertEquals('pending_verification', $b->payment_status->value);
        $this->assertNull($b->hold_expires_at); // no deadline while the reference is under review

        // The photographer finds no money received and rejects the reference.
        $payment = $this->paymentOf($b);
        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/payments/{$payment->id}/reject", ['notes' => 'Nothing received.'])->assertOk();

        $b = $b->fresh();
        $this->assertEquals('confirmed', $b->status->value);
        $this->assertEquals('pending', $b->payment_status->value);
        $this->assertNotNull($b->hold_expires_at); // now it can lapse like any other offer

        // ...and when it lapses, it returns to the waitlist instead of blocking the slot forever.
        $b->update(['hold_expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('waitlisted', $b->fresh()->status->value);
        // The rejected payment record is kept.
        $this->assertEquals('rejected', $payment->fresh()->matching_status->value);
    }
}