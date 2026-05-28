<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArtifactShowController;
use App\Http\Controllers\Api\CatalogItemIndexController;
use App\Http\Controllers\Api\CatalogVersionShowController;
use App\Http\Controllers\Api\CourseIndexController;
use App\Http\Controllers\Api\DeploymentCloudInitStoreController;
use App\Http\Controllers\Api\DeploymentIndexController;
use App\Http\Controllers\Api\DeploymentOperationStoreController;
use App\Http\Controllers\Api\DeploymentShowController;
use App\Http\Controllers\Api\DeploymentStoreController;
use App\Http\Controllers\Api\FloatingIpReleaseController;
use App\Http\Controllers\Api\FloatingIpStoreController;
use App\Http\Controllers\Api\HostKeyPhoneHomeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NetworkOfferingStoreController;
use App\Http\Controllers\Api\NetworkStoreController;
use App\Http\Controllers\Api\ProjectIndexController;
use App\Http\Controllers\Api\ProjectSshKeyIndexController;
use App\Http\Controllers\Api\ProjectSshKeyStoreController;
use App\Http\Controllers\Api\ProjectStackIndexController;
use App\Http\Controllers\Api\ProjectStackStoreController;
use App\Http\Controllers\Api\ProviderDriftAdoptController;
use App\Http\Controllers\Api\ProviderDriftRepairController;
use App\Http\Controllers\Api\ReplayController;
use App\Http\Controllers\Api\RouterStoreController;
use App\Http\Controllers\Api\ScriptApprovalStoreController;
use App\Http\Controllers\Api\ScriptRunShowController;
use App\Http\Controllers\Api\ScriptRunStoreController;
use App\Http\Controllers\Api\ScriptStoreController;
use App\Http\Controllers\Api\ScriptUpdateController;
use App\Http\Controllers\Api\SecurityGroupStoreController;
use App\Http\Controllers\Api\SecurityGroupUpdateController;
use App\Http\Controllers\Api\TokenIndexController;
use App\Http\Controllers\Api\TokenRevokeController;
use App\Http\Controllers\Api\TokenStoreController;
use App\Http\Middleware\AuthenticateTrackAJwt;
use App\Http\Middleware\BindAuthenticatedTenant;
use App\Http\Middleware\NormalizeTrackBTokenHeader;
use App\Http\Middleware\RecordTrackBTokenUse;
use App\Http\Middleware\RejectBearerSanctumToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/provisioning/host-keys/{token}', HostKeyPhoneHomeController::class)->name('api.v1.provisioning.host-keys.store');
});

Route::prefix('v1')
    ->middleware([
        NormalizeTrackBTokenHeader::class,
        AuthenticateTrackAJwt::class,
        'auth:sanctum',
        RejectBearerSanctumToken::class,
        BindAuthenticatedTenant::class,
        RecordTrackBTokenUse::class,
    ])
    ->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::get('/artifacts/{artifact}', ArtifactShowController::class)->name('api.v1.artifacts.show');
        Route::get('/catalog/items', CatalogItemIndexController::class)->name('api.v1.catalog.items.index');
        Route::get('/catalog/items/{catalogItem}/versions/{version}', CatalogVersionShowController::class)->name('api.v1.catalog.versions.show');
        Route::get('/courses', CourseIndexController::class)->name('api.v1.courses.index');
        Route::post('/network-offerings', NetworkOfferingStoreController::class)->name('api.v1.network-offerings.store');
        Route::post('/networks', NetworkStoreController::class)->name('api.v1.networks.store');
        Route::post('/routers', RouterStoreController::class)->name('api.v1.routers.store');
        Route::post('/floating-ips', FloatingIpStoreController::class)->name('api.v1.floating-ips.store');
        Route::delete('/floating-ips/{floatingIp}', FloatingIpReleaseController::class)->name('api.v1.floating-ips.release');
        Route::post('/security-groups', SecurityGroupStoreController::class)->name('api.v1.security-groups.store');
        Route::patch('/security-groups/{securityGroup}', SecurityGroupUpdateController::class)->name('api.v1.security-groups.update');
        Route::post('/provider-drifts/{providerDrift}/repair', ProviderDriftRepairController::class)->name('api.v1.provider-drifts.repair');
        Route::post('/provider-drifts/{providerDrift}/adopt', ProviderDriftAdoptController::class)->name('api.v1.provider-drifts.adopt');
        Route::get('/projects', ProjectIndexController::class)->name('api.v1.projects.index');
        Route::get('/projects/{project}/ssh-keys', ProjectSshKeyIndexController::class)->name('api.v1.projects.ssh-keys.index');
        Route::post('/projects/{project}/ssh-keys', ProjectSshKeyStoreController::class)->name('api.v1.projects.ssh-keys.store');
        Route::get('/projects/{project}/stacks', ProjectStackIndexController::class)->name('api.v1.projects.stacks.index');
        Route::post('/projects/{project}/stacks', ProjectStackStoreController::class)->name('api.v1.projects.stacks.store');
        Route::get('/deployments', DeploymentIndexController::class)->name('api.v1.deployments.index');
        Route::post('/deployments', DeploymentStoreController::class)->name('api.v1.deployments.store');
        Route::post('/deployments/{deployment}/cloud-init', DeploymentCloudInitStoreController::class)->name('api.v1.deployments.cloud-init.store');
        Route::post('/deployments/{deployment}/operations', DeploymentOperationStoreController::class)->name('api.v1.deployments.operations.store');
        Route::get('/deployments/{deployment}', DeploymentShowController::class)->name('api.v1.deployments.show');
        Route::get('/replay', ReplayController::class)->name('api.v1.replay');
        Route::post('/scripts', ScriptStoreController::class)->name('api.v1.scripts.store');
        Route::patch('/scripts/{script}', ScriptUpdateController::class)->name('api.v1.scripts.update');
        Route::post('/scripts/{script}/approvals', ScriptApprovalStoreController::class)->name('api.v1.scripts.approvals.store');
        Route::post('/scripts/{script}/runs', ScriptRunStoreController::class)->name('api.v1.scripts.runs.store');
        Route::get('/scripts/{script}/runs/{scriptRun}', ScriptRunShowController::class)->name('api.v1.scripts.runs.show');
        Route::get('/tokens', TokenIndexController::class)->name('api.v1.tokens.index');
        Route::post('/tokens', TokenStoreController::class)->name('api.v1.tokens.store');
        Route::delete('/tokens/{tokenGrant}', TokenRevokeController::class)->name('api.v1.tokens.revoke');
    });
