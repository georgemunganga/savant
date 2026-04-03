<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentSubscriptionController;
use App\Http\Controllers\Api\PublicPropertyCatalogController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicPropertyAvailabilityController;
use App\Http\Controllers\Api\SettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('owner-register', [AuthController::class, 'ownerRegister']);
Route::post('otp-verify', [AuthController::class, 'otpVerify']);
Route::post('otp-re-send', [AuthController::class, 'otpReSend']);
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('password-reset/validate', [AuthController::class, 'validatePasswordReset']);
Route::post('password-reset/complete', [AuthController::class, 'completePasswordReset']);

// setting
Route::get('system-currency', [SettingController::class, 'systemCurrency']);
Route::get('system-setting', [SettingController::class, 'systemSetting']);
Route::get('languages', [SettingController::class, 'getLanguage']);
Route::get('language-data/{code}', [SettingController::class, 'getLanguageJson']);
Route::get('public/home', [PublicPropertyCatalogController::class, 'home']);
Route::get('public/properties', [PublicPropertyCatalogController::class, 'index']);
Route::get('public/properties/by-slug/{slug}', [PublicPropertyCatalogController::class, 'showBySlug']);
Route::get('public/properties/{propertyId}', [PublicPropertyCatalogController::class, 'show']);
Route::post(
    'public/properties/{propertyId}/availability-check',
    [PublicPropertyAvailabilityController::class, 'check']
);
Route::post(
    'public/properties/{propertyId}/waitlist',
    [PublicPropertyAvailabilityController::class, 'waitlist']
);
Route::post(
    'public/properties/{propertyId}/bookings/confirm',
    [PublicPropertyAvailabilityController::class, 'confirm']
);


Route::group(['middleware' => ['auth:api']], function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // profile
    Route::get('profile-details', [ProfileController::class, 'profileDetails']);
    Route::post('profile-update', [ProfileController::class, 'profileUpdate']);
    Route::post('change-password', [ProfileController::class, 'changePasswordUpdate']);
    Route::post('delete-account', [ProfileController::class, 'deleteAccount']);

    // notification
    Route::get('notification-status/{id}', [NotificationController::class, 'status']);
});

// payment route start
Route::group(['middleware' => ['auth:api']], function () {
    Route::post('payment-subscription', [PaymentSubscriptionController::class, 'checkout']);
    Route::post('payment', [PaymentController::class, 'checkout']);
});

Route::match(array('GET', 'POST'), 'payment-subscription/verify', [PaymentSubscriptionController::class, 'verify']);
Route::match(array('GET', 'POST'), 'payment-subscription/failed', [PaymentSubscriptionController::class, 'failed']);
Route::match(array('GET', 'POST'), 'payment-verify', [PaymentController::class, 'verify']);
// payment route end
