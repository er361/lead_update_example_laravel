<?php

use Illuminate\Routing\Router;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsApproveBatchController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsApproveController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsBatchFilePreloadController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsBatchFileUploadController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsRejectBatchController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsRejectController;
use Modules\Offers\Api\Controllers\Leads\Actions\LeadsUpdateController;
use Modules\Offers\Api\Controllers\Leads\LeadsController;
use Modules\Offers\Api\Controllers\Leads\LeadsXlsController;

Route::group([
    'prefix'     => 'api/leads',
    'middleware' => ['api'],
    'as'         => 'api.leads.',
], function (Router $router) {

    $router
        ->post('postback', [LeadsController::class, 'postback'])
        // ->post('postback', fn() => response()->json(['success' => true])->setStatusCode(200))
        // ->post('postback', fn() => response()->json(['success' => false])->setStatusCode(503))
        ->name('postback')
        ->middleware('auth.tracker');

    $router->group([
        'middleware' => ['auth.jwt'],
    ], function (Router $router) {

        $router
            ->get('grid', [LeadsController::class, 'grid'])
            ->name('grid');

        Route::put('{lead}/update', LeadsUpdateController::class)->name('update-lead');

        $router
            ->get('{lead}', [LeadsController::class, 'show'])
            ->name('show');

        $router
            ->get('{lead}/history', [LeadsController::class, 'history'])
            ->name('history');

        // Lead actions
        $router
            ->post('{lead}/approve', LeadsApproveController::class)
            ->name('approve');

        $router
            ->post('{lead}/reject', LeadsRejectController::class)
            ->name('reject');

        $router
            ->post('approve-batch', LeadsApproveBatchController::class)
            ->name('approve-batch');

        $router
            ->post('reject-batch', LeadsRejectBatchController::class)
            ->name('reject-batch');

        $router
            ->post('batch/preload', LeadsBatchFilePreloadController::class)
            ->name('batch.preload');

        $router
            ->post('batch/upload', LeadsBatchFileUploadController::class)
            ->name('batch.upload');

        $router
            ->post('xls', LeadsXlsController::class)
            ->name('xls');
    });
});
