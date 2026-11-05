<?php

namespace App\Services;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\DigimaintHandover;
use App\Models\FreightQuote;
use App\Models\Invoice;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use App\Support\RequiredDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentService
{
    private const TYPES = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'eml' => ['message/rfc822', 'text/plain'], 'msg' => ['application/vnd.ms-outlook', 'application/x-ole-storage', 'application/CDFV2'],
    ];

    public function upload(PurchaseOrder $po, D $type, UploadedFile $file, User $actor): PoDocument
    {
        Access::tenant($po);
        $roles = match ($type) {
            D::DigimaintEvidence, D::DeliveryNote => [Role::StoreStaff, Role::StoreSupervisor],
            D::ProformaInvoice, D::CommercialInvoice => [Role::LogisticsOfficer, Role::Payables],
            D::TelexCopy => [Role::Treasury],
            default => [Role::LogisticsOfficer],
        };
        Access::roles($actor, ...$roles);
        if ($file->getSize() > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('File exceeds the 20MB upload limit.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        if (! isset(self::TYPES[$extension]) || ! in_array($mime, self::TYPES[$extension], true)) {
            throw new InvalidArgumentException('File content must match an allowed document type.');
        }

        return DB::transaction(function () use ($po, $type, $file, $actor, $extension, $mime) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status->isTerminal()) {
                throw new InvalidArgumentException('Documents on a completed order are locked.');
            }
            $evaluationTypes = array_keys(RequiredDocuments::forStage('evaluation_pack'));
            if (in_array($type->value, $evaluationTypes, true) && ! in_array($po->status, [S::RequisitionRaised, S::StockChecked, S::RfqSent, S::QuotesIn, S::ReturnedEvaluation, S::ReturnedCfo], true)) {
                throw new InvalidArgumentException('Return the evaluation for correction before changing its document pack.');
            }
            if ($type === D::PurchaseOrderDocument) {
                throw new InvalidArgumentException('The purchase-order PDF is generated from the approved commercial record.');
            }
            $path = $file->storeAs('po-documents/'.$po->tenant_id.'/'.$po->id, Str::uuid().'.'.$extension, 'private');
            if (! $path) {
                throw new \DomainException('The uploaded document could not be saved.');
            }
            try {
                $document = PoDocument::create([
                    'purchase_order_id' => $po->id, 'type' => $type, 'disk_path' => $path,
                    'original_filename' => $file->getClientOriginalName(), 'mime_type' => $mime, 'size' => $file->getSize(), 'uploaded_by' => $actor->id,
                ]);
                if ($po->shipment && in_array($type, [D::AirwayBill, D::CommercialInvoice, D::CustomsDutyReceipt, D::ShopReport, D::FreightQuotation], true)) {
                    ShipmentDocument::create(['shipment_id' => $po->shipment->id, 'po_document_id' => $document->id]);
                }
                Access::audit($po, $actor, 'document.uploaded', [], ['document_id' => $document->id, 'type' => $type->value]);

                return $document;
            } catch (\Throwable $e) {
                Storage::disk('private')->delete($path);
                throw $e;
            }
        });
    }

    public function delete(PoDocument $document, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        if (! $actor) {
            throw new AuthorizationException;
        }
        Access::roles($actor, Role::LogisticsOfficer);
        Access::tenant($document);
        DB::transaction(function () use ($document, $actor) {
            $po = PurchaseOrder::whereKey($document->purchase_order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, [S::RequisitionRaised, S::StockChecked, S::RfqSent, S::QuotesIn], true)
                || Quote::where('po_document_id', $document->id)->exists()
                || Invoice::where('po_document_id', $document->id)->exists()
                || FreightQuote::where('po_document_id', $document->id)->exists()
                || DigimaintHandover::where('po_document_id', $document->id)->exists()
                || $po->evaluations()->where('status', '!=', 'draft')->exists()) {
                throw new InvalidArgumentException('This document is part of a submitted or referenced record. Keep it and upload a revised document during correction.');
            }
            $path = $document->disk_path;
            $document->delete();
            Access::audit($po, $actor, 'document.deleted', ['document_id' => $document->id]);
            DB::afterCommit(fn () => Storage::disk('private')->delete($path));
        });
    }

    public function download(PoDocument $document): StreamedResponse
    {
        Access::tenant($document);

        return Storage::disk('private')->download($document->disk_path, $document->original_filename);
    }
}
