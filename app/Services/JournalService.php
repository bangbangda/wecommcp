<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Exceptions\WecomApiException;
use App\Models\Contact;
use App\Models\JournalRecord;
use App\Models\JournalTemplate;
use App\Services\ChatAnalysis\AnalysisConfigService;
use App\Wecom\WecomJournalClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 汇报业务服务
 * 负责拉取汇报数据、解析内容、AI 分析
 */
class JournalService
{
    public function __construct(
        private WecomJournalClient $client,
        private AiManager $aiManager,
        private AnalysisConfigService $config,
    ) {}

    /**
     * 同步指定日期范围内的汇报记录
     * 拉取所有启用模板的汇报，逐条获取详情并存储
     *
     * @param  string  $startDate  开始日期（Y-m-d）
     * @param  string  $endDate  结束日期（Y-m-d）
     * @return array{synced: int, skipped: int} 同步统计
     */
    public function syncRecords(string $startDate, string $endDate): array
    {
        $templates = JournalTemplate::where('is_active', true)->get();

        if ($templates->isEmpty()) {
            Log::warning('JournalService::syncRecords 无启用的汇报模板');

            return ['synced' => 0, 'skipped' => 0];
        }

        $startTime = Carbon::parse($startDate, 'Asia/Shanghai')->startOfDay()->timestamp;
        $endTime = Carbon::parse($endDate, 'Asia/Shanghai')->endOfDay()->timestamp;

        Log::info('JournalService::syncRecords 开始同步', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'templates' => $templates->pluck('template_name', 'template_id'),
        ]);

        $totalSynced = 0;
        $totalSkipped = 0;

        foreach ($templates as $template) {
            $result = $this->syncByTemplate($template->template_id, $startTime, $endTime);
            $totalSynced += $result['synced'];
            $totalSkipped += $result['skipped'];
        }

        Log::info('JournalService::syncRecords 同步完成', [
            'synced' => $totalSynced,
            'skipped' => $totalSkipped,
        ]);

