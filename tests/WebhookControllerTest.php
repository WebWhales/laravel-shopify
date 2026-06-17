<?php

namespace Signifly\Shopify\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Signifly\Shopify\Exceptions\WebhookFailed;
use Signifly\Shopify\Webhooks\SecretProvider;
use Signifly\Shopify\Webhooks\Webhook;
use Signifly\Shopify\Webhooks\WebhookValidator;

class WebhookControllerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Route::shopifyWebhooks();
        Event::fake();
    }

    #[Test]
    public function it_is_missing_a_signature()
    {
        $response = $this->postJson($this->getUrl());

        $response->assertStatus(400);
        $response->assertSee('The request did not contain a header named `X-Shopify-Hmac-Sha256`.');
    }

    #[Test]
    public function it_dispatches_an_event_relative_to_topic_name()
    {
        $signature = app(WebhookValidator::class)->calculateSignature('[]', config('shopify.webhooks.secret'));

        $response = $this->postJson($this->getUrl(), [], $this->getValidHeaders([
            Webhook::HEADER_HMAC_SIGNATURE => $signature,
        ]));

        $response->assertOk();
        Event::assertDispatched('shopify-webhooks.orders-create');
    }

    #[Test]
    public function it_throws_an_exception_when_hmac_header_is_missing()
    {
        $this->withoutExceptionHandling();

        $this->expectExceptionObject(WebhookFailed::missingSignature());

        $this->postJson($this->getUrl());
    }

    #[Test]
    public function it_throws_an_exception_with_an_empty_webhook_secret()
    {
        $this->withoutExceptionHandling();

        $this->mock(SecretProvider::class)
            ->shouldReceive('getSecret')
            ->andReturn('')
            ->once();

        $this->expectExceptionObject(WebhookFailed::missingSigningSecret());

        $this->postJson($this->getUrl(), [], $this->getValidHeaders());
    }

    #[Test]
    public function it_throws_an_exception_with_invalid_signature()
    {
        $this->withoutExceptionHandling();

        $this->expectExceptionObject(WebhookFailed::invalidSignature($signature = 'hmac'));

        $this->postJson($this->getUrl(), [], $this->getValidHeaders([
            Webhook::HEADER_HMAC_SIGNATURE => $signature,
        ]));
    }

    private function getUrl(): string
    {
        return route('shopify.webhooks');
    }

    private function getValidHeaders(array $overwrites = []): array
    {
        return array_merge([
            Webhook::HEADER_SHOP_DOMAIN => 'test.myshopify.com',
            Webhook::HEADER_HMAC_SIGNATURE => 'hmac',
            Webhook::HEADER_TOPIC => 'orders/create',
        ], $overwrites);
    }
}
