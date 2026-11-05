<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('view', $purchaseOrder);
        $data = $request->validate(['type' => ['required', Rule::enum(DocumentType::class)], 'file' => 'required|file|max:20480']);

        return $this->complete(fn () => $this->documents->upload($purchaseOrder, DocumentType::from($data['type']), $request->file('file'), $request->user()));
    }

    public function download(PoDocument $poDocument): StreamedResponse
    {
        $this->authorize('view', $poDocument->purchaseOrder);

        return $this->documents->download($poDocument);
    }

    public function destroy(PoDocument $poDocument, Request $request): RedirectResponse
    {
        $this->authorize('source', $poDocument->purchaseOrder);

        return $this->complete(fn () => $this->documents->delete($poDocument, $request->user()));
    }
}
