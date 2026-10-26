<?php

namespace App\Tests\Unit\Service;

use App\Service\CallbackNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CallbackNotifierTest extends TestCase
{
    public function testSchemelessLegacyCallbackIsSentOverHttp(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body']];
            return new MockResponse('ok', ['http_code' => 200]);
        });

        $sent = (new CallbackNotifier($http, new NullLogger()))->notifyReady('ortrac.mesd.k12.or.us/subdocs/external/ready', 'abc');

        self::assertTrue($sent);
        self::assertSame(['POST', 'http://ortrac.mesd.k12.or.us/subdocs/external/ready', 'media_id=abc'], $seen);
    }

    public function testNon200IsReportedAsNotSent(): void
    {
        $http = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));

        self::assertFalse((new CallbackNotifier($http, new NullLogger()))->notifyReady('https://example.org/cb', 'abc'));
    }

    public function testOtherSchemesAreRefused(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('must not be called'));

        self::assertFalse((new CallbackNotifier($http, new NullLogger()))->notifyReady('file:///etc/passwd', 'abc'));
    }
}