        return ['synced' => $totalSynced, 'skipped' => $totalSkipped];
    }

    /**
     * 按模板拉取汇报记录
     */
    private function syncByTemplate(string $templateId, int $startTime, int $endTime): array
    {
        $synced = 0;
        $skipped = 0;
        $cursor = 0;

        $filters = [['key' => 'template_id', 'value' => $templateId]];

        do {
            try {
                $result = $this->client->getRecordList($startTime, $endTime, $cursor, 100, $filters);
            } catch (WecomApiException $e) {
                Log::error("JournalService::syncByTemplate 获取列表失败 [{$templateId}]", [
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            foreach ($result['journaluuid_list'] as $uuid) {
                // 已存在则跳过
                if (JournalRecord::where('journal_uuid', $uuid)->exists()) {
                    $skipped++;

                    continue;
                }

                $this->syncSingleRecord($uuid);
                $synced++;
            }

            $cursor = $result['next_cursor'];
        } while ($result['endflag'] === 0);

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    /**
     * 拉取并存储单条汇报详情
     */
    private function syncSingleRecord(string $journalUuid): void
    {
        try {
            $detail = $this->client->getRecordDetail($journalUuid);
        } catch (WecomApiException $e) {
            Log::warning("JournalService::syncSingleRecord 获取详情失败 [{$journalUuid}]", [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($detail)) {
            return;
        }

        $submitterUserid = $detail['submitter']['userid'] ?? '';
        $submitterName = $this->resolveUserName($submitterUserid);

        // 提取接收人 userid 列表
        $receivers = collect($detail['receivers'] ?? [])->pluck('userid')->filter()->values()->toArray();

        // 解析汇报内容为纯文本
        $content = $this->parseContent($detail);

        JournalRecord::create([
            'journal_uuid' => $journalUuid,
            'template_id' => $detail['template_id'] ?? '',
            'template_name' => $detail['template_name'] ?? '',
            'submitter_userid' => $submitterUserid,
            'submitter_name' => $submitterName,
            'report_time' => isset($detail['report_time']) ? Carbon::createFromTimestamp($detail['report_time']) : null,
            'content' => $content,
            'raw_apply_data' => $detail['apply_data'] ?? null,
            'receivers' => $receivers,
            'comments_count' => count($detail['comments'] ?? []),
            'created_at' => now(),
        ]);
    }

    /**
     * 解析汇报详情为纯文本
     * 支持 apply_data 表单模式和 sys_journal_data 富文本模式
     *
     * @param  array  $detail  汇报详情
     * @return string 纯文本内容
     */
    private function parseContent(array $detail): string
    {
        // 特殊模板使用富文本字段
        if (! empty($detail['sys_journal_data'])) {
            return $this->cleanHtml($detail['sys_journal_data']);
        }

        $contents = $detail['apply_data']['contents'] ?? [];
        $parts = [];

        foreach ($contents as $item) {
            $title = $this->extractTitle($item);
            $value = $this->extractValue($item);

            if (! empty($value)) {
                $parts[] = ! empty($title) ? "{$title}：{$value}" : $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * 提取控件标题
     */
    private function extractTitle(array $item): string
    {
        $title = $item['title'] ?? [];
        if (is_array($title)) {
            foreach ($title as $t) {
                if (! empty($t['text'])) {
                    return $t['text'];
                }
            }

            return '';
        }

        return (string) $title;
    }

    /**
     * 提取控件值为纯文本
     * 支持所有企微汇报控件类型
     */
    private function extractValue(array $item): string
    {
        $control = $item['control'] ?? '';
        $value = $item['value'] ?? [];

        return match ($control) {
            'Text', 'Textarea' => $this->cleanHtml($value['text'] ?? ''),
            'Number' => $value['new_number'] ?? '',
            'Money' => $value['new_money'] ?? '',
            'Date' => $this->formatDate($value['date'] ?? []),
            'Selector' => $this->formatSelector($value['selector'] ?? []),
            'Contact' => $this->formatContact($value),
            'Location' => $this->formatLocation($value['location'] ?? []),
            'File' => '[附件 '.count($value['files'] ?? []).' 个]',
            'WedriveFile' => '[微盘文件 '.count($value['wedrive_files'] ?? []).' 个]',
            'Formula' => $value['formula']['value'] ?? '',
            'DateRange' => $this->formatDateRange($value),
            'Table' => $this->formatTable($value),
            'Doc' => $this->formatDocs($value['docs'] ?? []),
            default => '',
        };
    }

    /**
     * 清理 HTML 标签，转为干净的纯文本
     * 将 div/br/p 转换为换行，去除其余标签
     */
    private function cleanHtml(string $text): string
    {
        // 将块级标签和 br 转为换行
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(div|p|li)>/i', "\n", $text);
        // 去除剩余标签
        $text = strip_tags($text);
        // 清理多余空行
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * 格式化日期控件
     */
    private function formatDate(array $date): string
    {
        $timestamp = $date['s_timestamp'] ?? '';
        if (empty($timestamp)) {
            return '';
        }

        return Carbon::createFromTimestamp((int) $timestamp)->format('Y-m-d H:i');
    }

    /**
     * 格式化选择器控件
     */
    private function formatSelector(array $selector): string
    {
        $options = $selector['options'] ?? [];
        $texts = [];

        foreach ($options as $option) {
            foreach ($option['value'] ?? [] as $v) {
                if (! empty($v['text'])) {
                    $texts[] = $v['text'];
                }
            }
        }

        return implode(', ', $texts);
    }

    /**
     * 格式化联系人控件
     */
    private function formatContact(array $value): string
    {
        $parts = [];

        foreach ($value['members'] ?? [] as $member) {
            $parts[] = $this->resolveUserName($member['userid'] ?? '');
        }

        foreach ($value['departments'] ?? [] as $dept) {
            $parts[] = '部门#'.($dept['openapi_id'] ?? '');
        }

        return implode(', ', $parts);
    }

    /**
     * 格式化位置控件
     */
    private function formatLocation(array $location): string
    {
        return ($location['title'] ?? '').' '.($location['address'] ?? '');
    }

    /**
     * 格式化时长控件
     */
    private function formatDateRange(array $value): string
    {
        $begin = $value['date_range']['new_begin'] ?? 0;
        $end = $value['date_range']['new_end'] ?? 0;
        $duration = $value['date_range']['new_duration'] ?? 0;

        if ($begin && $end) {
            $beginStr = Carbon::createFromTimestamp($begin)->format('m/d H:i');
            $endStr = Carbon::createFromTimestamp($end)->format('m/d H:i');
            $hours = round($duration / 3600, 1);

            return "{$beginStr} ~ {$endStr}（{$hours}小时）";
        }

        return '';
    }

    /**
     * 格式化明细表格控件（递归解析子控件）
     */
    private function formatTable(array $value): string
    {
        $rows = [];

        foreach ($value['children'] ?? [] as $child) {
            $cells = [];
            foreach ($child['list'] ?? [] as $subItem) {
                $subTitle = $this->extractTitle($subItem);
                $subValue = $this->extractValue($subItem);
                if (! empty($subValue)) {
                    $cells[] = "{$subTitle}={$subValue}";
                }
            }
            if (! empty($cells)) {
                $rows[] = implode(', ', $cells);
            }
        }

        return implode('; ', $rows);
    }

    /**
     * 格式化文档控件
     */
    private function formatDocs(array $docs): string
    {
        return implode(', ', array_map(fn ($d) => $d['doc_url'] ?? '', $docs));
    }

    /**
     * 解析用户姓名
     */
    private function resolveUserName(string $userid): string
    {
        if (empty($userid)) {
            return '';
        }

        $contact = Contact::where('userid', $userid)->first();

        return $contact->name ?? $userid;
    }

    /**
     * 获取指定接收人在指定时间范围内收到的汇报
     *
     * @param  string  $receiverUserid  接收人 userid
     * @param  string  $startDate  开始日期
     * @param  string  $endDate  结束日期
     * @param  string|null  $templateId  可选模板过滤
     * @return Collection 汇报记录集合
     */
    public function getJournalsForReceiver(string $receiverUserid, string $startDate, string $endDate, ?string $templateId = null): Collection
    {
        $query = JournalRecord::forReceiver($receiverUserid)
            ->where('report_time', '>=', $startDate.' 00:00:00')
            ->where('report_time', '<=', $endDate.' 23:59:59');

        if ($templateId) {
            $query->where('template_id', $templateId);
        }

        return $query->orderBy('report_time')->get();
    }

    /**
     * AI 分析团队汇报（面向领导视角）
     *
     * @param  string  $leaderName  领导姓名
     * @param  Collection  $journals  汇报记录集合
     * @param  string  $startDate  时间范围
     * @param  string  $endDate  时间范围
     * @return string|null AI 分析结果
     */
    public function analyzeTeamJournals(string $leaderName, Collection $journals, string $startDate, string $endDate): ?string
    {
        $systemPrompt = <<<'PROMPT'
你是一个专业的团队管理分析助手，帮助部门领导快速了解团队汇报情况。

报告分为两部分：快速概览和详细分析。

== 快速概览 ==

团队状态：（一句话概括团队当前工作状态）
需要关注：（最需要领导关注的 1-2 件事，如风险、阻塞、需要支持的事项；没有则写"暂无异常"）
汇报统计：（已汇报 N 人，内容概况简述）

== 详细分析 ==

1. 团队工作概要
   按人汇总每人这段时间的主要工作内容，简明扼要

2. 重点关注事项
   从汇报中识别：项目风险、进度阻塞、需要领导支持或决策的事项、资源不足等
   如果没有需要关注的问题，也要说明"团队运转正常"

3. 汇报质量评估
   评估整体汇报质量：内容是否具体有实质（而非流水账）、是否包含进展和成果、是否有明确的计划
   指出质量较好和需要改进的具体情况

4. 管理建议
   基于汇报内容给出建议：哪些人需要 1on1 沟通、哪些项目需要重点关注、团队层面有什么需要调整的

要求：
- 使用纯文本格式，不要使用 markdown
- 不要使用 emoji
- 语气专业简洁
- 站在部门领导的视角分析
- 快速概览要精炼，让人 10 秒内了解全局
PROMPT;

        // 组装用户消息
        $parts = [];
        $parts[] = "分析对象：{$leaderName}收到的团队汇报";
        $parts[] = "时间范围：{$startDate} 至 {$endDate}";
        $parts[] = "汇报份数：{$journals->count()} 份";

        // 按人分组展示
        $grouped = $journals->groupBy('submitter_name');
        $parts[] = "\n## 各成员汇报内容";

        foreach ($grouped as $name => $personJournals) {
            $parts[] = "\n--- {$name} ({$personJournals->count()}份) ---";
            foreach ($personJournals as $journal) {
                $time = $journal->report_time->format('m/d H:i');
                $parts[] = "[{$time}] {$journal->template_name}";
                $parts[] = $journal->content;
                $parts[] = '';
            }
        }

        $userMessage = implode("\n", $parts);

        Log::info('JournalService::analyzeTeamJournals 调用 AI', [
            'journals_count' => $journals->count(),
            'submitters' => $grouped->keys()->toArray(),
        ]);

        $driver = $this->config->getAiDriver();
        $response = $this->aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
        );

        if (! $response || empty($response->text)) {
            Log::error('JournalService::analyzeTeamJournals AI 返回为空');

            return null;
        }

        return trim($response->text);
    }
}
