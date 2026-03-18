<?php

namespace App\Mcp\Tools\Analysis;

use App\Models\JournalRecord;
use App\Models\JournalTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_journal_stats')]
#[Description('查询团队汇报提交统计。查看谁已提交、谁未提交、提交率等。面向部门领导，基于汇报接收人关系自动识别团队成员。典型场景："谁还没交日报""这周的汇报提交情况""看看团队的汇报统计"。')]
class QueryJournalStatsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'userid' => $schema->string('当前用户（领导）的 userid')->required(),
            'report_type' => $schema->string('汇报类型：daily=日报, weekly=周报, monthly=月报'),
            'date' => $schema->string('查询日期（Y-m-d），默认今天。查日报用具体日期，查周报用本周内任意日期'),
        ];
    }

    /**
     * 查询汇报提交统计
     * 通过历史汇报的 receivers 关系推断团队成员，对比已提交情况
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'userid' => 'required|string',
            'report_type' => 'nullable|string|in:daily,weekly,monthly',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $userId = $data['userid'];
        $date = $data['date'] ?? Carbon::now('Asia/Shanghai')->format('Y-m-d');

        Log::debug('QueryJournalStatsTool::handle 收到请求', $data);

        // 确定查询的时间范围
        $reportType = $data['report_type'] ?? 'daily';
        [$startDate, $endDate] = $this->getDateRange($date, $reportType);

        // 获取 template_id
        $templateId = null;
        $template = JournalTemplate::where('report_type', $reportType)
            ->where('is_active', true)
            ->first();
        $templateId = $template?->template_id;

        // 查询该时间段内发给当前用户的汇报
        $query = JournalRecord::forReceiver($userId)
            ->where('report_time', '>=', $startDate.' 00:00:00')
            ->where('report_time', '<=', $endDate.' 23:59:59');

        if ($templateId) {
            $query->where('template_id', $templateId);
        }

        $submitted = $query->get();

        // 推断团队成员：从近 30 天的汇报记录中，找出所有曾给当前用户发过汇报的人
        $teamMembers = JournalRecord::forReceiver($userId)
            ->where('report_time', '>=', Carbon::now('Asia/Shanghai')->subDays(30)->format('Y-m-d').' 00:00:00')
            ->select('submitter_userid', 'submitter_name')
            ->distinct()
            ->get();

        $submittedUserids = $submitted->pluck('submitter_userid')->unique()->toArray();
        $allMemberUserids = $teamMembers->pluck('submitter_userid')->unique()->toArray();
        $notSubmittedUserids = array_diff($allMemberUserids, $submittedUserids);

        // 组装结果
        $submittedList = $submitted->map(fn ($j) => [
            'name' => $j->submitter_name ?: $j->submitter_userid,
            'report_time' => $j->report_time->format('Y-m-d H:i'),
            'template_name' => $j->template_name,
        ])->values()->toArray();

        $notSubmittedList = [];
        foreach ($notSubmittedUserids as $uid) {
            $name = $teamMembers->firstWhere('submitter_userid', $uid)?->submitter_name ?? $uid;
            $notSubmittedList[] = ['name' => $name, 'userid' => $uid];
        }

        $totalMembers = count($allMemberUserids);
        $submittedCount = count($submittedUserids);
        $rate = $totalMembers > 0 ? round($submittedCount / $totalMembers * 100) : 0;

        $typeLabel = match ($reportType) {
            'daily' => '日报',
            'weekly' => '周报',
            'monthly' => '月报',
            default => '汇报',
        };

        return Response::text(json_encode([
            'status' => 'success',
            'report_type' => $typeLabel,
            'period' => "{$startDate} 至 {$endDate}",
            'stats' => [
                'total_members' => $totalMembers,
                'submitted' => $submittedCount,
                'not_submitted' => count($notSubmittedUserids),
                'submit_rate' => "{$rate}%",
            ],
            'submitted_list' => $submittedList,
            'not_submitted_list' => $notSubmittedList,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 根据汇报类型确定查询时间范围
     */
    private function getDateRange(string $date, string $reportType): array
    {
        $carbon = Carbon::parse($date, 'Asia/Shanghai');

        return match ($reportType) {
            'weekly' => [
                $carbon->copy()->startOfWeek()->format('Y-m-d'),
                $carbon->copy()->endOfWeek()->format('Y-m-d'),
            ],
            'monthly' => [
                $carbon->copy()->startOfMonth()->format('Y-m-d'),
                $carbon->copy()->endOfMonth()->format('Y-m-d'),
            ],
            default => [$date, $date], // daily
        };
    }
}
