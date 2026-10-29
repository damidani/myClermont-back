<?php

declare(strict_types=1);

/*
 * Modules actifs. Commenter une ligne désactive le module (routes + écouteurs).
 */
return [
    App\Modules\Account\AccountModule::class,
    App\Modules\Gamification\GamificationModule::class,
    App\Modules\Favorites\FavoritesModule::class,
    App\Modules\Premium\PremiumModule::class,
    App\Modules\Events\EventsModule::class,
    App\Modules\Housing\HousingModule::class,
    App\Modules\Jobs\JobsModule::class,
    App\Modules\Community\CommunityModule::class,
    App\Modules\Services\ServicesModule::class,
    App\Modules\Mobility\MobilityModule::class,
    App\Modules\Messaging\MessagingModule::class,
    App\Modules\Classifieds\ClassifiedsModule::class,
    App\Modules\Explore\ExploreModule::class,
    App\Modules\Places\PlacesModule::class,
    App\Modules\Moderation\ModerationModule::class,
    App\Modules\Deals\DealsModule::class,
    App\Modules\Notifications\NotificationsModule::class,
    App\Modules\AdminApi\AdminApiModule::class,
    App\Modules\Fuel\FuelModule::class,
    App\Modules\Assistant\AssistantModule::class,
    App\Modules\Subscriptions\SubscriptionsModule::class,
];
