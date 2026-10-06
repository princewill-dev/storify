<?php

use App\Http\Controllers\Api\V1\Admin\CompanyServiceController;
use App\Http\Controllers\Api\V1\Admin\TestimonialController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-18 — Marketing content: testimonials & company services (WS18)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so these inherit the "admin" prefix, the "api.admin." name and that
| middleware. Only Route:: lines belong in this file.
|
| `permission:admin.content` is the seeded gate the roadmap maps to both
| screens (SpatiePermissionSeeder; legacy `admin_dashboard.php:172` for
| testimonials, the Content sidebar section for company services).
| AdminApiActivityLogger rides the routes so curating public marketing copy is
| itself audited (it de-dupes per request against WS-1's group-level wiring).
|
| Static segments (`testimonials/backfill-photos`, `company-services/reorder`)
| are registered before the `{testimonial}` / `{companyService}` routes for
| readability; static segments outscore parameters in the matcher, so they can
| never resolve as ids anyway.
|
| `{testimonial}` and `{companyService}` are numeric ids — legacy addressed
| both by id and neither model has a public code.
|
| Neither table is tenant-scoped (platform marketing content), so no
| business/store filter applies; the controllers carry the
| EnsuresPlatformAdmin guard because an in-business "Super Admin" role holds
| the full admin.* permission bundle.
*/

Route::middleware(['permission:admin.content', AdminApiActivityLogger::class])->group(function () {
    Route::get('testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
    Route::post('testimonials/backfill-photos', [TestimonialController::class, 'backfillPhotos'])->name('testimonials.backfill-photos');
    Route::post('testimonials', [TestimonialController::class, 'store'])->name('testimonials.store');
    Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->name('testimonials.update');
    Route::post('testimonials/{testimonial}/toggle', [TestimonialController::class, 'toggle'])->name('testimonials.toggle');
    Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->name('testimonials.destroy');

    Route::get('company-services', [CompanyServiceController::class, 'index'])->name('company-services.index');
    Route::post('company-services/reorder', [CompanyServiceController::class, 'reorder'])->name('company-services.reorder');
    Route::post('company-services', [CompanyServiceController::class, 'store'])->name('company-services.store');
    Route::put('company-services/{companyService}', [CompanyServiceController::class, 'update'])->name('company-services.update');
    Route::post('company-services/{companyService}/toggle', [CompanyServiceController::class, 'toggle'])->name('company-services.toggle');
    Route::delete('company-services/{companyService}', [CompanyServiceController::class, 'destroy'])->name('company-services.destroy');
});
