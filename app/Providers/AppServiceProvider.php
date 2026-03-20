<?php

namespace App\Providers;

use App\Ai\AiManager;
use App\Ai\Contracts\AiDriver;
use App\Mcp\ToolRegistry;
use App\Wecom\WecomCheckinClient;
use App\Wecom\WecomContactClient;
use App\Wecom\WecomDocumentClient;
use App\Wecom\WecomExternalContactClient;
use App\Wecom\WecomGroupChatClient;
use App\Wecom\WecomJournalClient;
use App\Wecom\WecomManager;
use App\Wecom\WecomMeetingClient;
use App\Wecom\WecomMeetingRoomClient;
use App\Wecom\WecomMessageClient;
use App\Wecom\WecomScheduleClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiManager::class, fn ($app) => new AiManager($app));
        $this->app->alias(AiManager::class, AiDriver::class);

        $this->app->singleton(ToolRegistry::class);

        // 企业微信应用管理器
        $this->app->singleton(WecomManager::class);

        // 容器别名（Controller 等通过别名获取 WecomApp 实例）
        $this->app->bind('wecom.app', fn ($app) => $app->make(WecomManager::class)->app('agent'));
        $this->app->bind('wecom.bot', fn ($app) => $app->make(WecomManager::class)->app('bot'));

        $this->app->singleton(WecomMeetingClient::class, fn ($app) => new WecomMeetingClient(
            app: $app->make(WecomManager::class)->app('agent'),
        ));

        $this->app->singleton(WecomMeetingRoomClient::class, fn ($app) => new WecomMeetingRoomClient(
            app: $app->make(WecomManager::class)->app('agent'),
        ));

        $this->app->singleton(WecomContactClient::class, fn ($app) => new WecomContactClient(
            manager: $app->make(WecomManager::class),
        ));

        $this->app->singleton(WecomMessageClient::class, fn ($app) => new WecomMessageClient(
            app: $app->make(WecomManager::class)->app('agent'),
            agentId: config('services.wecom.apps.agent.id'),
        ));

        $this->app->singleton(WecomScheduleClient::class, fn ($app) => new WecomScheduleClient(
            app: $app->make(WecomManager::class)->app('agent'),
        ));

        $this->app->singleton(WecomGroupChatClient::class, fn ($app) => new WecomGroupChatClient(
            app: $app->make(WecomManager::class)->app('agent'),
        ));

        $this->app->singleton(WecomExternalContactClient::class, fn ($app) => new WecomExternalContactClient(
            manager: $app->make(WecomManager::class),
        ));

        $this->app->singleton(WecomDocumentClient::class, fn ($app) => new WecomDocumentClient(
            manager: $app->make(WecomManager::class),
        ));

        $this->app->singleton(WecomJournalClient::class, fn ($app) => new WecomJournalClient(
            manager: $app->make(WecomManager::class),
        ));

        $this->app->singleton(WecomCheckinClient::class, fn ($app) => new WecomCheckinClient(
            app: $app->make(WecomManager::class)->app('agent'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
