<?php

// Replace the whole of routes/web.php with this file.

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DocumentController;
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

            Route::post('/requisitions/{purchaseOrder}/documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::get('/documents/{poDocument}/download', [DocumentController::class, 'download'])->name('documents.download');
            Route::delete('/documents/{poDocument}', [DocumentController::class, 'destroy'])->name('documents.destroy');

            Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
            Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
            Route::post('/approvals/{approval}/query', [ApprovalController::class, 'query'])->name('approvals.query');
            Route::post('/approval-queries/{approvalQuery}/answer', [ApprovalController::class, 'answerQuery'])->name('approvals.queries.answer');
        });

        require __DIR__.'/auth.php';
    });
