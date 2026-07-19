<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WidgetController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [AgentController::class, 'landing'])->name('landing');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});
Route::get('/demo/{agent:slug}', [ChatController::class, 'show'])->name('chat.show');
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::post('/demo/{agent:slug}/message', [ChatController::class, 'message'])->middleware('throttle:public-chat')->name('chat.message');
    Route::get('/demo/{agent:slug}/history', [ChatController::class, 'history'])->middleware('throttle:public-history')->name('chat.history');
    Route::post('/demo/{agent:slug}/messages/{message}/feedback', [ChatController::class, 'feedback'])->middleware('throttle:30,1')->name('chat.feedback');
});
Route::get('/widget/{agent:slug}.js', [WidgetController::class, 'script'])->name('widget.script');
Route::get('/widget/{agent:slug}', [WidgetController::class, 'frame'])->name('widget.frame');
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/app', [AgentController::class, 'dashboard'])->name('dashboard');
    Route::get('/onboarding', [AgentController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding', [AgentController::class, 'store'])->name('onboarding.store');
    Route::post('/conversations/{conversation}/handoff', [ChatController::class, 'handoff'])->name('chat.handoff');
    Route::get('/app/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::post('/app/knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::post('/app/knowledge/{source}/sync', [KnowledgeController::class, 'sync'])->name('knowledge.sync');
    Route::delete('/app/knowledge/{source}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
    Route::get('/app/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/app/inbox/{conversation}/take-over', [InboxController::class, 'takeOver'])->name('inbox.take-over');
    Route::post('/app/inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('/app/inbox/{conversation}/release', [InboxController::class, 'release'])->name('inbox.release');
    Route::post('/app/inbox/{conversation}/close', [InboxController::class, 'close'])->name('inbox.close');
    Route::get('/app/inbox/{conversation}/poll', [InboxController::class, 'poll'])->name('inbox.poll');
    Route::get('/app/channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::get('/app/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/app/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/app/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/app/team', [SettingsController::class, 'addMember'])->name('team.add');
    Route::delete('/app/team/{user}', [SettingsController::class, 'removeMember'])->name('team.remove');
});
