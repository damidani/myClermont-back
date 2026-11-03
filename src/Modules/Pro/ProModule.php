<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Espace commerçant MyClermont Pro (canal agents, demandes 1 à 8 du 31/10/2026).
 *
 * - routes /pro/* : site web commercant.myclermont.app, sans signature d'appareil (session + limites de débit), montées par proRoutes() ;
 * - routes de l'app (signées) : écrire au commerçant, lire ses réponses, remonter les statistiques de fiche.
 */
final class ProModule implements Module
{
    /** Routes de l'app (déjà sous « device » + « throttle:device »). */
    public function routes(Router $router): void
    {
        $router->post('/places/{id}/events', [StatsController::class, 'track']);
        // Stories des établissements (b5b4c116)
        $router->get('/stories', [StoryController::class, 'feed'], ['auth:optional']);
        $router->post('/stories/{id}/view', [StoryController::class, 'view']);
        $router->post('/stories/{id}/cta', [StoryController::class, 'cta']);
        $router->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/places/{id}/messages', [ThreadController::class, 'create'], ['throttle:contact']);
            $r->get('/me/threads', [ThreadController::class, 'mine']);
            $r->post('/me/threads/{id}/messages', [ThreadController::class, 'reply'], ['throttle:message']);
        });
    }

    /** Routes de l'espace pro, montées sous /v1/pro et /api/v1/pro (hors signature d'appareil). */
    public static function proRoutes(Router $r): void
    {
        $r->group(['prefix' => '/pro', 'middleware' => ['throttle:pro,ip']], static function (Router $r): void {
            // Sans session
            $r->group(['middleware' => ['throttle:pro_auth,ip']], static function (Router $r): void {
                $r->post('/auth/signup', [AuthController::class, 'signup']);
                $r->post('/auth/login', [AuthController::class, 'login']);
                $r->post('/auth/password/forgot', [AuthController::class, 'forgot']);
                $r->post('/auth/password/reset', [AuthController::class, 'reset']);
                $r->post('/team/invitations/{token}/accept', [AccountController::class, 'acceptInvitation']);
            });
            $r->post('/auth/refresh', [AuthController::class, 'refresh']);
            $r->post('/auth/logout', [AuthController::class, 'logout']);
            $r->get('/team/invitations/{token}', [AccountController::class, 'invitation']);
            $r->get('/cv/{id}', [RecruitController::class, 'downloadCv']);

            // Connecté
            $r->group(['middleware' => ['pro']], static function (Router $r): void {
                $r->post('/auth/password', [AuthController::class, 'changePassword']);
                $r->get('/auth/sessions', [AuthController::class, 'sessions']);
                $r->delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession']);
                $r->post('/auth/2fa', [AuthController::class, 'twoFactor']);
                $r->delete('/auth/2fa', [AuthController::class, 'twoFactor']);

                $r->get('/me', [AccountController::class, 'me']);
                $r->patch('/me', [AccountController::class, 'updateMe']);
                $r->delete('/me', [AccountController::class, 'destroy']);
                $r->patch('/me/email', [AccountController::class, 'updateEmail']);
                $r->patch('/me/notifications', [AccountController::class, 'updateNotifications']);
                $r->get('/me/export', [AccountController::class, 'export']);
                $r->get('/notifications', [AccountController::class, 'notifications']);
                $r->post('/notifications/read', [AccountController::class, 'readNotifications']);

                $r->get('/business', [AccountController::class, 'business']);
                $r->patch('/business', [AccountController::class, 'updateBusiness']);
                $r->patch('/business/modules', [AccountController::class, 'updateModules']);
                $r->get('/team', [AccountController::class, 'team']);
                $r->post('/team/invitations', [AccountController::class, 'invite']);
                $r->post('/team/invitations/{id}/resend', [AccountController::class, 'resendInvite']);
                $r->patch('/team/{id}', [AccountController::class, 'updateMember']);
                $r->delete('/team/{id}', [AccountController::class, 'removeMember']);
                $r->get('/partnership', [AccountController::class, 'partnership']);
                $r->post('/partnership/subscribe', [AccountController::class, 'subscribe']);
                $r->post('/partnership/cancel', [AccountController::class, 'cancel']);
                $r->post('/partnership/contact-request', [AccountController::class, 'contactRequest']);
                $r->get('/invoices', [AccountController::class, 'invoices']);
                $r->get('/invoices/{id}/pdf', [AccountController::class, 'invoicePdf']);

                $r->post('/uploads', [UploadController::class, 'create'], ['throttle:upload,ip']);
                $r->post('/uploads/{id}/confirm', [UploadController::class, 'confirm']);

                $r->get('/place', [PlaceController::class, 'show']);
                $r->put('/place', [PlaceController::class, 'update']);
                $r->post('/place/submit', [PlaceController::class, 'submit']);

                $r->get('/reviews', [ReviewController::class, 'index']);
                $r->post('/reviews/{id}/reply', [ReviewController::class, 'reply']);
                $r->put('/reviews/{id}/reply', [ReviewController::class, 'reply']);
                $r->delete('/reviews/{id}/reply', [ReviewController::class, 'deleteReply']);
                $r->post('/reviews/{id}/report', [ReviewController::class, 'report']);
                $r->delete('/reviews/{id}/report', [ReviewController::class, 'unreport']);

                $r->get('/threads', [ThreadController::class, 'index']);
                $r->get('/threads/{id}', [ThreadController::class, 'show']);
                $r->patch('/threads/{id}', [ThreadController::class, 'update']);
                $r->delete('/threads/{id}', [ThreadController::class, 'destroy']);
                $r->post('/threads/{id}/read', [ThreadController::class, 'read']);
                $r->post('/threads/{id}/messages', [ThreadController::class, 'send']);

                $r->get('/deals', [DealController::class, 'index']);
                $r->post('/deals', [DealController::class, 'create']);
                $r->patch('/deals/{id}', [DealController::class, 'update']);
                $r->delete('/deals/{id}', [DealController::class, 'destroy']);
                $r->get('/deal-codes', [DealController::class, 'codes']);
                $r->get('/deal-codes/{code}', [DealController::class, 'check']);
                $r->post('/deal-codes/{code}/redeem', [DealController::class, 'redeem']);

                $r->get('/events', [EventController::class, 'index']);
                $r->post('/events', [EventController::class, 'create']);
                $r->patch('/events/{id}', [EventController::class, 'update']);
                $r->delete('/events/{id}', [EventController::class, 'destroy']);
                $r->post('/events/{id}/submit', [EventController::class, 'submit']);
                $r->post('/events/{id}/withdraw', [EventController::class, 'withdraw']);

                $r->get('/visitor-photos', [VisitorPhotoController::class, 'index']);
                $r->post('/visitor-photos/validate', [VisitorPhotoController::class, 'validate']);
                $r->get('/visitor-photos/{id}', [VisitorPhotoController::class, 'show']);
                $r->patch('/visitor-photos/{id}', [VisitorPhotoController::class, 'update']);
                $r->post('/visitor-photos/{id}/refuse', [VisitorPhotoController::class, 'refuse']);
                $r->post('/visitor-photos/{id}/reopen', [VisitorPhotoController::class, 'reopen']);

                $r->get('/stories', [StoryController::class, 'index']);
                $r->post('/stories', [StoryController::class, 'create']);
                $r->patch('/stories/{id}', [StoryController::class, 'update']);
                $r->delete('/stories/{id}', [StoryController::class, 'destroy']);

                $r->get('/jobs', [RecruitController::class, 'jobs']);
                $r->post('/jobs', [RecruitController::class, 'createJob']);
                $r->patch('/jobs/{id}', [RecruitController::class, 'updateJob']);
                $r->delete('/jobs/{id}', [RecruitController::class, 'deleteJob']);
                $r->get('/applications', [RecruitController::class, 'applications']);
                $r->delete('/applications', [RecruitController::class, 'purgeApplications']);
                $r->patch('/applications/{id}', [RecruitController::class, 'updateApplication']);
                $r->delete('/applications/{id}', [RecruitController::class, 'deleteApplication']);
                $r->get('/applications/{id}/cv', [RecruitController::class, 'cv']);

                $r->get('/services', [RecruitController::class, 'services']);
                $r->post('/services', [RecruitController::class, 'createService']);
                $r->patch('/services/{id}', [RecruitController::class, 'updateService']);
                $r->delete('/services/{id}', [RecruitController::class, 'deleteService']);
                $r->get('/quotes', [RecruitController::class, 'quotes']);
                $r->patch('/quotes/{id}', [RecruitController::class, 'updateQuote']);
                $r->delete('/quotes/{id}', [RecruitController::class, 'deleteQuote']);

                $r->get('/housings', [RecruitController::class, 'housings']);
                $r->post('/housings', [RecruitController::class, 'createHousing']);
                $r->patch('/housings/{id}', [RecruitController::class, 'updateHousing']);
                $r->delete('/housings/{id}', [RecruitController::class, 'deleteHousing']);
                $r->get('/housing-contacts', [RecruitController::class, 'housingContacts']);
                $r->patch('/housing-contacts/{id}', [RecruitController::class, 'updateHousingContact']);
                $r->delete('/housing-contacts/{id}', [RecruitController::class, 'deleteHousingContact']);

                $r->get('/stats', [StatsController::class, 'index']);
                $r->get('/stats/export', [StatsController::class, 'export']);
            });
        });
    }

    /** CORS de l'espace pro : origine du front uniquement, avec cookies. */
    public static function isProPath(string $path): bool
    {
        return (bool) preg_match('#^(/api)?/v1/pro(/|$)#', $path);
    }

    public static function cors(Request $request, Response $response): Response
    {
        $origin = (string) $request->header('origin', '');
        if ($origin !== '' && in_array($origin, (array) Config::get('pro.origins', []), true)) {
            $response->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
            if ($request->method === 'OPTIONS') {
                $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
                    ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
                    ->withHeader('Access-Control-Max-Age', '600');
            }
        }

        return $response;
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
