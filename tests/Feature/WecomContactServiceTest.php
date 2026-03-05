<?php

use App\Exceptions\WecomApiException;
use App\Wecom\WecomContactClient;
use App\Wecom\WecomManager;
use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;

test('WecomContactClient 可从容器解析', function () {
    $client = app(WecomContactClient::class);

    expect($client)->toBeInstanceOf(WecomContactClient::class);
});

test('WecomContactClient 方法签名正确', function () {
    $client = app(WecomContactClient::class);

    expect(method_exists($client, 'getUserIdList'))->toBeTrue();
    expect(method_exists($client, 'getUser'))->toBeTrue();
    expect(method_exists($client, 'getDepartment'))->toBeTrue();
});

test('getUserIdList 成功时返回数组', function () {
    $mockResponse = Mockery::mock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
    $mockResponse->shouldReceive('toArray')->andReturn([
        'errcode' => 0,
        'errmsg' => 'ok',
        'dept_user' => [
            ['userid' => 'user1', 'department' => 1],
        ],
        'next_cursor' => '',
    ]);

    $mockHttpClient = Mockery::mock(AccessTokenAwareClient::class);
    $mockHttpClient->shouldReceive('postJson')->with('/cgi-bin/user/list_id', Mockery::any())->andReturn($mockResponse);

    $mockApp = Mockery::mock(\EasyWeChat\Work\Application::class);
    $mockApp->shouldReceive('getClient')->andReturn($mockHttpClient);

    $mockManager = Mockery::mock(WecomManager::class);
    $mockManager->shouldReceive('app')->with('contact')->andReturn($mockApp);

    $client = new WecomContactClient($mockManager);
    $result = $client->getUserIdList();

    expect($result)->toBeArray();
    expect($result)->toHaveKey('dept_user');
    expect($result['dept_user'])->toHaveCount(1);
});

test('getUser API 错误时抛出 WecomApiException', function () {
    $mockResponse = Mockery::mock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
    $mockResponse->shouldReceive('toArray')->andReturn([
        'errcode' => 60111,
        'errmsg' => 'userid not found',
    ]);

    $mockHttpClient = Mockery::mock(AccessTokenAwareClient::class);
    $mockHttpClient->shouldReceive('get')->with('/cgi-bin/user/get', Mockery::any())->andReturn($mockResponse);

    $mockApp = Mockery::mock(\EasyWeChat\Work\Application::class);
    $mockApp->shouldReceive('getClient')->andReturn($mockHttpClient);

    $mockManager = Mockery::mock(WecomManager::class);
    $mockManager->shouldReceive('app')->with('agent')->andReturn($mockApp);

    $client = new WecomContactClient($mockManager);

    expect(fn () => $client->getUser('nonexistent'))->toThrow(WecomApiException::class);
});

test('getDepartment 成功时返回数组', function () {
    $mockResponse = Mockery::mock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
    $mockResponse->shouldReceive('toArray')->andReturn([
        'errcode' => 0,
        'errmsg' => 'ok',
        'department' => [
            'id' => 1,
            'name' => '技术部',
            'parentid' => 0,
        ],
    ]);

    $mockHttpClient = Mockery::mock(AccessTokenAwareClient::class);
    $mockHttpClient->shouldReceive('get')->with('/cgi-bin/department/get', Mockery::any())->andReturn($mockResponse);

    $mockApp = Mockery::mock(\EasyWeChat\Work\Application::class);
    $mockApp->shouldReceive('getClient')->andReturn($mockHttpClient);

    $mockManager = Mockery::mock(WecomManager::class);
    $mockManager->shouldReceive('app')->with('agent')->andReturn($mockApp);

    $client = new WecomContactClient($mockManager);
    $result = $client->getDepartment(1);

    expect($result)->toBeArray();
    expect($result['department']['name'])->toBe('技术部');
});
