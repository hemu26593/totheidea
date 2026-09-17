<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Business\HrPolicyService;
use App\Domain\Business\PositionService;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The HR & Systems workspace: each business's org chart and its policies.
 *
 * The brief that asked for this called the area "Business Systems" and listed
 * things like Enquiry Management and Dispatch Management. There is no table for
 * those - what the application actually has under that tab is `positions` (a
 * reporting structure) and `hr_policies`. So the operating systems are
 * expressed the way the schema can hold them: as the roles that own them, with
 * the system named in the role's key result area. The org chart then reads as
 * what a consultant would have drawn on the whiteboard in session 4.
 *
 * The chart is built parent-first so reparenting never has to happen, and the
 * holder names are the same fictional people used everywhere else.
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $positions = app(PositionService::class);
        $policies = app(HrPolicyService::class);

        $businesses = collect(DemoDataset::businesses())->keyBy('code');

        foreach (Customer::query()->orderBy('id')->get() as $customer) {
            $business = $businesses->get($customer->code);

            if ($business === null) {
                continue;
            }

            if (! Position::query()->where('customer_id', $customer->getKey())->exists()) {
                $this->buildChart($positions, $customer, $business, $actor);
            }

            if (! HrPolicy::query()->where('customer_id', $customer->getKey())->exists()) {
                $this->publishPolicies($policies, $customer, $actor);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $business
     */
    private function buildChart(
        PositionService $positions,
        Customer $customer,
        array $business,
        User $actor,
    ): void {
        $head = $positions->create(
            $customer,
            (string) $business['contact']['role'],
            null,
            (string) $business['contact']['name'],
            'Owns the twelve-month objective and the weekly management review.',
            'Turnover, gross margin and the ninety-day plan.',
            $actor,
        );

        $branches = [
            [
                'title' => 'Sales & Business Development Head',
                'holder' => 'Vacant — to be appointed',
                'role' => 'Owns the enquiry-to-order process end to end.',
                'kra' => 'Enquiry management, quotation management and customer follow-up. '
                    .'Measured on quotations issued, conversion rate and quotation turnaround.',
                'children' => [
                    ['title' => 'Enquiry & Quotation Coordinator', 'holder' => 'Jayesh Solanki', 'kra' => 'Owns the enquiry register and the quotation system. Every enquiry logged the day it arrives.'],
                ],
            ],
            [
                'title' => 'Operations Head',
                'holder' => 'Dhaval Panchal',
                'role' => 'Owns production, quality and despatch.',
                'kra' => 'Production monitoring, inventory control and dispatch management. '
                    .'Measured on output against plan, rejection rate and on-time despatch.',
                'children' => [
                    ['title' => 'Production Supervisor', 'holder' => 'Ramesh Chavda', 'kra' => 'Owns the daily production plan and the board it is published on.'],
                    ['title' => 'Stores & Purchase In-charge', 'holder' => 'Nitin Bhavsar', 'kra' => 'Owns inventory control and purchase management, within the approved limit.'],
                ],
            ],
            [
                'title' => 'Finance & Accounts Head',
                'holder' => 'Falguni Mistry',
                'role' => 'Owns cash, receivables and management reporting.',
                'kra' => 'Management reporting and receivables. Measured on collection period and on the '
                    .'weekly dashboard being published on time.',
                'children' => [],
            ],
            [
                'title' => 'HR & Administration',
                'holder' => 'Sejal Pandya',
                'role' => 'Owns hiring, induction and the policy set.',
                'kra' => 'HR and people management: attendance, induction and the acknowledged policy set.',
                'children' => [],
            ],
        ];

        foreach ($branches as $branch) {
            $parent = $positions->create(
                $customer,
                $branch['title'],
                $head,
                $branch['holder'],
                $branch['role'],
                $branch['kra'],
                $actor,
            );

            foreach ($branch['children'] as $child) {
                $positions->create(
                    $customer,
                    $child['title'],
                    $parent,
                    $child['holder'],
                    null,
                    $child['kra'],
                    $actor,
                );
            }
        }
    }

    private function publishPolicies(HrPolicyService $policies, Customer $customer, User $actor): void
    {
        $set = [
            [
                'title' => 'Attendance & Working Hours',
                'body' => "Working hours are 09:00 to 18:00, Monday to Saturday, with a half day on the second and fourth Saturday.\n\n"
                    .'Attendance is recorded daily. Three unrecorded absences in a month are reviewed with the reporting head.',
            ],
            [
                'title' => 'Purchase Approval & Delegation',
                'body' => "Purchases up to Rs 50,000 are approved by the Stores & Purchase In-charge against an approved supplier.\n\n"
                    ."Above that limit, approval rests with the Operations Head. Capital items of any value go to the owner.\n\n"
                    .'This limit exists so that production is never held up by a signature.',
            ],
            [
                'title' => 'Customer Complaint Handling',
                'body' => "Every complaint is logged on the day it is received, with the customer, the order and the nature of the issue.\n\n"
                    ."An acknowledgement goes out within one working day and a corrective action within five.\n\n"
                    .'Complaints are reviewed at the Monday management review, not when they escalate.',
            ],
        ];

        foreach ($set as $policy) {
            $draft = $policies->draft(
                $customer,
                $policy['title'],
                $policy['body'],
                'v1.0',
                $actor,
            );

            $policies->publish($draft, $actor);
        }
    }
}
