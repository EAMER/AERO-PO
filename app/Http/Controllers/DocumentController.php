<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\Role;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function store(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = Auth::user();
        abort_unless($user->role->isAdmin() || $user->role === Role::LogisticsOfficer, 403);

        $data = $request->validate([
            'type' => 'required|string',
            'file' => 'required|file',
        ]);

        $type = DocumentType::tryFrom($data['type']);
        abort_unless($type, 422, 'Unknown document type.');

        try {
            $this->documents->upload($purchaseOrder, $type, $request->file('file'), $user);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'Document uploaded.');
    }

    public function download(PoDocument $poDocument)
    {
        return $this->documents->download($poDocument);
    }

    public function destroy(PoDocument $poDocument)
    {
        $user = Auth::user();
        abort_unless($user->role->isAdmin() || $user->role === Role::LogisticsOfficer, 403);

        $this->documents->delete($poDocument);

        return back()->with('status', 'Document removed.');
    }
}
