<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Services\ContactsService;
use Illuminate\Database\Seeder;

class ContactsSeeder extends Seeder
{
    public function run(): void
    {
        $service = new ContactsService;

        $contacts = [
            ['userid' => 'wangwei',   'name' => '王伟',  'department' => '产品部', 'position' => '产品经理', 'mobile' => '13800000001', 'email' => 'wangwei@example.com'],
            ['userid' => 'wangwei2',  'name' => '汪伟',  'department' => '技术部', 'position' => '后端开发', 'mobile' => '13800000002', 'email' => 'wangwei2@example.com'],
            ['userid' => 'liming',    'name' => '李明',  'department' => '技术部', 'position' => '前端开发', 'mobile' => '13800000003', 'email' => 'liming@example.com'],
            ['userid' => 'zhangsan',  'name' => '张三',  'department' => '市场部', 'position' => '市场总监', 'mobile' => '13800000004', 'email' => 'zhangsan@example.com'],
            ['userid' => 'lisi',      'name' => '李四',  'department' => '财务部', 'position' => '财务主管', 'mobile' => '13800000005', 'email' => 'lisi@example.com'],
            ['userid' => 'wangfang',  'name' => '王芳',  'department' => '人力资源部', 'position' => 'HR', 'mobile' => '13800000006', 'email' => 'wangfang@example.com'],
            ['userid' => 'zhaoliu',   'name' => '赵六',  'department' => '技术部', 'position' => '架构师', 'mobile' => '13800000007', 'email' => 'zhaoliu@example.com'],
            ['userid' => 'sunqi',     'name' => '孙七',  'department' => '产品部', 'position' => '产品助理', 'mobile' => '13800000008', 'email' => 'sunqi@example.com'],
            ['userid' => 'zhoumin',   'name' => '周敏',  'department' => '设计部', 'position' => 'UI设计师', 'mobile' => '13800000009', 'email' => 'zhoumin@example.com'],
            ['userid' => 'chenwei',   'name' => '陈伟',  'department' => '技术部', 'position' => '测试工程师', 'mobile' => '13800000010', 'email' => 'chenwei@example.com'],
            ['userid' => 'liuyang',   'name' => '刘洋',  'department' => '市场部', 'position' => '运营专员', 'mobile' => '13800000011', 'email' => 'liuyang@example.com'],
            ['userid' => 'huanglei',  'name' => '黄磊',  'department' => '技术部', 'position' => 'DevOps', 'mobile' => '13800000012', 'email' => 'huanglei@example.com'],
            ['userid' => 'wugang',    'name' => '吴刚',  'department' => '销售部', 'position' => '销售经理', 'mobile' => '13800000013', 'email' => 'wugang@example.com'],
            ['userid' => 'liqiang',   'name' => '李强',  'department' => '技术部', 'position' => 'CTO', 'mobile' => '13800000014', 'email' => 'liqiang@example.com'],
        ];

        foreach ($contacts as $data) {
            $pinyin = $service->generatePinyin($data['name']);
            Contact::create(array_merge($data, [
                'name_pinyin' => $pinyin['pinyin'],
                'name_initials' => $pinyin['initials'],
            ]));
        }
    }
}
