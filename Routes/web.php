<?php 
declare(strict_types=1);

use App\Core\Router ;
use App\Middleware\RequireLogin;
use App\Middleware\RequireGuest;
use App\Middleware\VerifyCsrf;
use App\Middleware\RateLimit;
use App\Middleware\RateLimitLive;
use App\Middleware\RateLimitEntries;

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UserController;
use App\Controllers\EntryController;
use App\Controllers\AnalyticsController;
use App\Controllers\Api\EntryController as ApiEntryController;
use App\Controllers\Api\SentimentController  as  ApiSentimentController;
use App\Controllers\Api\AnalyticsController as ApiAnalyticsController;
use App\Controllers\Api\InsightController as ApiInsightController;
use App\Controllers\Api\MessageController as ApiMessageController;
use App\Controllers\Api\SummaryController as ApiSummaryController;
use App\Controllers\Api\ProfileController as ApiProfileController;

//δημισια
$router-> get ('/' , [HomeController::class , 'index'], 'home');
//οχι εγγεγραμενοι 
$router-> group(['middleware' => [RequireGuest::class, VerifyCsrf::class]], function (Router $router): void {
    $router->get( '/login',   [AuthController::class,'showLoginForm'],    'auth.login.form');
    $router->middleware([RateLimit::class])->post('/login',   [AuthController::class , 'login' ], 'auth.login.attempt');
    $router->get('/register', [AuthController::class,'showRegisterForm'], 'auth.register.form');
    $router->middleware([RateLimit::class])->post('/register',[AuthController::class,'register'],  'auth.register.attempt');
    $router->get('/verify-email', [AuthController::class, 'showVerify'], 'auth.verify.form');
    $router->middleware([RateLimit::class])->post('/verify-email', [AuthController::class, 'verify'], 'auth.verify');
    $router->middleware([RateLimit::class])->post('/verify-email/resend', [AuthController::class, 'resendVerification'], 'auth.verify.resend');
    $router->get('/password/forgot', [AuthController::class, 'showForgot'], 'auth.password.forgot.form');
    $router->middleware([RateLimit::class])->post('/password/forgot', [AuthController::class, 'forgot'], 'auth.password.forgot');
    $router->get('/password/reset', [AuthController::class, 'showReset'], 'auth.password.reset.form');
    $router->middleware([RateLimit::class])->post('/password/reset', [AuthController::class, 'reset'], 'auth.password.reset');
    $router->get('/account/restore',      [AuthController::class, 'showRestore'],   'auth.restore.form');
    $router->post('/account/restore',         [AuthController::class, 'restore'],       'auth.restore');
    $router->post('/account/restore/cancel',  [AuthController::class, 'cancelRestore'], 'auth.restore.cancel');
});
//log in
$router->group(['middleware' => [RequireLogin::class, VerifyCsrf::class]], function (Router $router) : void {

    $router->post('/logout', [AuthController::class , 'logout'],  'auth.logout');

    $router->get('/dashboard',    [DashboardController::class , 'index'] ,      'app.dashboard');
    $router->get('/profile',      [UserController::class,       'showProfile'], 'app.profile');
    $router->middleware([RateLimit::class])->post('/account/delete', [UserController::class, 'deleteAccount'], 'app.account.delete');
    $router->middleware([RateLimit::class])->post('/account/password', [UserController::class, 'changePassword'], 'app.account.password');
    $router->get('/account/export', [UserController::class, 'exportData'], 'app.account.export');
    $router->get('/entry/editor', [EntryController::class,      'showEditor'],  'app.entry.editor');
    $router->get('/entry/history',[EntryController::class,      'history'],     'app.entry.history');
    $router->get('/entry/{id}',   [EntryController::class,      'view'],        'app.entry.view');
    $router->get('/analytics',    [AnalyticsController::class,  'index'],       'app.analytics');

    //Api
    $router->get('/api/entries',              [ApiEntryController::class,  'index'],           'api.entries.index');
    $router->middleware([RateLimitEntries::class])->post('/api/entries', [ApiEntryController::class, 'store'], 'api.entries.store');
    $router->get('/api/entries/{id}',        [ApiEntryController::class,  'show'],            'api.entries.show');
    $router->middleware([RateLimitEntries::class])->patch('/api/entries/{id}', [ApiEntryController::class, 'update'], 'api.entries.update');
    $router->delete('/api/entries/{id}',     [ApiEntryController::class,'destroy'],           'api.entries.destroy');
    $router->middleware([RateLimitLive::class])->post('/api/sentiment/live', [ApiSentimentController::class,'analyzeLive'],  'api.sentiment.live');
    $router->get('/api/analytics/mood-trends',[ApiAnalyticsController::class,'getMoodTrends'],'api.analytics.trends');
    $router->get('/api/analytics/emotions',   [ApiAnalyticsController::class,'getEmotionDistribution'],'api.analytics.emotions');
    $router->get('/api/analytics/weekly',     [ApiAnalyticsController::class,'getWeeklyAverage'],'api.analytics.weekly');
    $router->get('/api/analytics/streak',     [ApiAnalyticsController::class,'getStreak'],     'api.analytics.streak');
    $router->get('/api/analytics/monthly',    [ApiAnalyticsController::class,'getMonthlyComparison'],'api.analytics.monthly');
    $router->get('/api/analytics/entities',   [ApiAnalyticsController::class,'getTopEntities'],'api.analytics.entities');

    $router->get('/api/insights',             [ApiInsightController::class, 'index'],          'api.insights.index');
    $router->post('/api/insights/{id}/read',  [ApiInsightController::class, 'markRead'],       'api.insights.read');
    $router->get('/api/messages/today',       [ApiMessageController::class, 'today'],          'api.messages.today');
    $router->post('/api/messages/{id}/seen',  [ApiMessageController::class, 'markSeen'],       'api.messages.seen');
    $router->get('/api/summaries',            [ApiSummaryController::class, 'index'],          'api.summaries.index');
    $router->get('/api/profile',              [ApiProfileController::class, 'show'],           'api.profile.show');
    $router->patch('/api/profile',            [ApiProfileController::class, 'update'],         'api.profile.update');

});



