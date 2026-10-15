<?php 
declare(strict_types=1);

use App\Core\Router ;
use App\Middleware\RequireLogin;
use App\Middleware\RequireGuest;
use App\Middleware\VerifyCsrf;
use App\Middleware\RateLimit;
use App\Middleware\RateLimitLive;

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UserController;
use App\Controllers\EntryController;
use App\Controllers\AnalyticsController;
use App\Controllers\Api\EntryController as ApiEntryController;
use App\Controllers\Api\SentimentController  as  ApiSentimentController;
use App\Controllers\Api\AnalyticsController as ApiAnalyticsController;

//δημισια
$router-> get ('/' , [HomeController::class , 'index'], 'home');
//οχι εγγεγραμενοι 
$router-> group(['middleware' => [RequireGuest::class, VerifyCsrf::class]], function (Router $router): void {
    $router->get( '/login',   [AuthController::class,'showLoginForm'],    'auth.login.form');
    $router->middleware([RateLimit::class])->post('/login',   [AuthController::class , 'login' ], 'auth.login.attempt');
    $router->get('/register', [AuthController::class,'showRegisterForm'], 'auth.register.form');
    $router->middleware([RateLimit::class])->post('/register',[AuthController::class,'register'],  'auth.register.attempt');
    $router->get('/account/restore',          [AuthController::class, 'showRestore'],   'auth.restore.form');
    $router->post('/account/restore',         [AuthController::class, 'restore'],       'auth.restore');
    $router->post('/account/restore/cancel',  [AuthController::class, 'cancelRestore'], 'auth.restore.cancel');
});
//log in
$router->group(['middleware' => [RequireLogin::class, VerifyCsrf::class]], function (Router $router) : void {

    $router->post('/logout', [AuthController::class , 'logout'],  'auth.logout');

    $router->get('/dashboard',    [DashboardController::class , 'index'] ,      'app.dashboard');
    $router->get('/profile',      [UserController::class,       'showProfile'], 'app.profile');
    $router->middleware([RateLimit::class])->post('/account/delete', [UserController::class, 'deleteAccount'], 'app.account.delete');
    $router->get('/entry/editor', [EntryController::class,      'showEditor'],  'app.entry.editor');
    $router->get('/entry/history',[EntryController::class,      'history'],     'app.entry.history');
    $router->get('/entry/{id}',   [EntryController::class,      'view'],        'app.entry.view');
    $router->get('/analytics',    [AnalyticsController::class,  'index'],       'app.analytics');

    //Api
    $router->post('/api/entries',             [ApiEntryController::class,  'store'],           'api.entries.store');
    $router->get('/api/entries/{id}',        [ApiEntryController::class,  'show'],            'api.entries.show');
    $router->patch('/api/entries/{id}',       [ApiEntryController::class,  'update'],          'api.entries.update');
    $router->delete('/api/entries/{id}',     [ApiEntryController::class,'destroy'],           'api.entries.destroy');
    $router->middleware([RateLimitLive::class])->post('/api/sentiment/live', [ApiSentimentController::class,'analyzeLive'],  'api.sentiment.live');
    $router->get('/api/analytics/mood-trends',[ApiAnalyticsController::class,'getMoodTrends'],'api.analytics.trends');

});



