<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Business\HrPolicyAcknowledgementService;
use App\Domain\Business\HrPolicyService;
use App\Domain\Business\PositionService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Session 4 and 6 business systems: the org chart and the HR policy library.
 *
 * PUBLISHING IS ADMIN-ONLY. Staff hold hr_policies.manage - they may draft and
 * revise - but not hr_policies.publish. The publish control is therefore
 * absent for them, and HrPolicyPolicy refuses it regardless of what this
 * screen renders.
 *
 * A PUBLISHED POLICY IS IMMUTABLE. Its title, body and version label cannot be
 * rewritten; the only ways forward are supersede (a new policy that replaces
 * it) and archive. That is enforced on the model, so it holds for a Super
 * Admin too.
 *
 * ACKNOWLEDGEMENTS ARE APPEND-ONLY and are recorded against a POSITION, not a
 * user: the people acknowledging a policy are the business's own staff, who
 * have no account here.
 */
class BusinessSystems extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $addingPosition = false;

    public string $positionTitle = '';

    public ?int $parentPositionId = null;

    public string $holderName = '';

    public string $kra = '';

    public bool $draftingPolicy = false;

    public string $policyTitle = '';

    public string $policyBody = '';

    public string $policyVersionLabel = '';

    public ?int $acknowledgingPolicyId = null;

    public ?int $acknowledgingPositionId = null;

    public string $acknowledgedName = '';

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startPosition(): void
    {
        $this->authorize('create', Position::class);

        $this->reset(['positionTitle', 'parentPositionId', 'holderName', 'kra']);
        $this->addingPosition = true;
    }

    public function addPosition(PositionService $positions): void
    {
        $this->authorize('create', Position::class);

        $this->validate([
            'positionTitle' => ['required', 'string', 'max:150'],
            'parentPositionId' => ['nullable', 'integer'],
            'holderName' => ['nullable', 'string', 'max:150'],
            'kra' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer = $this->customer();
        $parent = $this->parentPositionId === null ? null : $this->positionInWorkspace((int) $this->parentPositionId);

        $saved = $this->runGuarded(function () use ($positions, $customer, $parent): void {
            $positions->create(
                customer: $customer,
                title: $this->positionTitle,
                parent: $parent,
                holderName: $this->holderName ?: null,
                roleDescription: null,
                kra: $this->kra ?: null,
                actor: auth()->user(),
            );
        }, 'Position added.');

        if ($saved) {
            $this->addingPosition = false;
        }
    }

    public function archivePosition(int $positionId, PositionService $positions): void
    {
        $position = $this->positionInWorkspace($positionId);

        $this->authorize('archive', $position);

        $this->runGuarded(
            fn () => $positions->archive($position, auth()->user()),
            'Position archived.',
        );
    }

    public function startPolicy(): void
    {
        $this->authorize('create', HrPolicy::class);

        $this->reset(['policyTitle', 'policyBody', 'policyVersionLabel']);
        $this->draftingPolicy = true;
    }

    public function draftPolicy(HrPolicyService $policies): void
    {
        $this->authorize('create', HrPolicy::class);

        $this->validate([
            'policyTitle' => ['required', 'string', 'max:200'],
            'policyBody' => ['nullable', 'string', 'max:50000'],
            'policyVersionLabel' => ['nullable', 'string', 'max:30'],
        ]);

        $customer = $this->customer();

        $saved = $this->runGuarded(function () use ($policies, $customer): void {
            $policies->draft(
                customer: $customer,
                title: $this->policyTitle,
                body: $this->policyBody ?: null,
                versionLabel: $this->policyVersionLabel ?: null,
                actor: auth()->user(),
            );
        }, 'Policy drafted.');

        if ($saved) {
            $this->draftingPolicy = false;
        }
    }

    public function publishPolicy(int $policyId, HrPolicyService $policies): void
    {
        $policy = $this->policyInWorkspace($policyId);

        // Asks for hr_policies.publish. Staff never see this control and are
        // refused here even if they reach the endpoint directly.
        $this->authorize('publish', $policy);

        $this->runGuarded(
            fn () => $policies->publish($policy, auth()->user()),
            'Policy published. Its content is now immutable.',
        );
    }

    public function archivePolicy(int $policyId, HrPolicyService $policies): void
    {
        $policy = $this->policyInWorkspace($policyId);

        $this->authorize('archive', $policy);

        $this->runGuarded(
            fn () => $policies->archive($policy, auth()->user()),
            'Policy archived.',
        );
    }

    public function startAcknowledgement(int $policyId): void
    {
        $policy = $this->policyInWorkspace($policyId);

        $this->authorize('create', HrPolicyAcknowledgement::class);

        $this->acknowledgingPolicyId = (int) $policy->getKey();
        $this->acknowledgingPositionId = null;
        $this->acknowledgedName = '';
    }

    public function recordAcknowledgement(HrPolicyAcknowledgementService $acknowledgements): void
    {
        $this->authorize('create', HrPolicyAcknowledgement::class);

        $this->validate([
            'acknowledgingPositionId' => ['required', 'integer'],
            'acknowledgedName' => ['required', 'string', 'max:150'],
        ]);

        $policy = $this->policyInWorkspace((int) $this->acknowledgingPolicyId);
        $position = $this->positionInWorkspace((int) $this->acknowledgingPositionId);

        $saved = $this->runGuarded(function () use ($acknowledgements, $policy, $position): void {
            $acknowledgements->record($policy, $position, $this->acknowledgedName, auth()->user());
        }, 'Acknowledgement recorded.');

        if ($saved) {
            $this->acknowledgingPolicyId = null;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();

        $policies = app(HrPolicyService::class)->libraryFor($customer)->load('acknowledgements.position');

        return view('livewire.customers.business-systems', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'positions' => app(PositionService::class)->chartFor($customer),
            'allPositions' => Position::query()
                ->where('customer_id', $customer->getKey())
                ->whereNull('archived_at')
                ->orderBy('title')
                ->get(),
            'policies' => $policies,
        ])->layout('components.layouts.app', ['title' => $customer->name.' — HR & Systems']);
    }

    private function positionInWorkspace(int $positionId): Position
    {
        $position = Position::query()->findOrFail($positionId);

        $this->assertOwnedByWorkspace((int) $position->customer_id);

        return $position;
    }

    private function policyInWorkspace(int $policyId): HrPolicy
    {
        $policy = HrPolicy::query()->findOrFail($policyId);

        $this->assertOwnedByWorkspace((int) $policy->customer_id);

        return $policy;
    }
}
