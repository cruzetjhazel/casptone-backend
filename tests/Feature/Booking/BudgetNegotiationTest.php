<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Budget-based custom package negotiation (part 2 of the booking/waitlist change).
 * Deliberately separate from the waitlist/offer workflow.
 *
 * Flow under test (all on a PENDING booking, before any payment exists):
 *   client sends a budget  ->  photographer accepts it / counters (price + inclusions) / declines
 *   ->  client accepts or rejects the photographer's terms  ->  only after acceptance the booking
 *   becomes Confirmed (payment pending) at the agreed price.
 *
 * Rules: no automatic discount; the photographer is never forced to accept; the listed price and
 * snapshot are untouched until the client accepts; payment is impossible before acceptance.
 */
class BudgetNegotiationTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private float $fee;

    private static int $refCounter = 3000000000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fee = (float) config('platform.fee', 30.00);

        $this->photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($this->photographer)->approved()->create();
        PhotographerPaymentConfig::factory()->for($this->photographer)->create();
    }

    // -- helpers --------------------------------------------------------------------------

    private function date(): string
    {
        return now()->addDays(10)->format('Y-m-d');
    }

    /** A Pending request for a ₱10,000 package (+ platform fee). */
    private function pendingBooking(array $extra = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'subtotal' => 10000,
            'total_price' => 10000 + $this->fee,
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->addHours(24),
        ], $extra));
    }

    private function sendBudget(Booking $booking, $amount = 7000, ?string $note = 'This is what I can afford.')
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/budget-request", array_filter([
            'budget_amount' => $amount,
            'note' => $note,
        ], fn ($v) => $v !== null));
    }

    private function respond(Booking $booking, array $payload, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->photographer);

        return $this->postJson("/api/photographer/bookings/{$booking->id}/budget-request/respond", $payload);
    }

    private function counter(Booking $booking, $price = 8500, ?array $inclusions = null)
    {
        return $this->respond($booking, [
            'action' => 'counter',
            'proposed_price' => $price,
            'inclusions' => $inclusions ?? [
                ['name' => '6 hours coverage', 'details' => 'Instead of 8 hours'],
                ['name' => '150 edited photos', 'details' => null],
            ],
            'note' => 'Reduced hours to fit your budget.',
        ]);
    }

    private function clientAccept(Booking $booking, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/budget-request/accept");
    }

    private function clientReject(Booking $booking)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/budget-request/reject");
    }

    private function submitPayment(Booking $booking, $amount)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => (string) (self::$refCounter++),
            'payer_name' => 'Test Client',
            'amount' => $amount,
            'payment_date' => now()->format('Y-m-d'),
        ]);
    }

    private function photographerAccepts(Booking $booking)
    {
        Sanctum::actingAs($this->photographer);

        return $this->postJson("/api/photographer/bookings/{$booking->id}/accept");
    }

    private function requestStatus(Booking $booking): ?string
    {
        return DB::table('booking_budget_requests')->where('booking_id', $booking->id)->latest('id')->value('status');
    }

    private function assertTermsUntouched(Booking $booking): void
    {
        $booking = $booking->fresh();

        $this->assertEquals('pending', $booking->status->value);
        $this->assertEquals(10000, (float) $booking->subtotal);
        $this->assertEquals(10000 + $this->fee, (float) $booking->total_price);
    }

    // -- 1. Client expresses a budget -----------------------------------------------------

    public function test_client_can_send_a_budget_on_a_pending_booking_without_changing_the_price(): void
    {
        $booking = $this->pendingBooking();

        $response = $this->sendBudget($booking, 7000)
            ->assertCreated()
            ->assertJsonPath('data.budget_request.status', 'requested');
        $this->assertEquals(7000, $response->json('data.budget_request.budget_amount'));

        $this->assertDatabaseHas('booking_budget_requests', [
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $this->photographer->id,
            'status' => 'requested',
        ]);

        // No automatic discount, and the request alone never makes the booking payable.
        $this->assertTermsUntouched($booking);
        $this->submitPayment($booking, 7000)->assertStatus(422);
        $this->submitPayment($booking, 10000 + $this->fee)->assertStatus(422);

        $this->assertTrue($this->photographer->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.budget_requested'));
    }

    public function test_a_very_low_budget_is_still_just_a_request_the_photographer_may_ignore(): void
    {
        $booking = $this->pendingBooking();

        $this->sendBudget($booking, 1)->assertCreated();

        $this->assertTermsUntouched($booking);
        $this->assertEquals('requested', $this->requestStatus($booking));
    }

    public function test_budget_must_be_a_positive_number_below_the_package_price(): void
    {
        $booking = $this->pendingBooking();

        foreach ([0, -500, 'abc', 10000, 12000] as $bad) {
            $this->sendBudget($booking, $bad)->assertStatus(422);
        }
        $this->sendBudget($booking, null)->assertStatus(422);

        $this->assertDatabaseCount('booking_budget_requests', 0);
    }

    public function test_budget_can_only_be_sent_while_the_booking_is_pending(): void
    {
        $cases = [
            'confirmed' => Booking::factory()->accepted()->create(['photographer_id' => $this->photographer->id, 'subtotal' => 10000, 'total_price' => 10030]),
            'waitlisted' => Booking::factory()->waitlisted()->create(['photographer_id' => $this->photographer->id, 'subtotal' => 10000, 'total_price' => 10030]),
            'expired' => Booking::factory()->create(['photographer_id' => $this->photographer->id, 'subtotal' => 10000, 'total_price' => 10030, 'status' => BookingStatus::Expired]),
            'cancelled' => Booking::factory()->rejected()->create(['photographer_id' => $this->photographer->id, 'subtotal' => 10000, 'total_price' => 10030]),
            'completed' => Booking::factory()->completed()->create(['photographer_id' => $this->photographer->id, 'subtotal' => 10000, 'total_price' => 10030]),
        ];

        foreach ($cases as $label => $booking) {
            $this->sendBudget($booking, 7000)->assertStatus(422);
        }

        $this->assertDatabaseCount('booking_budget_requests', 0);
    }

    public function test_only_one_budget_request_can_be_open_at_a_time(): void
    {
        $booking = $this->pendingBooking();

        $this->sendBudget($booking, 7000)->assertCreated();
        $this->sendBudget($booking, 6000)->assertStatus(422);

        $this->assertEquals(1, DB::table('booking_budget_requests')->where('booking_id', $booking->id)->count());
    }

    public function test_only_the_booking_client_can_send_a_budget(): void
    {
        $booking = $this->pendingBooking();

        auth()->forgetGuards();
        $this->postJson("/api/client/bookings/{$booking->id}/budget-request", ['budget_amount' => 7000])->assertStatus(401);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/client/bookings/{$booking->id}/budget-request", ['budget_amount' => 7000])->assertForbidden();

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/client/bookings/{$booking->id}/budget-request", ['budget_amount' => 7000])->assertForbidden();

        $this->assertDatabaseCount('booking_budget_requests', 0);
    }

    // -- 2. Photographer responds ---------------------------------------------------------

    public function test_photographer_can_accept_the_budget_which_still_needs_the_client_to_confirm(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $response = $this->respond($booking, ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.budget_request.status', 'proposed');
        $this->assertEquals(7000, $response->json('data.budget_request.proposed_price'));

        // Nothing changes on the booking and it is not payable until the client accepts.
        $this->assertTermsUntouched($booking);
        $this->submitPayment($booking, 7000 + $this->fee)->assertStatus(422);

        $this->assertTrue($booking->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.budget_proposed'));
    }

    public function test_photographer_can_counter_with_a_custom_price_and_inclusions(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $response = $this->counter($booking, 8500)->assertOk();

        $response->assertJsonPath('data.budget_request.status', 'proposed');
        $this->assertEquals(8500, $response->json('data.budget_request.proposed_price'));
        $this->assertEquals(8500 + $this->fee, $response->json('data.budget_request.proposed_total'));
        $this->assertCount(2, $response->json('data.budget_request.inclusions'));
        $this->assertEquals('6 hours coverage', $response->json('data.budget_request.inclusions.0.name'));

        // Still the original terms until the client accepts.
        $this->assertTermsUntouched($booking);
        $this->assertNotNull($booking->fresh()->hold_expires_at);
        $this->assertTrue($booking->fresh()->hold_expires_at->isFuture());
    }

    public function test_counter_offer_validation(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $tooMany = array_map(fn ($i) => ['name' => "Item {$i}"], range(1, 16));

        $bad = [
            ['action' => 'counter', 'inclusions' => [['name' => 'x']]],                                   // price missing
            ['action' => 'counter', 'proposed_price' => 0, 'inclusions' => [['name' => 'x']]],            // not positive
            ['action' => 'counter', 'proposed_price' => -1, 'inclusions' => [['name' => 'x']]],
            ['action' => 'counter', 'proposed_price' => 10001, 'inclusions' => [['name' => 'x']]],        // above the listed price
            ['action' => 'counter', 'proposed_price' => 8000, 'inclusions' => $tooMany],                  // more than 15
            ['action' => 'counter', 'proposed_price' => 8000, 'inclusions' => [['details' => 'no name']]],
            ['action' => 'counter', 'proposed_price' => 8000],                                            // inclusions required
            ['action' => 'haggle', 'proposed_price' => 8000],                                             // unknown action
            [],
        ];

        foreach ($bad as $payload) {
            $this->respond($booking, $payload)->assertStatus(422);
        }

        $this->assertEquals('requested', $this->requestStatus($booking));
    }

    public function test_photographer_can_decline_the_budget_and_nothing_is_discounted(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        // A reason is required.
        $this->respond($booking, ['action' => 'decline'])->assertStatus(422);

        $this->respond($booking, ['action' => 'decline', 'reason' => 'That is below my minimum for this coverage.'])
            ->assertOk()
            ->assertJsonPath('data.budget_request.status', 'declined');

        $this->assertTermsUntouched($booking);
        $this->assertTrue($booking->client->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.budget_declined'));

        // The booking itself is still Pending: the photographer may still accept it at the listed price.
        $this->photographerAccepts($booking)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertEquals(10000 + $this->fee, (float) $booking->fresh()->total_price);
    }

    public function test_photographer_cannot_respond_twice_or_without_a_request(): void
    {
        $booking = $this->pendingBooking();

        $this->respond($booking, ['action' => 'accept'])->assertStatus(422); // no request yet

        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();
        $this->counter($booking, 9000)->assertStatus(422);                    // already proposed
        $this->respond($booking, ['action' => 'decline', 'reason' => 'x'])->assertStatus(422);

        $this->assertEquals(8500 + $this->fee, (float) DB::table('booking_budget_requests')
            ->where('booking_id', $booking->id)->value('proposed_total'));
    }

    public function test_only_the_bookings_approved_photographer_can_respond(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $other = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($other)->approved()->create();
        $this->respond($booking, ['action' => 'accept'], $other)->assertForbidden();

        $unapproved = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($unapproved)->pendingReview()->create();
        $booking2 = $this->pendingBooking(['photographer_id' => $unapproved->id]);
        $this->sendBudget($booking2, 7000)->assertCreated();
        $this->respond($booking2, ['action' => 'accept'], $unapproved)->assertForbidden();

        $this->respond($booking, ['action' => 'accept'], $booking->client)->assertForbidden();

        $this->assertEquals('requested', $this->requestStatus($booking));
    }

    // -- 3. Client accepts / rejects ------------------------------------------------------

    public function test_client_accepting_the_terms_confirms_the_booking_at_the_agreed_price_and_opens_payment(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking, 8500)->assertOk();

        $this->clientAccept($booking)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.budget_request.status', 'accepted');

        $booking = $booking->fresh();
        $this->assertEquals(8500, (float) $booking->subtotal);
        $this->assertEquals(8500 + $this->fee, (float) $booking->total_price);   // fee is added once, never discounted
        $this->assertTrue($booking->hold_expires_at->isFuture());                 // payment deadline
        $this->assertNull($booking->slot_offered_at);                             // not a waitlist offer
        $this->assertDatabaseCount('booking_slot_offers', 0);

        $this->assertTrue($this->photographer->fresh()->notifications
            ->contains(fn ($n) => ($n->data['type'] ?? null) === 'booking.budget_accepted'));

        // Payment amounts follow the NEW total, not the old listed price.
        Sanctum::actingAs($booking->client);
        $info = $this->getJson("/api/client/bookings/{$booking->id}/payment-info")
            ->assertOk()
            ->assertJsonPath('data.payment_actions.can_submit_online_payment', true);
        $this->assertEquals(8500 + $this->fee, $info->json('data.amounts.total_price'));
        $this->assertEquals(8500 + $this->fee, $info->json('data.amounts.full_payment_amount'));

        $this->submitPayment($booking, 10000 + $this->fee)->assertStatus(422);
        $this->submitPayment($booking, 8500 + $this->fee)->assertCreated();
    }

    public function test_client_accepting_a_photographer_accepted_budget_uses_the_budget_as_the_price(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->respond($booking, ['action' => 'accept'])->assertOk();

        $this->clientAccept($booking)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals(7000, (float) $booking->fresh()->subtotal);
        $this->assertEquals(7000 + $this->fee, (float) $booking->fresh()->total_price);
    }

    public function test_client_rejecting_the_terms_keeps_the_original_pending_booking(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        $this->clientReject($booking)->assertOk()->assertJsonPath('data.budget_request.status', 'rejected');

        $this->assertTermsUntouched($booking);
        $this->clientAccept($booking)->assertStatus(422);        // cannot change their mind afterwards
        $this->submitPayment($booking, 8500 + $this->fee)->assertStatus(422);

        // The photographer can still accept the original booking at the listed price.
        $this->photographerAccepts($booking)->assertOk();
        $this->assertEquals(10000 + $this->fee, (float) $booking->fresh()->total_price);
    }

    public function test_the_client_can_only_accept_terms_the_photographer_has_proposed(): void
    {
        $booking = $this->pendingBooking();

        $this->clientAccept($booking)->assertStatus(422);                 // no request

        $this->sendBudget($booking, 7000)->assertCreated();
        $this->clientAccept($booking)->assertStatus(422);                 // still only "requested"

        $this->assertTermsUntouched($booking);
    }

    public function test_a_declined_request_cannot_be_accepted(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->respond($booking, ['action' => 'decline', 'reason' => 'Too low'])->assertOk();

        $this->clientAccept($booking)->assertStatus(422);
        $this->assertTermsUntouched($booking);
    }

    public function test_only_the_booking_client_can_accept_or_reject_the_terms(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        $this->clientAccept($booking, User::factory()->create())->assertForbidden();
        $this->clientAccept($booking, $this->photographer)->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/client/bookings/{$booking->id}/budget-request/reject")->assertForbidden();

        $this->assertTermsUntouched($booking);
        $this->assertEquals('proposed', $this->requestStatus($booking));
    }

    // -- 4. Expiry ------------------------------------------------------------------------

    public function test_stale_terms_cannot_be_accepted(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        DB::table('booking_budget_requests')->where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

        $this->clientAccept($booking)->assertStatus(422);
        $this->assertTermsUntouched($booking);
        $this->submitPayment($booking, 8500 + $this->fee)->assertStatus(422);
    }

    public function test_an_ignored_counter_expires_with_the_booking_and_can_never_be_paid(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        $booking->fresh()->update(['hold_expires_at' => now()->subMinute()]);
        DB::table('booking_budget_requests')->where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('expired', $booking->fresh()->status->value);
        $this->assertEquals('expired', $this->requestStatus($booking));
        $this->clientAccept($booking)->assertStatus(422);
        $this->submitPayment($booking, 8500 + $this->fee)->assertStatus(422);
    }

    public function test_a_request_the_photographer_never_answers_expires_with_the_booking(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $booking->fresh()->update(['hold_expires_at' => now()->subMinute()]);
        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertEquals('expired', $booking->fresh()->status->value);
        $this->assertEquals('expired', $this->requestStatus($booking));
        $this->respond($booking, ['action' => 'accept'])->assertStatus(422);
    }

    // -- 5. Interaction with the normal accept / cancel flow ------------------------------

    public function test_accepting_the_booking_at_the_listed_price_supersedes_an_unanswered_request(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();

        $this->photographerAccepts($booking)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals('superseded', $this->requestStatus($booking));
        $this->assertEquals(10000 + $this->fee, (float) $booking->fresh()->total_price);
    }

    public function test_the_booking_cannot_be_accepted_at_the_listed_price_while_terms_await_the_client(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        $this->photographerAccepts($booking)->assertStatus(422);

        $this->assertTermsUntouched($booking);
        $this->assertEquals('proposed', $this->requestStatus($booking));
    }

    public function test_cancelling_or_declining_the_booking_closes_the_open_request(): void
    {
        $cancelled = $this->pendingBooking();
        $this->sendBudget($cancelled, 7000)->assertCreated();
        Sanctum::actingAs($cancelled->client);
        $this->postJson("/api/client/bookings/{$cancelled->id}/request-cancellation", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertEquals('withdrawn', $this->requestStatus($cancelled));

        $declined = $this->pendingBooking();
        $this->sendBudget($declined, 7000)->assertCreated();
        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/bookings/{$declined->id}/reject", ['reason' => 'Unavailable'])->assertOk();
        $this->assertEquals('withdrawn', $this->requestStatus($declined));
    }

    // -- 6. Slot exclusivity still applies after a negotiated acceptance -------------------

    public function test_accepting_terms_for_a_slot_that_was_taken_meanwhile_waitlists_the_booking(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();

        $holder = Booking::factory()->confirmed()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => $this->date(),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        $this->clientAccept($booking)->assertOk()->assertJsonPath('data.status', 'waitlisted');

        $booking = $booking->fresh();
        $this->assertEquals($holder->id, $booking->superseded_by_booking_id);
        $this->assertNull($booking->hold_expires_at);
        $this->assertEquals('accepted', $this->requestStatus($booking));
        $this->assertEquals(8500, (float) $booking->subtotal);   // negotiated terms are kept
        $this->submitPayment($booking, 8500 + $this->fee)->assertStatus(422);
    }

        public function test_a_client_can_send_a_new_request_after_each_decline_but_never_more_than_three(): void
    {
        $booking = $this->pendingBooking();

        foreach ([7000, 6500, 6000] as $amount) {
            $this->sendBudget($booking, $amount)->assertCreated();
            $this->respond($booking, ['action' => 'decline', 'reason' => 'Too low'])->assertOk();
        }

        $this->sendBudget($booking, 5500)->assertStatus(422);

        $this->assertEquals(3, DB::table('booking_budget_requests')->where('booking_id', $booking->id)->count());
        $this->assertTermsUntouched($booking);
    }

    public function test_a_client_can_start_again_after_rejecting_the_terms(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();
        $this->clientReject($booking)->assertOk();

        $this->sendBudget($booking, 7500)
            ->assertCreated()
            ->assertJsonPath('data.budget_request.status', 'requested');

        $this->assertEquals(2, DB::table('booking_budget_requests')->where('booking_id', $booking->id)->count());
        $this->assertTermsUntouched($booking);
    }

    public function test_negotiation_never_touches_the_waitlist_offer_tables_or_fields(): void
    {
        $booking = $this->pendingBooking();
        $this->sendBudget($booking, 7000)->assertCreated();
        $this->counter($booking)->assertOk();
        $this->clientAccept($booking)->assertOk();

        $this->assertDatabaseCount('booking_slot_offers', 0);
        $this->assertNull($booking->fresh()->slot_offered_at);
        $this->assertNull($booking->fresh()->slot_offer_accepted_at);
        $this->assertNull($booking->fresh()->superseded_by_booking_id);
        $this->assertEquals(BookingPaymentStatus::Pending, $booking->fresh()->payment_status);
    }
}