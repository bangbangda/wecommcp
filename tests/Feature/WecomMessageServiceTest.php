<?php

use App\Wecom\WecomMessageClient;

test('WecomMessageClient 可从容器解析', function () {
    $client = app(WecomMessageClient::class);

    expect($client)->toBeInstanceOf(WecomMessageClient::class);
});

test('WecomMessageClient 方法签名正确', function () {
    $client = app(WecomMessageClient::class);

    expect(method_exists($client, 'sendText'))->toBeTrue();

    $method = new ReflectionMethod($client, 'sendText');
    $params = $method->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('toUser');
    expect($params[1]->getName())->toBe('content');
    expect($method->getReturnType()->getName())->toBe('array');
});
