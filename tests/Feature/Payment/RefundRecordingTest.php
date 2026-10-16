<?php

namespace Tests\Feature\Payment;

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
 * Refunds are never automatic. Approving a cancellation leaves the payment and its refund
 * fields alone; an administrator RECORDS the refund decision afterwards
 * (POST /api/admin/payments/{payment}/refund). 'partial' / 'full' mean the refund was actually
 * issued (outside the platform); 'pending' means a decision is still open; 'denied' means no refund.
 */
class RefundRecordingTest extends TestCase
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

    private function admin(): User
    {
        $factory = User::factory();

        foreach (['administrator', 'admin'] as $state) {
            if (method_exists($factory, $state)) {
                return $factory->{$state}()->create();
            }
        }

        $this->fail('Could not find an administrator state on UserFactory (tried administrator(), admin()).');
    }

    private function accepted(string $start = '09:00', string $end = '11:00'): Booking
    {
        return Booking::factory()->accepted()->create([
            'photographer_id' => $this->photographer->id,
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => $start,
            'end_time' => $end,
            'total_price' => 5000,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Pending,
            'hold_expires_at' => now()->addHours(24),
        ]);
    }

    private static int $ref = 3000000000000;

    /** A pays and is verified; then A asks to cancel and the photographer approves. */
    private function paidThenCancelled(Booking $a): Payment
    {
        Sanctum::actingAs($a->client);
        $paymentId = $this->postJson("/api/client/bookings/{$a->id}/payments", [
            'plan' => 'full', 'reference_number' => (string) (self::$ref++), 'payer_name' => 'Test Client',
            'amount' => $a->total_price, 'payment_date' => now()->format('Y-m-d'),
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        Sanctum::actingAs($a->client);
        $this->postJson("/api/client/bookings/{$a->id}/request-cancellation", ['reason' => 'Change of plans'])->assertOk();

        Sanctum::actingAs($this->photographer);
        $this->postJson("/api/photographer/bookings/{$a->id}/cancellation/approve")->assertOk();

        return Payment::findOrFail($paymentId);
    }

    private function record(Payment $payment, array $body, ?User $actor = null)
    {
        Sanctum::actingAs($actor ?? $this->admin());

        return $this->postJson("/api/admin/payments/{$payment->id}/refund", $body);
    }

    public function test_approving_a_cancellation_never_changes_the_payment_or_marks_a_refund(): void
    {
        $a = $this->accepted();
        $payment = $this->paidThenCancelled($a);

        $payment = $payment->fresh();
        $this->assertEquals('cancelled', $a->fresh()->status->value);
        $this->assertEquals('manually_verified', $payment->matching_status->value);
        $this->assertEquals('none', $payment->refund_status->value);
        $this->assertNull($payment->refund_amount);
        $this->assertNull($payment->refunded_at);
        $this->assertEquals(1, Payment::where('booking_id', $a->id)->count());
    }

    public function test_admin_can_record_a_full_refund_and_the_payment_history_is_kept(): void
    {
        $a = $this->accepted();
        $payment = $this->paidThenCancelled($a);
        $admin = $this->admin();

        $this->record($payment, ['refund_status' => 'full', 'refund_amount' => 5000, 'refund_notes' => 'Sent back via GCash'], $admin)
            ->assertOk();

        $payment = $payment->fresh();
        $this->assertEquals('full', $payment->refund_status->value);
        $this->assertEquals('5000.00', (string) $payment->refund_amount);
        $this->assertEquals($admin->id, $payment->refunded_by);
        $this->assertNotNull($payment->refunded_at);
        // History is untouched: the original verified payment is still there.
        $this->assertEquals('manually_verified', $payment->matching_status->value);
        $this->assertEquals(1, Payment::where('booking_id', $a->id)->count());
        $this->assertEquals('cancelled', $a->fresh()->status->value);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $a->client_id,
            'type' => \App\Notifications\Payment\RefundRecordedNotification::class,
        ]);
    }

    public function test_a_pending_refund_decision_is_not_marked_as_issued(): void
    {
        $payment = $this->paidThenCancelled($this->accepted());

        $this->record($payment, ['refund_status' => 'pending', 'refund_notes' => 'Reviewing'])->assertOk();

        $payment = $payment->fresh();
        $this->assertEquals('pending', $payment->refund_status->value);
        $this->assertNull($payment->refund_amount);
        $this->assertNull($payment->refunded_at);
    }

    public function test_a_refund_moves_from_pending_to_issued_only_when_the_admin_records_it(): void
    {
        $payment = $this->paidThenCancelled($this->accepted());
        $admin = $this->admin();

        $this->record($payment, ['refund_status' => 'pending'], $admin)->assertOk();
        $this->assertNull($payment->fresh()->refunded_at);

        $this->record($payment, ['refund_status' => 'partial', 'refund_amount' => 2000], $admin)->assertOk();

        $payment = $payment->fresh();
        $this->assertEquals('partial', $payment->refund_status->value);
        $this->assertEquals('2000.00', (string) $payment->refund_amount);
        $this->assertNotNull($payment->refunded_at);
    }

    public function test_a_denied_refund_is_recorded_without_an_amount(): void
    {
        $payment = $this->paidThenCancelled($this->accepted());

        $this->record($payment, ['refund_status' => 'denied', 'refund_notes' => 'Past the cancellation window'])->assertOk();

        $payment = $payment->fresh();
        $this->assertEquals('denied', $payment->refund_status->value);
        $this->assertNull($payment->refund_amount);
        $this->assertNull($payment->refunded_at);
    }

    public function test_an_issued_refund_needs_an_amount_and_cannot_exceed_what_was_paid(): void
    {
        $payment = $this->paidThenCancelled($this->accepted());

        $this->record($payment, ['refund_status' => 'full'])->assertStatus(422);
        $this->record($payment, ['refund_status' => 'partial', 'refund_amount' => 5000.01])->assertStatus(422);

        $this->assertEquals('none', $payment->fresh()->refund_status->value);
    }

    public function test_only_an_administrator_can_record_a_refund(): void
    {
        $a = $this->accepted();
        $payment = $this->paidThenCancelled($a);

        $this->record($payment, ['refund_status' => 'full', 'refund_amount' => 5000], $this->photographer)->assertForbidden();
        $this->record($payment, ['refund_status' => 'full', 'refund_amount' => 5000], $a->client)->assertForbidden();

        $this->assertEquals('none', $payment->fresh()->refund_status->value);
    }

    public function test_recording_a_refund_does_not_change_or_repeat_the_slot_offer(): void
    {
        $a = $this->accepted('09:00', '11:00');
        $b = $this->accepted('10:00', '12:00'); // accepted too; waitlisted once A is verified
        $payment = $this->paidThenCancelled($a);

        // Cancelling A (it held a paid slot) already offered the released slot to B automatically.
        $offered = $b->fresh();
        $this->assertEquals('confirmed', $offered->status->value);
        $this->assertNotNull($offered->slot_offered_at);
        $this->assertDatabaseCount('booking_slot_offers', 1);
        $offeredAt = $offered->slot_offered_at->timestamp;

        $this->record($payment, ['refund_status' => 'full', 'refund_amount' => 5000])->assertOk();

        // Recording the refund is only a record: it must not release, re-offer or change the offer.
        $this->assertDatabaseCount('booking_slot_offers', 1);
        $b = $b->fresh();
        $this->assertEquals('confirmed', $b->status->value);
        $this->assertNotNull($b->slot_offered_at);
$this->assertNull($b->slot_offer_accepted_at);
$this->assertEquals($offeredAt, $b->slot_offered_at->timestamp);

        Sanctum::actingAs($this->photographer);
        $ids = collect($this->getJson("/api/photographer/bookings/{$a->id}/accommodation-candidates")->json('data'))->pluck('id')->all();
        $this->assertEquals([], $ids); // B already holds the offer, so nobody is left waiting
    }
}