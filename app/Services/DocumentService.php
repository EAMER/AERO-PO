<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads land on the 'private' disk (see config/filesystems.php), one folder
 * per tenant/PO, and are served only through a streaming route - never a
 * public URL. This is where 7.1.2/7.1.5/7.1.10 documents get attached.
 */
class DocumentService
{
    private const DISK = 'private';
    private const MAX_KB = 20 * 1024; // 20 MB
    private const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'eml', 'msg'];

    public function upload(PurchaseOrder $po, DocumentType $type, UploadedFile $file, User $actor): PoDocument
    {
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new \InvalidArgumentException('File exceeds the 20MB upload limit.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('File type not allowed: '.$file->getClientOriginalExtension());
        }

        $dir = "po-documents/{$po->tenant_id}/{$po->id}";
        $name = Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $name, self::DISK);

        return PoDocument::create([
            'purchase_order_id' => $po->id,
            'type' => $type,
            'disk_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function delete(PoDocument $document): void
    {
        Storage::disk(self::DISK)->delete($document->disk_path);
        $document->delete();
    }

    /** Stream the file to the browser. Caller must authorize the request first. */
    public function download(PoDocument $document)
    {
        return Storage::disk(self::DISK)->download($document->disk_path, $document->original_filename);
    }
}
