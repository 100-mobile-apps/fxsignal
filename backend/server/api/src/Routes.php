<?php
declare(strict_types=1);

/** API routes, shared by api/index.php and the website (which calls the same handlers in-process). */
function api_routes(): array
{
    return [
        ['GET', '#^/$#', fn() => Market::health()],
        ['GET', '#^/health$#', fn() => Market::health()],
        ['POST', '#^/auth/register$#', fn() => Auth::register()],
        ['POST', '#^/auth/login$#', fn() => Auth::login()],
        ['POST', '#^/auth/forgot$#', fn() => Auth::forgot()],
        ['POST', '#^/auth/reset$#', fn() => Auth::reset()],
        ['POST', '#^/auth/verify$#', fn() => Auth::verify()],
        ['POST', '#^/auth/verify/resend$#', fn() => Auth::resendVerification()],
        ['GET', '#^/me$#', fn() => Account::me()],
        ['PUT', '#^/me$#', fn() => Account::update()],
        ['DELETE', '#^/me$#', fn() => Account::delete()],
        ['PUT', '#^/me/password$#', fn() => Account::changePassword()],
        ['PUT', '#^/me/notifications$#', fn() => Account::notifications()],
        ['PUT', '#^/me/watchlist$#', fn() => Account::watchlist()],
        ['PUT', '#^/me/signal-pairs$#', fn() => Signals::setPairs()],
        ['PUT', '#^/me/device$#', fn() => Billing::saveDevice()],
        ['DELETE', '#^/me/device$#', fn() => Billing::deleteDevice()],
        ['POST', '#^/billing/verify$#', fn() => Billing::verify()],
        ['POST', '#^/billing/rtdn$#', fn() => Billing::rtdn()],
        ['POST', '#^/billing/paystack$#', fn() => Paystack::webhook()],
        ['PUT', '#^/me/referrer$#', fn() => Affiliates::apiSetReferrer()],
        ['GET', '#^/signals$#', fn() => Signals::list()],
        ['GET', '#^/signals/(?P<id>\d+)$#', fn($p) => Signals::show($p)],
        ['POST', '#^/signals/(?P<id>\d+)/unlock$#', fn($p) => Billing::freeUnlock($p)],
        ['GET', '#^/stats$#', fn() => Stats::show()],
        ['GET', '#^/reports$#', fn() => Reports::show()],
        ['GET', '#^/email/unsubscribe$#', fn() => Notify::unsubscribe()],
        ['POST', '#^/email/unsubscribe$#', fn() => Notify::unsubscribe()],
        ['GET', '#^/market/prices$#', fn() => Market::prices()],
    ];
}
