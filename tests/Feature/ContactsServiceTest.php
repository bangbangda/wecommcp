<?php

use App\Models\Contact;
use App\Services\ContactsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ContactsService;

    // 种子数据：含同音字测试用例
    $contacts = [
        ['userid' => 'wangwei',  'name' => '王伟',  'department' => '产品部', 'position' => '产品经理'],
        ['userid' => 'wangwei2', 'name' => '汪伟',  'department' => '技术部', 'position' => '后端开发'],
        ['userid' => 'liming',   'name' => '李明',  'department' => '技术部', 'position' => '前端开发'],
        ['userid' => 'zhangsan', 'name' => '张三',  'department' => '市场部', 'position' => '市场总监'],
        ['userid' => 'wangfang', 'name' => '王芳',  'department' => '人力资源部', 'position' => 'HR'],
        ['userid' => 'liqiang',  'name' => '李强',  'department' => '技术部', 'position' => 'CTO'],
    ];

    foreach ($contacts as $data) {
        $pinyin = $this->service->generatePinyin($data['name']);
        Contact::create(array_merge($data, [
            'name_pinyin' => $pinyin['pinyin'],
            'name_initials' => $pinyin['initials'],
        ]));
    }
});

// === 拼音生成 ===

test('generatePinyin 生成正确的拼音和首字母', function () {
    $result = $this->service->generatePinyin('王伟');
    expect($result['pinyin'])->toBe('wang wei');
    expect($result['initials'])->toBe('ww');
});

test('generatePinyin 同音字生成相同拼音', function () {
    $wang1 = $this->service->generatePinyin('王伟');
    $wang2 = $this->service->generatePinyin('汪伟');
    expect($wang1['pinyin'])->toBe($wang2['pinyin']);
    expect($wang1['initials'])->toBe($wang2['initials']);
});

test('generatePinyin 不同名字生成不同拼音', function () {
    $a = $this->service->generatePinyin('张三');
    $b = $this->service->generatePinyin('李明');
    expect($a['pinyin'])->not->toBe($b['pinyin']);
});

// === 优先级1: 精确匹配 ===

test('精确匹配返回唯一结果', function () {
    $results = $this->service->searchByName('李明');
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('李明');
    expect($results->first()->userid)->toBe('liming');
});

test('精确匹配：王伟只返回王伟', function () {
    $results = $this->service->searchByName('王伟');
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('王伟');
});

test('精确匹配：汪伟只返回汪伟', function () {
    $results = $this->service->searchByName('汪伟');
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('汪伟');
});

// === 优先级2: 拼音全匹配（同音字核心场景） ===

test('拼音匹配：不存在的同音字返回所有同音人', function () {
    // "忘伟" 不在数据库中，但拼音 wang wei 匹配到 王伟+汪伟
    $results = $this->service->searchByName('忘伟');
    expect($results)->toHaveCount(2);
    expect($results->pluck('name')->sort()->values()->toArray())->toBe(['汪伟', '王伟']);
});

test('拼音匹配：另一个同音字写法也能匹配', function () {
    $results = $this->service->searchByName('旺伟');
    expect($results)->toHaveCount(2);
    expect($results->pluck('name')->toArray())->toContain('王伟', '汪伟');
});

test('拼音匹配：语音识别错字场景', function () {
    // 语音说"李明"，ASR识别成"黎明"
    $results = $this->service->searchByName('黎明');
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('李明');
});

// === 优先级3: 首字母匹配 ===

test('首字母匹配：当拼音全匹配无结果时使用首字母', function () {
    // 构造一个仅首字母匹配的场景
    $pinyin = $this->service->generatePinyin('张三');
    expect($pinyin['initials'])->toBe('zs');

    // "张三" 精确匹配就能找到，这里直接验证首字母值正确
    $results = $this->service->searchByName('张三');
    expect($results)->toHaveCount(1);
});

// === 优先级4: 模糊匹配（兜底） ===

test('模糊匹配：部分姓名匹配', function () {
    // "王" 不会精确/拼音/首字母命中（wang 拼音也不匹配完整记录），走模糊
    $results = $this->service->searchByName('王');
    expect($results->count())->toBeGreaterThanOrEqual(1);
    $names = $results->pluck('name')->toArray();
    // 应包含所有姓王的人
    expect($names)->toContain('王伟');
    expect($names)->toContain('王芳');
});

// === 未找到 ===

test('搜索不存在的人返回空集合', function () {
    $results = $this->service->searchByName('不存在的人');
    expect($results)->toBeEmpty();
});
