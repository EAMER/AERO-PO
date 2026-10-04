<?php

// Replace the whole of routes/web.php with this file.

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::domain('{tenant}.'.config('tenancy.domain'))
    ->middleware('tenant')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('login'));

        Route::middleware(['auth', 'tenant.user'])->group(function () {
            Route::get('/dashboard', fn () => redirect()->route('requisitions.index'))->name('dashboard');

            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

            Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
            Route::get('/requisitions/create', [RequisitionController::class, 'create'])->name('requisitions.create');
            Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
            Route::get('/requisitions/{purchaseOrder}', [RequisitionController::class, 'show'])->name('requisitions.show');
            Route::post('/requisitions/{purchaseOrder}/check-stock', [RequisitionController::class, 'checkStock'])->name('requisitions.check-stock');
            Route::post('/requisitions/{purchaseOrder}/mark-rfq-sent', [RequisitionController::class, 'markRfqSent'])->name('requisitions.mark-rfq-sent');
            Route::post('/requisitions/{purchaseOrder}/mark-quotes-in', [RequisitionController::class, 'markQuotesIn'])->name('requisitions.mark-quotes-in');
            Route::post('/requisitions/{purchaseOrder}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');

            Route::post('/requisitions/{purchaseOrder}/payment-terms', [RequisitionController::class, 'setPaymentTerms'])->name('requisitions.payment-terms');
            Route::post('/requisitions/{purchaseOrder}/acknowledge', [RequisitionController::class, 'markAcknowledged'])->name('requisitions.acknowledge');
            Route::post('/requisitions/{purchaseOrder}/proceed-to-payment', [RequisitionController::class, 'proceedToPayment'])->name('requisitions.proceed-to-payment');
            Route::post('/requisitions/{purchaseOrder}/confirm-payment', [RequisitionController::class, 'confirmPayment'])->name('requisitions.confirm-payment');
            Route::post('/requisitions/{purchaseOrder}/ready-to-ship', [RequisitionController::class, 'markReadyToShipOnNetTerms'])->name('requisitions.ready-to-ship');

            Route::post('/requisitions/{purchaseOrder}/documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::get('/documents/{poDocument}/download', [DocumentController::class, 'download'])->name('documents.download');
            Route::delete('/documents/{poDocument}', [DocumentController::class, 'destroy'])->name('documents.destroy');

            Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
            Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
            Route::post('/approvals/{approval}/query', [ApprovalController::class, 'query'])->name('approvals.query');
            Route::post('/approval-queries/{approvalQuery}/answer', [ApprovalController::class, 'answerQuery'])->name('approvals.queries.answer');

            Route::post('/requisitions/{purchaseOrder}/shipment-details', [ShippingController::class, 'recordDetails'])->name('shipping.record-details');
            Route::post('/requisitions/{purchaseOrder}/customs-duty-paid', [ShippingController::class, 'markCustomsDutyPaid'])->name('shipping.customs-duty-paid');
            Route::post('/requisitions/{purchaseOrder}/dispatch', [ShippingController::class, 'dispatch'])->name('shipping.dispatch');
            Route::post('/requisitions/{purchaseOrder}/mark-delivered', [ShippingController::class, 'markDelivered'])->name('shipping.mark-delivered');
            Route::post('/requisitions/{purchaseOrder}/confirm-receipt', [ShippingController::class, 'confirmReceipt'])->name('shipping.confirm-receipt');
            Route::post('/requisitions/{purchaseOrder}/issue-grn', [ShippingController::class, 'issueGrn'])->name('shipping.issue-grn');
            Route::post('/requisitions/{purchaseOrder}/serviceable-label', [ShippingController::class, 'markServiceableLabelApplied'])->name('shipping.serviceable-label');
            Route::post('/requisitions/{purchaseOrder}/cardex-sent', [ShippingController::class, 'markCardexSent'])->name('shipping.cardex-sent');
            Route::post('/requisitions/{purchaseOrder}/close', [ShippingController::class, 'closeOut'])->name('shipping.close');
            Route::post('/requisitions/{purchaseOrder}/close-from-stock', [ShippingController::class, 'closeFulfilledFromStock'])->name('shipping.close-from-stock');
        });

        require __DIR__.'/auth.php';
    });
