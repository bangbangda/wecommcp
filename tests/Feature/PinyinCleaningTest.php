<?php

use App\Models\Contact;
use App\Models\ExternalContact;
use App\Services\ContactsService;
use App\Services\ExternalContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// === ContactsService 拼音清洗 ===

test('ContactsService: 中文顿号被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('希依、');
    expect($result['pinyin'])->toBe('xi yi');
    expect($result['initials'])->toBe('xy');
});

test('ContactsService: 英文句号被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('张三.');
    expect($result['pinyin'])->toBe('zhang san');
    expect($result['initials'])->toBe('zs');
});

test('ContactsService: 横杠被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('王-小明');
    expect($result['pinyin'])->toBe('wang xiao ming');
    expect($result['initials'])->toBe('wxm');
});

test('ContactsService: 数字被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('王修刚17806339239');
    expect($result['pinyin'])->toBe('wang xiu gang');
    expect($result['initials'])->toBe('wxg');
});

test('ContactsService: 空格被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('小 和');
    expect($result['pinyin'])->toBe('xiao he');
    expect($result['initials'])->toBe('xh');
});

test('ContactsService: 竖线和混合特殊字符被正确清除', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('德信财务|王修刚17806339239');
    expect($result['pinyin'])->toBe('de xin cai wu wang xiu gang');
    expect($result['initials'])->toBe('dxcwwxg');
});

test('ContactsService: 纯英文名保持不变', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('Robot');
    expect($result['pinyin'])->toBe('Robot');
});

test('ContactsService: 纯特殊字符返回空', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('、。！');
    expect($result['pinyin'])->toBe('');
    expect($result['initials'])->toBe('');
});

test('ContactsService: 空字符串返回空', function () {
    $service = new ContactsService;
    $result = $service->generatePinyin('');
    expect($result['pinyin'])->toBe('');
    expect($result['initials'])->toBe('');
});

// === ExternalContactService 拼音清洗 ===

test('ExternalContactService: 中文顿号被正确清除', function () {
    $service = app(ExternalContactService::class);
    $method = new ReflectionMethod($service, 'generatePinyin');

    $result = $method->invoke($service, '希依、');
    expect($result['pinyin'])->toBe('xi yi');
    expect($result['initials'])->toBe('xy');
});

test('ExternalContactService: 混合特殊字符被正确清除', function () {
    $service = app(ExternalContactService::class);
    $method = new ReflectionMethod($service, 'generatePinyin');

    $result = $method->invoke($service, '德信财务|王修刚17806339239');
    expect($result['pinyin'])->toBe('de xin cai wu wang xiu gang');
    expect($result['initials'])->toBe('dxcwwxg');
});

// === 带特殊字符的联系人搜索集成测试 ===

test('搜索"希依"能匹配到名为"希依、"的外部联系人', function () {
    // 创建带顿号的外部联系人
    $service = app(ExternalContactService::class);
    $method = new ReflectionMethod($service, 'generatePinyin');
    $pinyin = $method->invoke($service, '希依、');

    ExternalContact::create([
        'external_userid' => 'wm_test_xiyi',
        'name' => '希依、',
        'name_pinyin' => $pinyin['pinyin'],
        'name_initials' => $pinyin['initials'],
        'type' => 1,
        'gender' => 0,
    ]);

    $results = $service->searchByName('希依');
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('希依、');
});

test('搜索"王修刚"能匹配到名为"德信财务|王修刚17806339239"的外部联系人', function () {
    $service = app(ExternalContactService::class);
    $method = new ReflectionMethod($service, 'generatePinyin');
    $pinyin = $method->invoke($service, '德信财务|王修刚17806339239');

    ExternalContact::create([
        'external_userid' => 'wm_test_wxg',
        'name' => '德信财务|王修刚17806339239',
        'name_pinyin' => $pinyin['pinyin'],
        'name_initials' => $pinyin['initials'],
        'type' => 1,
        'gender' => 0,
    ]);

    // 模糊匹配应该能找到
    $results = $service->searchByName('王修刚');
    expect($results)->toHaveCount(1);
    expect($results->first()->external_userid)->toBe('wm_test_wxg');
});

test('搜索带特殊字符的内部联系人也能正确匹配', function () {
    $service = new ContactsService;
    $pinyin = $service->generatePinyin('小和');

    Contact::create([
        'userid' => 'xiaohe',
        'name' => '小和',
        'name_pinyin' => $pinyin['pinyin'],
        'name_initials' => $pinyin['initials'],
        'department' => '测试部',
        'position' => '测试',
    ]);

    // 精确匹配
    $results = $service->searchByName('小和');
    expect($results)->toHaveCount(1);
    expect($results->first()->userid)->toBe('xiaohe');
});
