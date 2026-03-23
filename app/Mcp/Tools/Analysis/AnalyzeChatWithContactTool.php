<?php

namespace App\Mcp\Tools\Analysis;

use App\Ai\AiManager;
use App\Models\ChatAnalysisSummary;
use App\Models\Contact;
use App\Models\ExternalContact;
use App\Services\ChatAnalysis\AnalysisConfigService;
use App\Services\ChatAnalysis\MessageCollector;
use App\Services\ContactsService;
use App\Services\ExternalContactService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('analyze_chat_with_contact')]
#[Description('分析当前用户与指定联系人的一对一聊天内容，生成沟通概要和跟进建议。自动识别联系人身份（内部同事/外部客户），采用不同分析视角。联系人可传姓名（自动搜索匹配）或直接传 userid；如之前已通过 search_contacts 或 search_external_contacts 获取到 userid，可直接传入 contact_userid 跳过搜索。典型场景："总结我和张总的聊天""分析我和客户王总最近的沟通""我应该怎么跟进和李四的联系"。仅分析一对一聊天记录，不适用于群聊分析或工作日总结（工作总结请使用 get_daily_work_summary）。')]
class AnalyzeChatWithContactTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'userid' => $schema->string('当前用户的 userid')->required(),
            'contact_name' => $schema->string('要分析的联系人姓名（会自动搜索匹配）'),
            'contact_userid' => $schema->string('联系人的 userid 或 external_userid（如已知可直接传入，跳过搜索）'),
            'start_date' => $schema->string('分析起始日期（Y-m-d），默认最近7天'),
            'end_date' => $schema->string('分析结束日期（Y-m-d），默认今天'),
        ];
    }

    /**
     * 分析用户与指定联系人的聊天内容
     * 自动识别联系人类型，采用不同分析视角
     */
    public function handle(
        Request $request,
        AiManager $aiManager,
        AnalysisConfigService $config,
        MessageCollector $collector,
    ): Response {
        $data = $request->validate([
            'userid' => 'required|string',
            'contact_name' => 'nullable|string',
            'contact_userid' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $userId = $data['userid'];
        $endDate = $data['end_date'] ?? Carbon::now('Asia/Shanghai')->format('Y-m-d');
        $startDate = $data['start_date'] ?? Carbon::now('Asia/Shanghai')->subDays(7)->format('Y-m-d');

        Log::debug('AnalyzeChatWithContactTool::handle 收到请求', $data);

        // 1. 解析联系人（含身份类型）
        $contactResult = $this->resolveContact($data['contact_userid'] ?? null, $data['contact_name'] ?? null);

        if ($contactResult['status'] !== 'found') {
            return Response::text(json_encode($contactResult, JSON_UNESCAPED_UNICODE));
        }

        $contactUserid = $contactResult['userid'];
        $contactName = $contactResult['name'];
        $contactType = $contactResult['contact_type']; // internal / external

        // 2. 查询 Layer 1 摘要
        $summaries = $this->getSummaries($userId, $contactUserid, $startDate, $endDate);

        // 3. 如果摘要不足，回溯 Layer 0 原始记录补充
        $rawConversations = '';
        if ($summaries->isEmpty()) {
            $rawConversations = $this->getRawConversations($userId, $contactUserid, $startDate, $endDate, $collector);

            if (empty($rawConversations)) {
                return Response::text(json_encode([
                    'status' => 'empty',
                    'message' => "在 {$startDate} 至 {$endDate} 期间，未找到你与{$contactName}的聊天记录",
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        // 4. 根据联系人类型调用不同视角的 AI 分析
        $analysis = $this->callAiAnalysis(
            $aiManager, $config,
            $userId, $contactName, $contactType,
            $startDate, $endDate,
            $summaries, $rawConversations,
        );

        if ($analysis === null) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => 'AI 分析失败，请稍后重试',
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'success',
            'contact_name' => $contactName,
            'contact_type' => $contactType === 'external' ? '外部客户' : '内部同事',
            'period' => "{$startDate} 至 {$endDate}",
            'analysis' => $analysis,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 解析联系人，返回 userid、姓名和类型（internal/external）
     * 复用 ContactsService 和 ExternalContactService 的四级匹配策略
     */
    private function resolveContact(?string $contactUserid, ?string $contactName): array
    {
        // 直接传入 userid
        if (! empty($contactUserid)) {
            $contact = Contact::where('userid', $contactUserid)->first();
            if ($contact) {
                return ['status' => 'found', 'userid' => $contact->userid, 'name' => $contact->name, 'contact_type' => 'internal'];
            }
            $external = ExternalContact::where('external_userid', $contactUserid)->first();
            if ($external) {
                return ['status' => 'found', 'userid' => $external->external_userid, 'name' => $external->name, 'contact_type' => 'external'];
            }

            return ['status' => 'not_found', 'message' => "未找到 userid 为「{$contactUserid}」的联系人"];
        }

        // 按姓名搜索（使用四级匹配策略：精确→拼音→首字母→模糊）
        if (empty($contactName)) {
            return ['status' => 'not_found', 'message' => '请提供联系人姓名或 userid'];
        }

        $contacts = app(ContactsService::class)->searchByName($contactName);
        $externals = app(ExternalContactService::class)->searchByName($contactName);

        $all = collect();
        foreach ($contacts as $c) {
            $all->push(['userid' => $c->userid, 'name' => $c->name, 'contact_type' => 'internal', 'label' => "内部 | {$c->department}"]);
        }
        foreach ($externals as $e) {
            $all->push(['userid' => $e->external_userid, 'name' => $e->name, 'contact_type' => 'external', 'label' => "外部客户 | {$e->corp_name}"]);
        }

        if ($all->isEmpty()) {
            return ['status' => 'not_found', 'message' => "未找到与「{$contactName}」匹配的联系人"];
        }

        if ($all->count() === 1) {
            $match = $all->first();

            return ['status' => 'found', 'userid' => $match['userid'], 'name' => $match['name'], 'contact_type' => $match['contact_type']];
        }

        return [
            'status' => 'need_clarification',
            'message' => "找到多个匹配「{$contactName}」的联系人，请确认是哪位",
            'candidates' => $all->values()->toArray(),
        ];
    }

    /**
     * 查询 Layer 1 对话摘要
     */
    private function getSummaries(string $userA, string $userB, string $startDate, string $endDate): Collection
    {
        $pair = [$userA, $userB];
        sort($pair);

        return ChatAnalysisSummary::where('user_a', $pair[0])
            ->where('user_b', $pair[1])
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate)
            ->whereNotNull('raw_analysis')
            ->orderBy('date')
            ->get();
    }

    /**
     * 回溯 Layer 0 原始聊天记录
     */
    private function getRawConversations(
        string $userId,
        string $contactUserid,
        string $startDate,
        string $endDate,
        MessageCollector $collector,
    ): string {
        $parts = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            $date = $current->format('Y-m-d');
            $records = $collector->collectByDate($date);

            $filtered = $records->filter(function ($r) use ($userId, $contactUserid) {
                $from = $r->from;
                $to = is_array($r->to) ? ($r->to[0] ?? '') : $r->to;

                return ($from === $userId && $to === $contactUserid)
                    || ($from === $contactUserid && $to === $userId);
            });

            if ($filtered->isNotEmpty()) {
                $nameMap = $collector->resolveNames($filtered);
                $formatted = $collector->formatConversation($filtered, $nameMap);
                $parts[] = "--- {$date} ---\n{$formatted}";
            }

            $current->addDay();
        }

        return implode("\n\n", $parts);
    }

    /**
     * 根据联系人类型调用不同视角的 AI 分析
     *
     * @param  string  $contactType  联系人类型：internal / external
     */
    private function callAiAnalysis(
        AiManager $aiManager,
        AnalysisConfigService $config,
        string $userId,
        string $contactName,
        string $contactType,
        string $startDate,
        string $endDate,
        Collection $summaries,
        string $rawConversations,
    ): ?string {
        $systemPrompt = $contactType === 'external'
            ? $this->buildExternalPrompt()
            : $this->buildInternalPrompt();

        // 组装用户消息
        $parts = [];
        $typeLabel = $contactType === 'external' ? '（外部客户）' : '（内部同事）';
        $parts[] = "分析对象：我与{$contactName}{$typeLabel}的沟通记录";
        $parts[] = "时间范围：{$startDate} 至 {$endDate}";

        if ($summaries->isNotEmpty()) {
            $parts[] = "\n## 已有的对话摘要（按天）";
            foreach ($summaries as $summary) {
                $parts[] = "【{$summary->date->format('m/d')}】({$summary->message_count}条消息)";
                $parts[] = $summary->summary;

                $insights = $summary->raw_analysis['new_insights'] ?? [];
                $todoCount = count($insights['todos'] ?? []);
                $decisionCount = count($insights['decisions'] ?? []);
                if ($todoCount > 0 || $decisionCount > 0) {
                    $parts[] = "当日提取：{$todoCount}条待办, {$decisionCount}条决策";
                }
                $parts[] = '';
            }
        }

        if (! empty($rawConversations)) {
            $parts[] = "\n## 原始聊天记录";
            $parts[] = $rawConversations;
        }

        $userMessage = implode("\n", $parts);

        Log::info('AnalyzeChatWithContactTool AI 分析', [
            'contact' => $contactName,
            'contact_type' => $contactType,
            'summaries_count' => $summaries->count(),
            'has_raw' => ! empty($rawConversations),
        ]);

        $driver = $config->getAiDriver();
        $response = $aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
        );

        if (! $response || empty($response->text)) {
            Log::error('AnalyzeChatWithContactTool AI 返回为空');

            return null;
        }

        return trim($response->text);
    }

    /**
     * 构建外部客户分析提示词
     * 关注：购买意向、客户需求、跟进时机、销售机会
     */
    private function buildExternalPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的客户关系分析助手。请基于提供的聊天数据，从销售和客户管理的视角生成分析报告。

报告分为两部分：快速概览（让用户一眼知道该做什么）和详细分析（需要时深入查看）。

== 快速概览 ==

客户状态：（从以下选择一个最匹配的）
  初步接触 / 需求沟通中 / 方案评估中 / 等待决策 / 需要跟进 / 持续维护
  状态后附一句话说明当前进展

紧急行动：（最重要的 1-2 条下一步动作，具体到时间和内容）

关注风险：（如果有需要注意的风险点，一句话概括；没有则不写这行）

== 详细分析 ==

1. 客户沟通概要
   简要概括这段时间与客户的主要沟通内容和进展

2. 客户需求识别
   从对话中提取客户表达的需求、关注点、痛点
   区分明确需求（客户直接说出来的）和潜在需求（从对话中推断的）

3. 购买/合作意向分析
   客户对我方产品或服务的态度和兴趣程度
   客户提到的预算、时间计划、决策流程等关键信息
   识别积极信号（如主动询问价格、要求演示、讨论方案细节）
   识别消极信号（如犹豫、对比竞品、延迟决策）

4. 待跟进事项
   列出目前仍需跟进的具体事项（已完成的不要列出）
   标注紧急程度和建议跟进时间

5. 跟进策略建议
   基于以上分析，给出具体的下一步跟进行动建议
   包括：什么时候跟进、用什么方式、聊什么话题、需要准备什么材料
   如果客户有顾虑，建议如何回应

要求：
- 使用纯文本格式，不要使用 markdown
- 不要使用 emoji
- 语气专业简洁
- 站在销售/客户经理的视角分析
- 跟进建议要具体可执行，不要泛泛而谈
- 快速概览要精炼，让人 10 秒内知道该做什么
PROMPT;
    }

    /**
     * 构建内部同事分析提示词
     * 关注：工作协作、待跟进事项、决策进展
     */
    private function buildInternalPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的工作沟通分析助手。请基于提供的聊天数据，生成一份沟通分析报告。

报告分为两部分：快速概览（让用户一眼知道该做什么）和详细分析（需要时深入查看）。

== 快速概览 ==

协作状态：（从以下选择一个最匹配的）
  有待办需跟进 / 协作进行中 / 暂无待办
  状态后附一句话说明当前进展

紧急行动：（最重要的 1-2 条下一步动作；如果暂无待办则写"暂无需要立即处理的事项"）

== 详细分析 ==

1. 沟通概要
   这段时间主要讨论了哪些事情，简要概括

2. 关键话题
   按话题分类列出讨论的主要内容

3. 待跟进事项
   目前仍需要跟进的工作事项（已完成的不要列出）

4. 协作建议
   基于沟通内容，给出后续应该如何推进协作的具体建议

要求：
- 使用纯文本格式，不要使用 markdown
- 不要使用 emoji
- 语气专业简洁
- 从当前用户的视角出发分析
- 待跟进事项只列出确实还未完成的
- 快速概览要精炼，让人 10 秒内知道该做什么
PROMPT;
    }
}
