<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BookingExtensionStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Enums\PackageStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\RefundStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\BookingExtension;
use App\Models\BookingSchedule;
use App\Models\ClientProfile;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. One booking for every state the system can be in, all on
 * the "Golden Hour Weddings" photographer from CatalogShowcaseSeeder, so a
 * single login shows every status badge, payment status, refund state,
 * dispute state and schedule shape.
 *
 * Every booking's special_requests starts with "STATE:" and names the state,
 * so you can tell what you are looking at. Re-running deletes and recreates
 * them (idempotent).
 *
 *   Photographer login: wedding.showcase@example.test / password
 *   Client login:       showcase.client@example.test   / password
 *   Second client:      showcase.client2@example.test  / password
 *
 *   php artisan db:seed --class=BookingStatesShowcaseSeeder
 */
class BookingStatesShowcaseSeeder extends Seeder
{
    private User $photographer;

    private User $client;

    private User $client2;

    private ?User $admin;

    /** @var array<string, Package> */
    private array $packages = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('BookingStatesShowcaseSeeder only runs in local/testing.');

            return;
        }

        if (! User::where('email', CatalogShowcaseSeeder::WEDDING_EMAIL)->exists()) {
            $this->call(CatalogShowcaseSeeder::class);
        }

        $this->photographer = User::where('email', CatalogShowcaseSeeder::WEDDING_EMAIL)->firstOrFail();
        $this->admin = User::where('account_type', AccountType::Administrator)->first();
        $this->client = $this->clientUser(env('TEST_CLIENT_EMAIL', 'client@example.test'), 'Test Client');
        $this->client2 = $this->clientUser('showcase.client2@example.test', 'Showcase Client Two');

        foreach (Package::where('user_id', $this->photographer->id)->where('status', PackageStatus::Published)->get() as $p) {
            $this->packages[$p->name] = $p;
        }

        $this->cleanup();

        $this->requests();
        $this->acceptedAndPayments();
        $this->serviceTracker();
        $this->cancellationsAndRefunds();
        $this->reschedulesAndModifications();
        $this->nonCompletion();
        $this->scheduleShapes();
        $this->extensions();

        $count = Booking::where('photographer_id', $this->photographer->id)
            ->where('special_requests', 'like', 'STATE:%')->count();
        $this->command?->info("Seeded {$count} state bookings for {$this->photographer->email}.");
    }

    // ------------------------------------------------------------------
    // Groups
    // ------------------------------------------------------------------

    private function requests(): void
    {
        // Photographer's decision window: min(now + 24h, first session start).
        $this->book('Pending request (timed)', 'Prenup Session', 20, ['hold_expires_at' => now()->addHours(20)]);
        $this->book('Pending request (open-ended, no end time)', 'Wedding Day Coverage', 45, ['hold_expires_at' => now()->addHours(20)]);
        $this->book('Pending request (event tomorrow, short decision window)', 'Engagement Mini Session', 1, [
            'start_time' => '15:00',
            'hold_expires_at' => min(now()->addHours(24), Carbon::parse(now()->addDay()->toDateString().' 15:00')),
        ]);
        $this->book('Expired: photographer never responded', 'Civil Wedding', 12, [
            'status' => BookingStatus::Expired,
            'hold_expires_at' => now()->subHours(6),
        ]);
    }

    private function acceptedAndPayments(): void
    {
        // Accepted: status Confirmed, payment still pending, hold_expires_at = client's payment deadline.
        $this->book('Accepted, awaiting payment (24h window)', 'Prenup Session', 25, [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => now()->addHours(18),
        ]);
        $this->book('Accepted, awaiting payment (cutoff before the event)', 'Engagement Mini Session', 2, [
            'start_time' => '13:00',
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => min(now()->addHours(24), Carbon::parse(now()->addDays(2)->toDateString().' 13:00')->subHours(3)),
        ]);
        $this->book('Expired: client did not pay in time', 'Civil Wedding', 10, [
            'status' => BookingStatus::Expired,
            'hold_expires_at' => now()->subHours(3),
        ]);

        $b = $this->book('Payment submitted, awaiting verification', 'Wedding Day Coverage', 30, [
            'status' => BookingStatus::Confirmed,
            'payment_plan' => PaymentPlan::Full,
            'payment_status' => BookingPaymentStatus::PendingVerification,
            'hold_expires_at' => null,
        ]);
        $this->payment($b, PaymentPlan::Full, (float) $b->total_price, ['matching_status' => PaymentMatchingStatus::PendingMatch, 'photographer_payment_reference_id' => null]);

        $b = $this->book('Payment rejected (client must resubmit)', 'Prenup Session', 26, [
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Pending,
            'hold_expires_at' => now()->addHours(10),
        ]);
        $this->payment($b, PaymentPlan::Full, (float) $b->total_price, [
            'matching_status' => PaymentMatchingStatus::Rejected,
            'photographer_payment_reference_id' => null,
            'verified_by' => $this->admin?->id,
            'verified_at' => now(),
            'verification_action' => 'rejected',
            'verification_notes' => 'Reference number does not match any received GCash payment.',
        ]);

        $b = $this->book('Payment not matched (needs manual review)', 'Civil Wedding', 33, [
            'status' => BookingStatus::Confirmed,
            'payment_plan' => PaymentPlan::Half,
            'payment_status' => BookingPaymentStatus::PendingVerification,
            'hold_expires_at' => null,
        ]);
        $this->payment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2), ['matching_status' => PaymentMatchingStatus::NotMatched, 'photographer_payment_reference_id' => null]);

        $b = $this->book('Half paid online (balance due)', 'Debut Celebration', 35, $this->paid(PaymentPlan::Half, BookingPaymentStatus::PartiallyPaid));
        $this->matchedPayment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2));

        $b = $this->book('Fully paid online', 'Wedding Day Coverage', 50, $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
        $paid = $b;

        $b = $this->book('Fully paid (half online + balance paid onsite)', 'Civil Wedding', 40, $this->paid(PaymentPlan::Half, BookingPaymentStatus::FullyPaid));
        $half = round((float) $b->total_price / 2, 2);
        $this->matchedPayment($b, PaymentPlan::Half, $half);
        $this->payment($b, PaymentPlan::Half, round((float) $b->total_price - $half, 2), [
            'type' => \App\Enums\PaymentType::Onsite, 'method' => 'cash', 'reference_number' => null,
            'photographer_payment_reference_id' => null,
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $this->admin?->id, 'verified_at' => now(), 'verification_action' => 'verified',
            'notes' => 'Remaining balance collected on-site.',
        ]);

        // Two clients wanted the same slot; the one who paid won (see SlotConflictService).
        $this->book('Auto-declined: another client paid for this slot', 'Wedding Day Coverage', 50, [
            'status' => BookingStatus::Cancelled,
            'rejection_reason' => 'This slot was taken by another confirmed booking.',
            'hold_expires_at' => null,
            'superseded_by_booking_id' => $paid->id,
        ], $this->client2);

        $this->book('Accommodated again (reopened after the other client cancelled)', 'Wedding Day Coverage', 70, [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => now()->addHours(20),
        ], $this->client2);
    }

    private function serviceTracker(): void
    {
        foreach ([
            ['Service tracker: Upcoming', 14, ServiceTrackerStatus::Upcoming],
            ['Service tracker: Event day', 0, ServiceTrackerStatus::EventDay],
            ['Service tracker: Editing', -3, ServiceTrackerStatus::Editing],
            ['Service tracker: Delivered (awaiting completion)', -8, ServiceTrackerStatus::Delivered],
        ] as [$label, $days, $stage]) {
            $b = $this->book($label, 'Civil Wedding', $days, array_merge($this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), [
                'service_status' => $stage,
                'service_status_updated_at' => now(),
            ]));
            $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
        }

        $b = $this->book('Completed', 'Prenup Session', -20, array_merge($this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), [
            'status' => BookingStatus::Completed,
            'service_status' => ServiceTrackerStatus::Delivered,
            'service_status_updated_at' => now()->subDays(12),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
    }

    private function cancellationsAndRefunds(): void
    {
        $b = $this->book('Rejected by photographer', 'Prenup Session', 15, [
            'status' => BookingStatus::Cancelled,
            'rejection_reason' => 'Not available on the requested date.',
            'hold_expires_at' => null,
        ]);

        $b = $this->book('Cancellation requested (waiting for photographer)', 'Debut Celebration', 28, array_merge(
            $this->paid(PaymentPlan::Half, BookingPaymentStatus::PartiallyPaid),
            ['cancellation_reason' => 'Family emergency.', 'cancellation_requested_at' => now()->subDay()]
        ));
        $this->matchedPayment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2));

        $b = $this->book('Cancellation denied by photographer (booking stays)', 'Civil Wedding', 32, array_merge(
            $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid),
            [
                'cancellation_reason' => 'Changed my mind.',
                'cancellation_requested_at' => now()->subDays(2),
                'cancellation_decision' => CancellationDecision::Rejected,
                'cancellation_decided_at' => now()->subDay(),
            ]
        ));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        foreach ([
            ['Cancelled, full refund issued', RefundStatus::Full, 1.0],
            ['Cancelled, partial refund issued', RefundStatus::Partial, 0.5],
            ['Cancelled, refund pending', RefundStatus::Pending, null],
            ['Cancelled, refund denied', RefundStatus::Denied, 0.0],
        ] as $i => [$label, $refund, $ratio]) {
            $b = $this->book($label, 'Prenup Session', 22 + $i, [
                'status' => BookingStatus::Cancelled,
                'hold_expires_at' => null,
                'payment_plan' => PaymentPlan::Full,
                'payment_status' => BookingPaymentStatus::Cancelled,
                'cancellation_reason' => 'Client found another provider.',
                'cancellation_requested_at' => now()->subDays(4),
                'cancellation_decision' => CancellationDecision::Approved,
                'cancellation_decided_at' => now()->subDays(3),
            ]);
            $p = $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
            $p->update([
                'refund_status' => $refund,
                'refund_amount' => $ratio === null ? null : round((float) $b->total_price * $ratio, 2),
                'refund_notes' => match ($refund) {
                    RefundStatus::Full => 'Refunded in full via GCash.',
                    RefundStatus::Partial => 'Half refunded per cancellation policy.',
                    RefundStatus::Pending => 'Awaiting photographer confirmation.',
                    default => 'Cancelled inside the no-refund window.',
                },
                'refunded_by' => in_array($refund, [RefundStatus::Full, RefundStatus::Partial, RefundStatus::Denied], true) ? $this->admin?->id : null,
                'refunded_at' => $refund === RefundStatus::Pending ? null : now()->subDays(2),
            ]);
        }
    }

    private function reschedulesAndModifications(): void
    {
        $base = fn () => $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid);

        $b = $this->book('Reschedule requested (waiting for photographer)', 'Civil Wedding', 36, array_merge($base(), [
            'requested_event_date' => now()->addDays(60)->toDateString(),
            'requested_start_time' => '14:00',
            'reschedule_requested_at' => now()->subHours(6),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('Reschedule approved', 'Civil Wedding', 62, array_merge($base(), [
            'requested_event_date' => now()->addDays(62)->toDateString(),
            'requested_start_time' => '09:00',
            'reschedule_requested_at' => now()->subDays(3),
            'reschedule_decision' => CancellationDecision::Approved,
            'reschedule_decided_at' => now()->subDays(2),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('Reschedule declined', 'Civil Wedding', 38, array_merge($base(), [
            'requested_event_date' => now()->addDays(9)->toDateString(),
            'requested_start_time' => '09:00',
            'reschedule_requested_at' => now()->subDays(3),
            'reschedule_decision' => CancellationDecision::Rejected,
            'reschedule_decided_at' => now()->subDays(2),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('Modification requested (venue)', 'Debut Celebration', 44, array_merge($base(), [
            'modification_type' => 'venue',
            'modification_reason' => 'The reception venue changed.',
            'modification_requested_at' => now()->subDay(),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
    }

    private function nonCompletion(): void
    {
        $missed = fn () => array_merge($this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), [
            'status' => BookingStatus::Cancelled,
            'service_status' => ServiceTrackerStatus::Upcoming,
            'cancellation_reason' => 'Reported as a no-show.',
        ]);

        $b = $this->book('No-show: photographer (reported by client, dispute window open)', 'Civil Wedding', -2, array_merge($missed(), [
            'non_completion_reason' => \App\Enums\BookingNonCompletionReason::PhotographerNoShow->value,
            'non_completion_reported_at' => now()->subHours(5),
            'non_completion_reported_by' => $this->client->id,
            'non_completion_dispute_deadline_at' => now()->addHours(43),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('No-show: client (reported by photographer, window lapsed)', 'Civil Wedding', -6, array_merge($missed(), [
            'non_completion_reason' => \App\Enums\BookingNonCompletionReason::ClientNoShow->value,
            'non_completion_reported_at' => now()->subDays(4),
            'non_completion_reported_by' => $this->photographer->id,
            'non_completion_dispute_deadline_at' => now()->subDays(2),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('No-show disputed (waiting for admin)', 'Civil Wedding', -5, array_merge($missed(), [
            'non_completion_reason' => \App\Enums\BookingNonCompletionReason::ClientNoShow->value,
            'non_completion_reported_at' => now()->subDays(3),
            'non_completion_reported_by' => $this->photographer->id,
            'non_completion_dispute_deadline_at' => now()->subDay(),
            'non_completion_disputed_at' => now()->subDays(2),
            'non_completion_dispute_reason' => 'I was at the venue on time. There was a mix-up on the address.',
            'non_completion_review_status' => 'pending_admin',
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        $b = $this->book('No-show dispute: upheld by admin', 'Civil Wedding', -9, array_merge($missed(), [
            'non_completion_reason' => \App\Enums\BookingNonCompletionReason::ClientNoShow->value,
            'non_completion_reported_at' => now()->subDays(7),
            'non_completion_reported_by' => $this->photographer->id,
            'non_completion_dispute_deadline_at' => now()->subDays(5),
            'non_completion_disputed_at' => now()->subDays(6),
            'non_completion_dispute_reason' => 'I could not attend.',
            'non_completion_review_status' => 'upheld',
            'non_completion_admin_notes' => 'Photographer messages show arrival and attempts to reach the client.',
            'non_completion_resolved_at' => now()->subDays(4),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

        // Overturned: the report was wrong, so the booking is Confirmed again.
        $b = $this->book('No-show dispute: overturned by admin (booking restored)', 'Civil Wedding', -10, array_merge($this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), [
            'service_status' => ServiceTrackerStatus::Upcoming,
            'non_completion_reported_at' => now()->subDays(8),
            'non_completion_reported_by' => $this->client->id,
            'non_completion_dispute_deadline_at' => now()->subDays(6),
            'non_completion_disputed_at' => now()->subDays(7),
            'non_completion_dispute_reason' => 'I was there and have photos as proof.',
            'non_completion_review_status' => 'overturned',
            'non_completion_admin_notes' => 'Time-stamped photos confirm the photographer attended.',
            'non_completion_resolved_at' => now()->subDays(5),
        ]));
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
    }

    private function scheduleShapes(): void
    {
        // Multi-schedule booking on an open-ended multi-day package: no end times.
        $b = $this->book('Multi-day booking (3 schedules, no end times)', 'Grand Wedding (Multi-Day)', 80, $this->paid(PaymentPlan::Half, BookingPaymentStatus::PartiallyPaid));
        $this->matchedPayment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2));
        foreach ([
            [1, 'Ceremony', 81, '10:00'],
            [2, 'Reception', 81, '18:00'],
        ] as [$order, $label, $offset, $start]) {
            BookingSchedule::create([
                'booking_id' => $b->id,
                'label' => $label,
                'event_date' => now()->addDays($offset)->toDateString(),
                'start_time' => $start,
                'end_time' => null,
                'duration_minutes' => null,
                'location_type' => 'studio',
                'sort_order' => $order,
            ]);
        }

        // Custom (hourly) multi-session booking with per-session hours.
        $b = $this->customBooking('Custom hourly, 2 sessions', 90, 3, $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), 3 * 2200 + 2 * 2200);
        $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);
        BookingSchedule::create([
            'booking_id' => $b->id, 'label' => 'Second session',
            'event_date' => now()->addDays(91)->toDateString(),
            'start_time' => '09:00', 'end_time' => '11:00', 'duration_minutes' => 120,
            'location_type' => 'studio', 'sort_order' => 1,
        ]);
    }

    private function extensions(): void
    {
        // isEligibleForExtensionRequest() needs is_custom_package + custom_hours.
        foreach ([
            ['Extension requested (pending)', 100, BookingExtensionStatus::Pending, null, null],
            ['Extension approved', 105, BookingExtensionStatus::Approved, now()->subDay(), null],
            ['Extension declined', 110, BookingExtensionStatus::Declined, now()->subDay(), 'I have another shoot right after.'],
        ] as [$label, $days, $status, $decidedAt, $decline]) {
            $b = $this->customBooking($label, $days, 4, $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid), 4 * 2200);
            $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price);

            BookingExtension::create([
                'booking_id' => $b->id,
                'requested_hours' => 2,
                'hourly_rate' => 2200,
                'additional_charge' => 4400,
                'status' => $status,
                'requested_at' => now()->subDays(2),
                'decided_at' => $decidedAt,
                'decline_reason' => $decline,
                'new_end_time' => $status === BookingExtensionStatus::Approved ? '15:00' : null,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function clientUser(string $email, string $name): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw(['email' => $email, 'name' => $name, 'password' => bcrypt('password')])
        );
        ClientProfile::firstOrCreate(['user_id' => $user->id], ClientProfile::factory()->raw(['user_id' => $user->id]));

        return $user;
    }

    /** Wipe a previous run so re-seeding never duplicates. */
    private function cleanup(): void
    {
        $ids = Booking::where('photographer_id', $this->photographer->id)
            ->where('special_requests', 'like', 'STATE:%')->pluck('id');

        Booking::whereIn('id', $ids)->update(['superseded_by_booking_id' => null]);
        Booking::whereIn('id', $ids)->delete();
    }

    private function paid(PaymentPlan $plan, BookingPaymentStatus $status): array
    {
        return [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => null,
            'payment_plan' => $plan,
            'payment_status' => $status,
            'service_status' => ServiceTrackerStatus::Upcoming,
            'service_status_updated_at' => now(),
        ];
    }

    private function book(string $label, string $packageName, int $daysOut, array $over = [], ?User $client = null): Booking
    {
        $package = $this->packages[$packageName]
            ?? throw new \RuntimeException("Package '{$packageName}' not found. Run CatalogShowcaseSeeder first.");

        $open = $package->schedule_mode === \App\Enums\PackageScheduleMode::Open;
        $start = $over['start_time'] ?? '09:00';
        $minutes = $open ? null : $package->duration_minutes;
        $end = $minutes ? Carbon::parse($start)->addMinutes($minutes)->format('H:i') : null;

        $eventType = str_contains($packageName, 'Debut') ? 'debut' : 'wedding';

        return Booking::factory()->create(array_merge([
            'client_id' => ($client ?? $this->client)->id,
            'photographer_id' => $this->photographer->id,
            'package_id' => $package->id,
            'is_custom_package' => false,
            'package_snapshot' => [
                'name' => $package->name,
                'price' => (float) $package->price,
                'duration_minutes' => $package->duration_minutes,
                'schedule_mode' => $open ? 'open' : 'timed',
                'buffer_minutes' => $package->buffer_minutes,
            ],
            'add_ons_snapshot' => [],
            'event_type' => $eventType,
            'event_date' => now()->addDays($daysOut)->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $minutes,
            'subtotal' => $package->price,
            'total_price' => $package->price,
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->addHours(20),
            'special_requests' => 'STATE: '.$label,
        ], $over));
    }

    private function customBooking(string $label, int $daysOut, int $hours, array $over, float $total): Booking
    {
        $b = Booking::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'package_id' => null,
            'is_custom_package' => true,
            'package_snapshot' => null,
            'custom_package_snapshot' => [
                'pricing_model' => 'hourly',
                'hourly_rate' => '2200.00',
                'hours' => $hours,
                'duration_minutes' => $hours * 60,
                'buffer_minutes' => 30,
                'allows_multiple_sessions' => true,
                'max_sessions' => 4,
                'components' => [],
            ],
            'add_ons_snapshot' => [],
            'event_type' => 'wedding',
            'event_date' => now()->addDays($daysOut)->toDateString(),
            'start_time' => '09:00',
            'end_time' => Carbon::parse('09:00')->addHours($hours)->format('H:i'),
            'duration_minutes' => $hours * 60,
            'subtotal' => $total,
            'total_price' => $total,
            'special_requests' => 'STATE: '.$label,
        ], $over));

        // custom_hours is not in Booking::$fillable (see the note in the seeder docs).
        $b->forceFill(['custom_hours' => $hours])->save();

        return $b;
    }

    private function matchedPayment(Booking $b, PaymentPlan $plan, float $amount): Payment
    {
        $ref = PhotographerPaymentReference::factory()->used()->create([
            'photographer_id' => $b->photographer_id,
            'amount_received' => $amount,
            'payment_date' => now()->toDateString(),
        ]);

        return $this->payment($b, $plan, $amount, [
            'reference_number' => $ref->reference_number,
            'photographer_payment_reference_id' => $ref->id,
            'matching_status' => PaymentMatchingStatus::Matched,
            'verified_by' => $this->admin?->id,
            'verified_at' => now(),
            'verification_action' => 'verified',
        ]);
    }

    private function payment(Booking $b, PaymentPlan $plan, float $amount, array $over = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'booking_id' => $b->id,
            'client_id' => $b->client_id,
            'photographer_id' => $b->photographer_id,
            'plan' => $plan,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ], $over));
    }
}