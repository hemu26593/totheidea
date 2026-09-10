<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Attachments\DocumentService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Files attached to a business.
 *
 * STORED ON A PRIVATE DISK, ALWAYS. A document holds a business's own
 * material, so it must never land somewhere reachable by URL. Downloads go
 * through this component, which re-authorizes and re-checks ownership on every
 * request rather than handing out a path.
 *
 * is_internal DEFAULTS TO TRUE. The failure mode of forgetting the flag is an
 * over-restricted file, never a leaked one.
 */
class Documents extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;
    use WithFileUploads;

    public bool $uploading = false;

    public $file;

    public bool $internal = true;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startUpload(): void
    {
        $this->authorize('create', Document::class);

        $this->reset(['file']);
        $this->internal = true;
        $this->uploading = true;
    }

    public function upload(DocumentService $documents): void
    {
        $this->authorize('create', Document::class);

        $this->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $customer = $this->customer();

        // The private disk. Never 'public'.
        $path = $this->file->store('documents/'.$customer->getKey(), 'local');

        $saved = $this->runGuarded(function () use ($documents, $customer, $path): void {
            $documents->attachByStaff(
                $customer,
                [
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $this->file->getClientOriginalName(),
                    'mime_type' => $this->file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => (int) Storage::disk('local')->size($path),
                    'checksum_sha256' => hash_file('sha256', Storage::disk('local')->path($path)) ?: null,
                ],
                auth()->user(),
                $this->internal,
            );
        }, 'Document uploaded.');

        if ($saved) {
            $this->uploading = false;
            $this->reset('file');
        }
    }

    /**
     * Stream a document back.
     *
     * The id is re-read, its ownership re-verified against this workspace, and
     * the policy re-consulted - so a document id from another business is a
     * 404 rather than a download.
     */
    public function download(int $documentId)
    {
        $document = $this->documentInWorkspace($documentId);

        $this->authorize('view', $document);

        if (! Storage::disk($document->disk)->exists($document->path)) {
            $this->addError('domain', 'The stored file is missing from the disk.');

            return null;
        }

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function archive(int $documentId, DocumentService $documents): void
    {
        $document = $this->documentInWorkspace($documentId);

        $this->authorize('archive', $document);

        $this->runGuarded(
            fn () => $documents->archive($document, auth()->user()),
            'Document archived.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.documents', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'documents' => app(DocumentService::class)->forSubject($customer, $customer),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Documents']);
    }

    /**
     * A document is polymorphic, so ownership is resolved through its subject
     * rather than a column. Only documents attached to THIS customer record
     * are reachable here.
     */
    private function documentInWorkspace(int $documentId): Document
    {
        $document = Document::query()->findOrFail($documentId);

        if ($document->documentable_type !== (new Customer)->getMorphClass()) {
            abort(404);
        }

        $this->assertOwnedByWorkspace((int) $document->documentable_id);

        return $document;
    }
}
