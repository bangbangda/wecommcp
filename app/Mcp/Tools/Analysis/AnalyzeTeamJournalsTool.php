<?php

namespace App\Mcp\Tools\Analysis;

use App\Models\Contact;
use App\Models\JournalTemplate;
use App\Services\JournalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('analyze_team_journals')]
#[Description('分析团队汇报内容，生成工作概要、重点关注事项和管理建议。面向部门领导，自动获取发送给当前用户的所有下属汇报进行 AI 分析。支持按汇报类型筛选（daily=日报, weekly=周报, monthly=月报），不传则分析所有类型。典型场景："帮我看看团队这周的日报""大家最近的汇报怎么样""团队有什么需要我关注的"。此工具分析汇报内容，如需查看提交统计（谁交了、谁没交），请使用 query_journal_stats。仅分析发送给当前用户的汇报，不能查看其他领导收到的汇报。')]
class AnalyzeTeamJournalsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'userid' => $schema->string('当前用户（领导）的 userid')->required(),
            'report_type' => $schema->string('汇报类型筛选：daily=日报, weekly=周报, monthly=月报, 不传则分析所有类型'),
            'start_date' => $schema->string('开始日期（Y-m-d），默认最近7天'),
            'end_date' => $schema->string('结束日期（Y-m-d），默认今天'),
        ];
    }

    /**
     * 分析团队汇报
     * 通过 receivers 字段找到所有发给当前用户的汇报，调用 AI 分析
     */
    public function handle(Request $request, JournalService $service): Response
    {
        $data = $request->validate([
            'userid' => 'required|string',
            'report_type' => 'nullable|string|in:daily,weekly,monthly',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $userId = $data['userid'];
        $endDate = $data['end_date'] ?? Carbon::now('Asia/Shanghai')->format('Y-m-d');
        $startDate = $data['start_date'] ?? Carbon::now('Asia/Shanghai')->subDays(7)->format('Y-m-d');

        Log::debug('AnalyzeTeamJournalsTool::handle 收到请求', $data);

        // 按汇报类型获取 template_id
        $templateId = null;
        if (! empty($data['report_type'])) {
            $template = JournalTemplate::where('report_type', $data['report_type'])
                ->where('is_active', true)
                ->first();
            $templateId = $template?->template_id;

            if (! $templateId) {
                return Response::text(json_encode([
                    'status' => 'error',
                    'message' => "未找到类型为「{$data['report_type']}」的汇报模板配置",
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        // 获取发给当前用户的汇报
        $journals = $service->getJournalsForReceiver($userId, $startDate, $endDate, $templateId);

        if ($journals->isEmpty()) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => "在 {$startDate} 至 {$endDate} 期间，没有收到团队的汇报记录。请先运行 journal:sync 同步数据。",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析领导姓名
        $leaderName = Contact::where('userid', $userId)->value('name') ?? $userId;

        // AI 分析
        $analysis = $service->analyzeTeamJournals($leaderName, $journals, $startDate, $endDate);

        if ($analysis === null) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => 'AI 分析失败，请稍后重试',
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'success',
            'period' => "{$startDate} 至 {$endDate}",
            'journals_count' => $journals->count(),
            'submitters_count' => $journals->pluck('submitter_userid')->unique()->count(),
            'analysis' => $analysis,
        ], JSON_UNESCAPED_UNICODE));
    }
}
