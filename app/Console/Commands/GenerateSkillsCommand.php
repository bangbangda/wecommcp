<?php

namespace App\Console\Commands;

use App\Models\WecomApiDoc;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateSkillsCommand extends Command
{
    protected $signature = 'wecom:generate-skills
        {--module= : 只生成指定模块（category_id）}
        {--list : 列出所有可用模块}
        {--force : 覆盖已存在的文件}';

    protected $description = '根据 wecom_api_docs 数据自动生成 Skill 文件';

    /**
     * 单个 reference 文件最大字符数（约 2000-3000 tokens）
     * 超过此阈值的子分类将按文档拆分为独立文件
     */
    private const MAX_REF_FILE_SIZE = 8000;

    /**
     * 模块名称到 kebab-case 的映射
     *
     * @var array<string, string>
     */
    private const MODULE_NAME_MAP = [
        '通讯录管理' => 'contacts',
        '应用管理' => 'app-manage',
        '消息接收与发送' => 'message',
        '素材管理' => 'media',
        '身份验证' => 'auth',
        '打卡' => 'attendance',
        '审批' => 'approval',
        '企业支付' => 'payment',
        '电子发票' => 'invoice',
        '开发指南' => 'dev-guide',
        '会话内容存档' => 'chat-archive',
        '紧急通知应用' => 'emergency',
        '家校沟通' => 'school-contact',
        '客户联系' => 'external-contact',
        '企业互联' => 'corp-connect',
        '汇报' => 'report',
        '政民沟通' => 'gov-contact',
        '会议室' => 'meeting-room',
        '日程' => 'schedule',
        '会议' => 'meeting',
        '直播' => 'living',
        '微盘' => 'wedrive',
        '公费电话' => 'dial',
        '家校应用' => 'school-app',
        '微信客服' => 'kf',
        '上下游' => 'chain',
        '邮件' => 'mail',
        '文档' => 'wedoc',
        '基础' => 'base',
        '连接微信' => 'wechat-connect',
        '办公' => 'office',
        '小程序接入对外收款' => 'miniapp-payment',
        '安全管理' => 'security',
        '账号ID' => 'account-id',
        '人事助手' => 'hr',
        '高级功能' => 'advanced',
        '数据与智能专区' => 'data-intelligence',
    ];

    /**
     * 子分类名称到 kebab-case 的映射
     *
     * @var array<string, string>
     */
    private const SUB_NAME_MAP = [
        '成员管理' => 'member',
        '部门管理' => 'department',
        '标签管理' => 'tag',
        '通讯录回调通知' => 'callback',
        '异步导入接口' => 'async-import',
        '异步导出接口' => 'async-export',
        '自定义菜单' => 'menu',
        '接收消息与事件' => 'receive',
        '应用发送消息到群聊会话' => 'group-chat',
        '家校消息推送' => 'school-push',
        '消息推送（原"群机器人"）' => 'webhook',
        '智能机器人' => 'bot',
        '智能表格自动化创建的群聊' => 'smartsheet-chat',
        '网页授权登录' => 'oauth',
        '企业微信Web登录' => 'web-login',
        '二次验证' => 'verify',
        '管理日历' => 'calendar',
        '管理日程' => 'event',
        '回调通知' => 'callback',
        '预约会议高级管理' => 'booking-advanced',
        '会中控制管理' => 'in-meeting',
        '录制管理' => 'recording',
        '会议室连接器（MRA）管理' => 'mra',
        'Rooms会议室管理' => 'rooms',
        '会议布局和背景管理' => 'layout',
        '电话入会（PSTN）管理' => 'pstn',
        '网络研讨会 (Webinar) 管理' => 'webinar',
        '预约会议基础管理' => 'booking-basic',
        '高级功能账号管理' => 'premium-account',
        '会议统计管理' => 'statistics',
        '管理空间' => 'space',
        '管理空间权限' => 'space-acl',
        '管理文件' => 'file',
        '管理文件权限' => 'file-acl',
        '客户管理' => 'customer',
        '客户标签管理' => 'customer-tag',
        '客户群管理' => 'customer-group',
        '离职继承' => 'resign-transfer',
        '统计管理' => 'statistics',
        '消息推送' => 'push',
        '联系我与客户入群方式' => 'contact-way',
        '企业服务人员管理' => 'staff',
        '客户朋友圈' => 'moment',
        '在职继承' => 'onjob-transfer',
        '获客助手' => 'acquisition',
        '接待人员管理' => 'servicer',
        '客服账号管理' => 'account',
        '会话分配与消息收发' => 'session',
        '其他基础信息获取' => 'basic-info',
        '机器人管理' => 'bot',
        '发送邮件' => 'send',
        '获取接收的邮件' => 'receive',
        '管理应用邮箱账号' => 'app-mailbox',
        '管理邮件群组' => 'mail-group',
        '管理公共邮箱' => 'public-mailbox',
        '其他邮件客户端登录设置' => 'client-config',
        '管理文档内容' => 'doc-content',
        '管理表格内容' => 'sheet-content',
        '管理文档' => 'doc-manage',
        '设置文档权限' => 'doc-acl',
        '管理收集表' => 'form',
        '管理智能表格内容' => 'smartsheet',
        '素材管理' => 'media',
        '接收外部数据到智能表格' => 'external-data',
        '操作日志' => 'audit-log',
    ];

    /**
     * 执行命令
     *
     * @return int 命令退出码
     */
    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listModules();
        }

        $moduleId = $this->option('module');
        $force = $this->option('force');

        // 服务端API 下的所有模块
        $query = WecomApiDoc::where('parent_id', 90135)->where('type', 0)->orderBy('doc_id');

        if ($moduleId) {
            $query->where('category_id', $moduleId);
        }

        $modules = $query->get();

        if ($modules->isEmpty()) {
            $this->error('未找到可用模块。');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($modules->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->start();

        $generated = 0;
        foreach ($modules as $module) {
            $bar->setMessage($module->title);

            $result = $this->generateSkill($module, $force);
            if ($result) {
                $generated++;
            }

            $bar->advance();
        }

        $bar->setMessage('完成');
        $bar->finish();
        $this->newLine(2);

        $this->info("生成完成：{$generated}/{$modules->count()} 个模块。");

        return self::SUCCESS;
    }

    /**
     * 列出所有可用模块
     *
     * @return int 命令退出码
     */
    private function listModules(): int
    {
        $modules = WecomApiDoc::where('parent_id', 90135)->where('type', 0)->orderBy('doc_id')->get();

        $this->table(['category_id', '模块名', 'kebab-name', '文档数'], $modules->map(function ($m) {
            $docCount = WecomApiDoc::where('type', 1)->where('status', 1)
                ->where('category_path', 'LIKE', "%{$m->title}%")
                ->count();

            return [
                $m->category_id,
                $m->title,
                self::MODULE_NAME_MAP[$m->title] ?? Str::slug($m->title),
                $docCount,
            ];
        }));

        return self::SUCCESS;
    }

    /**
     * 为单个模块生成 Skill 文件
     *
     * @param  WecomApiDoc  $module  模块记录
     * @param  bool  $force  是否覆盖已有文件
     * @return bool 是否成功生成
     */
    private function generateSkill(WecomApiDoc $module, bool $force): bool
    {
        $kebabName = self::MODULE_NAME_MAP[$module->title] ?? Str::slug($module->title);
        $skillDir = base_path("skills/wecom-{$kebabName}-assistant");
        $refDir = "{$skillDir}/references";

        // 跳过已存在的（除非 --force）
        if (! $force && is_dir($skillDir)) {
            return false;
        }

        if (! is_dir($refDir)) {
            mkdir($refDir, 0755, true);
        }

        // 获取子分类
        $subCategories = WecomApiDoc::where('parent_id', $module->category_id)
            ->where('type', 0)
            ->orderBy('doc_id')
            ->get();

        // 获取直属文档
        $directDocs = WecomApiDoc::where('parent_id', $module->category_id)
            ->where('type', 1)
            ->where('status', 1)
            ->orderBy('doc_id')
            ->get();

        // 生成 reference 文件
        $indexEntries = [];

        // 处理子分类
        foreach ($subCategories as $sub) {
            $docs = WecomApiDoc::where('parent_id', $sub->category_id)
                ->where('type', 1)
                ->where('status', 1)
                ->orderBy('doc_id')
                ->get();

            if ($docs->isEmpty()) {
                continue;
            }

            $subKebab = self::SUB_NAME_MAP[$sub->title] ?? Str::slug($sub->title);

            // 先构建合并内容，检查是否需要拆分
            $mergedContent = $this->buildReferenceFile($module->title, $sub->title, $docs);

            if (strlen($mergedContent) <= self::MAX_REF_FILE_SIZE) {
                // 合并内容未超限，输出为单个文件
                $refFile = "{$kebabName}-{$subKebab}.md";
                file_put_contents("{$refDir}/{$refFile}", $mergedContent);

                $docTitles = $docs->pluck('title')->implode('、');
                $indexEntries[$sub->title][] = "- [{$sub->title}](references/{$refFile}) - {$docTitles}";
            } else {
                // 超限则按文档逐个拆分为独立 reference 文件
                foreach ($docs as $doc) {
                    $docSlug = Str::slug($doc->title) ?: $doc->doc_id;
                    $refFile = "{$kebabName}-{$subKebab}-{$docSlug}.md";
                    $refContent = $this->buildReferenceFile($module->title, $sub->title, collect([$doc]));
                    file_put_contents("{$refDir}/{$refFile}", $refContent);

                    $indexEntries[$sub->title][] = "- [{$doc->title}](references/{$refFile}) - {$doc->title}";
                }
            }
        }

        // 处理直属文档（归入"通用"组）
        if ($directDocs->isNotEmpty()) {
            $mergedContent = $this->buildReferenceFile($module->title, '通用', $directDocs);

            if (strlen($mergedContent) <= self::MAX_REF_FILE_SIZE) {
                $refFile = "{$kebabName}-general.md";
                file_put_contents("{$refDir}/{$refFile}", $mergedContent);

                $docTitles = $directDocs->pluck('title')->implode('、');
                $indexEntries['通用'][] = "- [通用](references/{$refFile}) - {$docTitles}";
            } else {
                foreach ($directDocs as $doc) {
                    $docSlug = Str::slug($doc->title) ?: $doc->doc_id;
                    $refFile = "{$kebabName}-general-{$docSlug}.md";
                    $refContent = $this->buildReferenceFile($module->title, '通用', collect([$doc]));
                    file_put_contents("{$refDir}/{$refFile}", $refContent);

                    $indexEntries['通用'][] = "- [{$doc->title}](references/{$refFile}) - {$doc->title}";
                }
            }
        }

        // 生成 SKILL.md
        $skillContent = $this->buildSkillMd($module->title, $kebabName, $indexEntries);
        file_put_contents("{$skillDir}/SKILL.md", $skillContent);

        // 链接通用错误码文件（如果不是 contacts 模块）
        $errorCodesSource = base_path('skills/wecom-contacts-assistant/references/error-codes.md');
        $errorCodesDest = "{$refDir}/error-codes.md";
        if (file_exists($errorCodesSource) && ! file_exists($errorCodesDest)) {
            copy($errorCodesSource, $errorCodesDest);
        }

        return true;
    }

    /**
     * 构建 reference 文件内容
     * 从每个文档的 parsed_content 中提取关键信息，组合成精简的参考文档
     *
     * @param  string  $moduleName  模块名称
     * @param  string  $subName  子分类名称
     * @param  \Illuminate\Support\Collection  $docs  文档集合
     * @return string reference 文件内容
     */
    private function buildReferenceFile(string $moduleName, string $subName, $docs): string
    {
        $lines = ["# {$moduleName} - {$subName}", ''];
        $lines[] = '## 接口清单';

        foreach ($docs as $doc) {
            $lines[] = "- {$doc->title}";
        }

        $lines[] = '';

        foreach ($docs as $doc) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = "## {$doc->title}";
            $lines[] = '';

            $content = $doc->parsed_content ?? '';
            if (empty($content)) {
                $lines[] = '> 文档内容暂未抓取';
                $lines[] = '';

                continue;
            }

            // 提取精简内容
            $lines[] = $this->condenseApiDoc($content);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 精简 API 文档内容
     * 保留关键信息：接口地址、参数表、请求/返回示例、权限和注意事项
     * 去除冗余的第三方应用说明和重复内容
     *
     * @param  string  $content  原始 parsed_content
     * @return string 精简后的内容
     */
    private function condenseApiDoc(string $content): string
    {
        // 去除文档开头的安全升级公告（多个段落）
        $content = preg_replace(
            '/^(企业通讯录安全特别重要.*?\n|应用只能获取可见范围内的成员信息.*?\n)+/s',
            '',
            $content
        );

        // 去除 "!!#ff0000 【重要】!!" 类标记
        $content = preg_replace('/\*\*!!#\w+\s*(.*?)!!\*\*/', '**$1**', $content);

        // 移除 [TOC] 标记
        $content = str_replace('[TOC]', '', $content);

        // 移除 <style>...</style> 块及其内容
        $content = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $content);

        // 移除 <img> 标签，保留 alt 文本（如有）
        $content = preg_replace('/<img\s[^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/i', '$1', $content);
        $content = preg_replace('/<img[^>]*\/?>/i', '', $content);

        // 替换 HTML 实体
        $content = str_replace(['&nbsp;', '&lt;', '&gt;', '&amp;'], [' ', '<', '>', '&'], $content);

        // 去除 <br> <font> 等 HTML 标签
        $content = preg_replace('/<br\s*\/?>/', "\n", $content);
        $content = preg_replace('/<\/?font[^>]*>/', '', $content);
        $content = preg_replace('/<\/br>/', '', $content);

        // 移除残留 HTML 标签（div, span, p, a, button, table 等），保留纯文本内容
        $content = preg_replace('/<\/?(div|span|p|a|button|table|thead|tbody|tr|td|th|ul|ol|li|section|article|header|footer|nav|pre|code|em|strong|b|i|u|h[1-6])[^>]*>/i', '', $content);

        // 精简过长的参数说明（去除第三方应用相关的冗余描述）
        $content = preg_replace(
            '/[；;]第三方[^|]*(?=\|)/u',
            '',
            $content
        );
        $content = preg_replace(
            '/[；;]代开发自建应用[^|]*(?=\|)/u',
            '',
            $content
        );
        $content = preg_replace(
            '/[；;]上游企业[^|]*(?=\|)/u',
            '',
            $content
        );

        // 去除 oauth2 授权获取的说明
        $content = preg_replace(
            '/[，,]?代开发自建应用需要.*?获取/u',
            '',
            $content
        );

        // 去除"应用获取敏感字段的说明"整段
        $content = preg_replace(
            '/###\s*应用获取敏感字段的说明.*$/s',
            '',
            $content
        );

        // 去除文档开头的空行
        $content = ltrim($content, "\n\r");

        // 压缩连续空行为最多两个
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    /**
     * 构建 SKILL.md 文件内容
     *
     * @param  string  $moduleName  模块中文名
     * @param  string  $kebabName  kebab-case 名
     * @param  array  $indexEntries  索引条目（按分组）
     * @return string SKILL.md 内容
     */
    private function buildSkillMd(string $moduleName, string $kebabName, array $indexEntries): string
    {
        $lines = [
            '---',
            "name: wecom-{$kebabName}-assistant",
            'description: >',
            "  企业微信{$moduleName}助手。",
            "  Use when user mentions \"{$moduleName}\" 或相关操作。",
            'metadata:',
            '  author: hossy',
            '  version: 1.0.0',
            '  mcp-server: wecom-server',
            "  category: {$kebabName}",
            "  tags: [{$kebabName}, wecom]",
            '---',
            '',
            "# 企业微信{$moduleName}助手",
            '',
            '## 核心指令',
            '',
            "- 执行{$moduleName}相关操作前，确认用户意图",
            '- 修改和删除操作需向用户确认后再执行',
            '- access_token 由系统内部处理，不要暴露给用户',
            '- 接口调用失败时，根据 errcode 查阅错误码表给出具体原因',
            '',
            '## 工作流程',
            '',
            '### 步骤1: 理解意图',
            "分析用户指令，判断{$moduleName}的具体操作类型。",
            '',
            '### 步骤2: 参数校验与确认',
            '校验必填参数，向用户确认操作内容。',
            '',
            '### 步骤3: 执行操作',
            '调用对应接口，解析返回结果。失败时根据 errcode 给出建议。',
            '',
            '### 步骤4: 结果反馈',
            '将操作结果以用户友好的方式呈现。',
            '',
            '## API 参考文档索引',
            '',
        ];

        // 将错误码条目追加到"通用"组，避免重复 header
        $errorCodeEntry = '- [全局错误码](references/error-codes.md) - 企业微信 API 全局 errcode 对照表，接口调用失败时查阅';
        if (isset($indexEntries['通用'])) {
            $indexEntries['通用'][] = $errorCodeEntry;
        } else {
            $indexEntries['通用'] = [$errorCodeEntry];
        }

        foreach ($indexEntries as $group => $entries) {
            $lines[] = "### {$group}";
            foreach ($entries as $entry) {
                $lines[] = $entry;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
