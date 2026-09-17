<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customers\CustomerService;
use App\Models\Batch;
use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * The businesses, the people we deal with, and their places in a batch.
 *
 * All three go through CustomerService::createInBatch - the same call the Add
 * Customer screen makes - so the demo exercises the real path rather than a
 * parallel one. Each business therefore arrives active, with a primary contact
 * RecipientResolver can find, and with the enrolment the rest of the domain
 * hangs off. Nothing is enrolled by hand and nothing is activated afterwards.
 *
 * One business is deliberately left with a payment due date a little in the
 * past, so the payment reminder trigger has something to find during a
 * demonstration. It is a reminder, not a gate: that business is as active as
 * every other one.
 */
class DemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $customers = app(CustomerService::class);

        $batches = Batch::query()->pluck('id', 'code');

        foreach (DemoDataset::businesses() as $business) {
            if (Customer::query()->where('code', $business['code'])->exists()) {
                continue;
            }

            $batch = isset($batches[$business['batch']])
                ? Batch::query()->find($batches[$business['batch']])
                : null;

            $customers->createInBatch(
                ['name' => $business['name'], 'code' => $business['code']],
                $batch,
                [
                    'name' => $business['contact']['name'],
                    'email' => $business['contact']['email'],
                    'phone_e164' => $business['contact']['phone'],
                ],
                $actor,
            );
        }

        $this->giveOneBusinessAPaymentReminder();
    }

    /**
     * Trigger 7 reads enrollments.payment_due_date and nothing else. Setting
     * one gives the Notifications screen something real to show; leaving the
     * rest null is what makes the point that participation never depended on
     * it.
     */
    private function giveOneBusinessAPaymentReminder(): void
    {
        $customer = Customer::query()
            ->where('code', DemoDataset::CODE_PREFIX.'PRAKASH')
            ->with('enrollments')
            ->first();

        $enrollment = $customer?->enrollments->first();

        if ($enrollment === null || $enrollment->payment_due_date !== null) {
            return;
        }

        $enrollment->forceFill([
            'payment_due_date' => now()->subDays(4)->toDateString(),
        ])->save();
    }
}
