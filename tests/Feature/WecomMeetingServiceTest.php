<?php

use App\Exceptions\WecomApiException;
use App\Wecom\WecomMeetingClient;
use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;

test('WecomMeetingClient 可从容器解析', function () {
    $client = app(WecomMeetingClient::class);

    expect($client)->toBeInstanceOf(WecomMeetingClient::class);
});

test('WecomMeetingClient 方法签名正确', function () {
    $client = app(WecomMeetingClient::class);

    expect(method_exists($client, 'createMeeting'))->toBeTrue();
    expect(method_exists($client, 'cancelMeeting'))->toBeTrue();
    expect(method_exists($client, 'getMeetingInfo'))->toBeTrue();
});

test('createMeeting API 错误时抛出 WecomApiException', function () {
    $mockResponse = Mockery::mock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
    $mockResponse->shouldReceive('toArray')->andReturn([
        'errcode' => 300024,
        'errmsg' => 'invalid meeting params',
    ]);

    $mockHttpClient = Mockery::mock(AccessTokenAwareClient::class);
    $mockHttpClient->shouldReceive('postJson')->with('/cgi-bin/meeting/create', Mockery::any())->andReturn($mockResponse);

    $mockApp = Mockery::mock(\EasyWeChat\Work\Application::class);
    $mockApp->shouldReceive('getClient')->andReturn($mockHttpClient);

    $client = new WecomMeetingClient($mockApp);

    expect(fn () => $client->createMeeting(
        adminUserid: 'test_user',
        title: '测试会议',
        startTime: time(),
        duration: 3600,
        inviteeUserids: ['user1', 'user2'],
    ))->toThrow(WecomApiException::class);
});
