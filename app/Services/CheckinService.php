<?php

namespace App\Services;

use App\Models\Contact;
use App\Wecom\WecomCheckinClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CheckinService
{
    /** @var array<int, string> 异常类型码 → 中文名称 */
    private const EXCEPTION_TYPES = [
        1 => '迟到',
        2 => '早退',
        3 => '缺卡',
        4 => '旷工',
        5 => '地点异常',
        6 => '设备异常',
    ];

    /** @var array<int, string> 假勤类型码 → 中文名称 */
    private const SP_TYPES = [
        1 => '请假',
        2 => '补卡',
        3 => '出差',
        4 => '外出',
        15 => '审批打卡',
        100 => '外勤',
    ];

    /** @var array<int, int> 异常严重程度排序（数字越大越严重） */
    private const EXCEPTION_SEVERITY = [
        4 => 6, // 旷工
        3 => 5, // 缺卡
        1 => 4, // 迟到
        2 => 3, // 早退
        5 => 2, // 地点异常
        6 => 1, // 设备异常
    ];

    public function __construct(private WecomCheckinClient $checkinClient) {}

    /**
     * 获取日报数据（格式化后）
     *
     * @param  array  $userIds  用户 userid 列表
     * @param  Carbon  $date  查询日期
     * @return array 格式化的日报数据
     */
    public function getDayReport(array $userIds, Carbon $date): array
    {
        $timestamp = $date->copy()->startOfDay()->timestamp;

        Log::debug('CheckinService::getDayReport', ['userIds' => $userIds, 'date' => $date->toDateString()]);

        $rawData = $this->checkinClient->getDayData($userIds, $timestamp, $timestamp);

        return array_map(fn (array $item) => $this->formatDayData($item), $rawData);
    }

    /**
     * 获取月报数据（格式化后）
     *
     * @param  array  $userIds  用户 userid 列表
     * @param  Carbon  $startDate  开始日期
     * @param  Carbon  $endDate  结束日期
     * @return array 格式化的月报数据
     */
    public function getMonthReport(array $userIds, Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate->copy()->startOfDay()->timestamp;
        $end = $endDate->copy()->startOfDay()->timestamp;

        Log::debug('CheckinService::getMonthReport', [
            'userIds' => $userIds,
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
        ]);

        $rawData = $this->checkinClient->getMonthData($userIds, $start, $end);

        return array_map(fn (array $item) => $this->formatMonthData($item), $rawData);
    }

    /**
     * 获取周报数据（基于日报按周聚合）
     *
     * @param  array  $userIds  用户 userid 列表
     * @param  Carbon  $weekStart  周起始日期（周一）
     * @return array 格式化的周报数据
     */
    public function getWeekReport(array $userIds, Carbon $weekStart): array
    {
        $start = $weekStart->copy()->startOfWeek()->startOfDay()->timestamp;
        $end = $weekStart->copy()->endOfWeek()->startOfDay()->timestamp;

        Log::debug('CheckinService::getWeekReport', [
            'userIds' => $userIds,
            'weekStart' => $weekStart->toDateString(),
        ]);

        $rawData = $this->checkinClient->getDayData($userIds, $start, $end);

        // 按用户分组聚合
        $grouped = collect($rawData)->groupBy(fn (array $item) => $item['base_info']['acctid'] ?? '');

        $result = [];
        foreach ($grouped as $userId => $days) {
            $result[] = $this->aggregateWeekData($userId, $days);
        }

        return $result;
    }

    /**
     * 获取异常记录（按严重程度排序）
     *
     * @param  array  $userIds  用户 userid 列表
     * @param  Carbon  $startDate  开始日期
     * @param  Carbon  $endDate  结束日期
     * @return array 异常记录和统计摘要
     */
    public function getAnomalies(array $userIds, Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate->copy()->startOfDay()->timestamp;
        $end = $endDate->copy()->startOfDay()->timestamp;

        Log::debug('CheckinService::getAnomalies', [
            'userIds' => $userIds,
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
        ]);

        $rawData = $this->checkinClient->getDayData($userIds, $start, $end);

        $anomalies = [];
        $summary = [];

        foreach ($rawData as $item) {
            $exceptions = $item['exception_infos'] ?? [];
            if (empty($exceptions)) {
                continue;
            }

            $userId = $item['base_info']['acctid'] ?? '';
            $name = $item['base_info']['name'] ?? $userId;
            $date = isset($item['base_info']['date'])
                ? Carbon::createFromTimestamp($item['base_info']['date'], 'Asia/Shanghai')->toDateString()
                : '';

            foreach ($exceptions as $exc) {
                $exceptionCode = $exc['exception'] ?? 0;
                $count = $exc['count'] ?? 0;
                if ($count === 0) {
                    continue;
                }

                $typeName = self::EXCEPTION_TYPES[$exceptionCode] ?? "未知({$exceptionCode})";
                $severity = self::EXCEPTION_SEVERITY[$exceptionCode] ?? 0;
                $duration = $exc['duration'] ?? 0;

                $anomalies[] = [
                    'userid' => $userId,
                    'name' => $name,
                    'date' => $date,
                    'exception_type' => $typeName,
                    'exception_code' => $exceptionCode,
                    'count' => $count,
                    'duration' => $duration > 0 ? $this->formatDuration($duration) : null,
                    'severity' => $severity,
                ];

                // 汇总统计
                $key = "{$userId}_{$exceptionCode}";
                if (! isset($summary[$key])) {
                    $summary[$key] = [
                        'userid' => $userId,
                        'name' => $name,
                        'exception_type' => $typeName,
                        'total_count' => 0,
                        'total_duration' => 0,
                        'severity' => $severity,
                    ];
                }
                $summary[$key]['total_count'] += $count;
                $summary[$key]['total_duration'] += $duration;
            }
        }

        // 按严重程度降序排列
        usort($anomalies, fn ($a, $b) => $b['severity'] <=> $a['severity'] ?: strcmp($a['date'], $b['date']));

        // 汇总也按严重程度排序
        $summaryList = array_values($summary);
        usort($summaryList, fn ($a, $b) => $b['severity'] <=> $a['severity'] ?: $b['total_count'] <=> $a['total_count']);

        // 格式化汇总中的时长
        foreach ($summaryList as &$s) {
            $s['total_duration'] = $s['total_duration'] > 0 ? $this->formatDuration($s['total_duration']) : null;
            unset($s['severity']);
        }

        // 移除明细中的 severity 字段
        foreach ($anomalies as &$a) {
            unset($a['severity']);
        }

        return [
            'anomalies' => $anomalies,
            'summary' => $summaryList,
            'total_anomaly_count' => count($anomalies),
        ];
    }

    /**
     * 根据姓名列表解析 userid
     * 返回解析成功的 userid 列表和需要澄清的姓名
     *
     * @param  array  $names  姓名列表
     * @return array{resolved: array, ambiguous: array, not_found: array}
     */
    public function resolveUserIds(array $names): array
    {
        $contactsService = app(ContactsService::class);
        $resolved = [];
        $ambiguous = [];
        $notFound = [];

        foreach ($names as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $contact = $matches->first();
                $resolved[] = [
                    'userid' => $contact->userid,
                    'name' => $contact->name,
                ];
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input_name' => $name,
                    'candidates' => $matches->map(fn (Contact $c) => [
                        'userid' => $c->userid,
                        'name' => $c->name,
                        'department' => $c->department,
                    ])->toArray(),
                ];
            } else {
                $notFound[] = $name;
            }
        }

        return [
            'resolved' => $resolved,
            'ambiguous' => $ambiguous,
            'not_found' => $notFound,
        ];
    }

    /**
     * 获取所有员工的 userid 列表
     *
     * @return array userid 列表
     */
    public function getAllUserIds(): array
    {
        return Contact::pluck('userid')->toArray();
    }

    /**
     * 格式化日报数据
     *
     * @param  array  $item  原始日报数据
     * @return array 格式化后的数据
     */
    private function formatDayData(array $item): array
    {
        $baseInfo = $item['base_info'] ?? [];
        $summaryInfo = $item['summary_info'] ?? [];
        $exceptions = $item['exception_infos'] ?? [];
        $spItems = $item['sp_items'] ?? [];
        $otInfo = $item['ot_info'] ?? [];

        $result = [
            'userid' => $baseInfo['acctid'] ?? '',
            'name' => $baseInfo['name'] ?? '',
            'department' => $baseInfo['departs_name'] ?? '',
            'date' => isset($baseInfo['date'])
                ? Carbon::createFromTimestamp($baseInfo['date'], 'Asia/Shanghai')->toDateString()
                : '',
            'day_type' => ($baseInfo['day_type'] ?? 0) === 0 ? '工作日' : '休息日',
            'rule_name' => $baseInfo['rule_info']['groupname'] ?? '',
            'checkin_count' => $summaryInfo['checkin_count'] ?? 0,
            'actual_work_time' => $this->formatDuration($summaryInfo['regular_work_sec'] ?? 0),
            'standard_work_time' => $this->formatDuration($summaryInfo['standard_work_sec'] ?? 0),
        ];

        // 异常信息
        $exceptionList = [];
        foreach ($exceptions as $exc) {
            $code = $exc['exception'] ?? 0;
            $count = $exc['count'] ?? 0;
            if ($count > 0) {
                $exceptionList[] = [
                    'type' => self::EXCEPTION_TYPES[$code] ?? "未知({$code})",
                    'count' => $count,
                    'duration' => ($exc['duration'] ?? 0) > 0 ? $this->formatDuration($exc['duration']) : null,
                ];
            }
        }
        $result['exceptions'] = $exceptionList;

        // 假勤信息
        $spList = [];
        foreach ($spItems as $sp) {
            $count = $sp['count'] ?? 0;
            if ($count > 0) {
                $spList[] = [
                    'type' => $sp['name'] ?? (self::SP_TYPES[$sp['type'] ?? 0] ?? '未知'),
                    'count' => $count,
                    'duration' => $this->formatSpDuration($sp['duration'] ?? 0, $sp['time_type'] ?? 0),
                ];
            }
        }
        $result['leave_info'] = $spList;

        // 加班信息
        if (($otInfo['ot_status'] ?? 0) > 0) {
            $result['overtime'] = [
                'status' => ($otInfo['ot_status'] ?? 0) === 1 ? '正常' : '缺时长',
                'duration' => $this->formatDuration($otInfo['ot_duration'] ?? 0),
            ];
        }

        // 状态判定
        $result['status'] = $this->determineStatus($exceptionList, $spList);

        return $result;
    }

    /**
     * 格式化月报数据
     *
     * @param  array  $item  原始月报数据
     * @return array 格式化后的数据
     */
    private function formatMonthData(array $item): array
    {
        $baseInfo = $item['base_info'] ?? [];
        $summaryInfo = $item['summary_info'] ?? [];
        $exceptions = $item['exception_infos'] ?? [];
        $spItems = $item['sp_items'] ?? [];
        $overwork = $item['overwork_info'] ?? [];

        $result = [
            'userid' => $baseInfo['acctid'] ?? '',
            'name' => $baseInfo['name'] ?? '',
            'department' => $baseInfo['departs_name'] ?? '',
            'rule_name' => $baseInfo['rule_info']['groupname'] ?? '',
            'work_days' => $summaryInfo['work_days'] ?? 0,
            'rest_days' => $summaryInfo['rest_days'] ?? 0,
            'except_days' => $summaryInfo['except_days'] ?? 0,
            'actual_work_time' => $this->formatDuration($summaryInfo['regular_work_sec'] ?? 0),
            'standard_work_time' => $this->formatDuration($summaryInfo['standard_work_sec'] ?? 0),
        ];

        // 异常统计
        $exceptionList = [];
        foreach ($exceptions as $exc) {
            $code = $exc['exception'] ?? 0;
            $count = $exc['count'] ?? 0;
            if ($count > 0) {
                $exceptionList[] = [
                    'type' => self::EXCEPTION_TYPES[$code] ?? "未知({$code})",
                    'count' => $count,
                    'duration' => ($exc['duration'] ?? 0) > 0 ? $this->formatDuration($exc['duration']) : null,
                ];
            }
        }
        $result['exceptions'] = $exceptionList;

        // 假勤统计
        $spList = [];
        foreach ($spItems as $sp) {
            $count = $sp['count'] ?? 0;
            if ($count > 0) {
                $spList[] = [
                    'type' => $sp['name'] ?? (self::SP_TYPES[$sp['type'] ?? 0] ?? '未知'),
                    'count' => $count,
                    'duration' => $this->formatSpDuration($sp['duration'] ?? 0, $sp['time_type'] ?? 0),
                ];
            }
        }
        $result['leave_info'] = $spList;

        // 加班统计
        $totalOvertime = ($overwork['workday_over_sec'] ?? 0)
            + ($overwork['restdays_over_sec'] ?? 0)
            + ($overwork['holidays_over_sec'] ?? 0);

        if ($totalOvertime > 0) {
            $result['overtime'] = [
                'total' => $this->formatDuration($totalOvertime),
                'workday' => $this->formatDuration($overwork['workday_over_sec'] ?? 0),
                'restday' => $this->formatDuration($overwork['restdays_over_sec'] ?? 0),
                'holiday' => $this->formatDuration($overwork['holidays_over_sec'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 按周聚合日报数据
     *
     * @param  string  $userId  用户 userid
     * @param  Collection  $days  该用户一周的日报数据
     * @return array 周聚合结果
     */
    private function aggregateWeekData(string $userId, Collection $days): array
    {
        $name = '';
        $department = '';
        $totalWorkSec = 0;
        $totalStandardSec = 0;
        $workDays = 0;
        $exceptDays = 0;
        $exceptionCounts = [];
        $spCounts = [];

        foreach ($days as $day) {
            $baseInfo = $day['base_info'] ?? [];
            $summaryInfo = $day['summary_info'] ?? [];
            $exceptions = $day['exception_infos'] ?? [];
            $spItems = $day['sp_items'] ?? [];

            if (empty($name)) {
                $name = $baseInfo['name'] ?? '';
                $department = $baseInfo['departs_name'] ?? '';
            }

            $totalWorkSec += $summaryInfo['regular_work_sec'] ?? 0;
            $totalStandardSec += $summaryInfo['standard_work_sec'] ?? 0;

            if (($baseInfo['day_type'] ?? 0) === 0) {
                $workDays++;
            }

            $hasException = false;
            foreach ($exceptions as $exc) {
                $code = $exc['exception'] ?? 0;
                $count = $exc['count'] ?? 0;
                if ($count > 0) {
                    $hasException = true;
                    $typeName = self::EXCEPTION_TYPES[$code] ?? "未知({$code})";
                    $exceptionCounts[$typeName] = ($exceptionCounts[$typeName] ?? 0) + $count;
                }
            }
            if ($hasException) {
                $exceptDays++;
            }

            foreach ($spItems as $sp) {
                $count = $sp['count'] ?? 0;
                if ($count > 0) {
                    $typeName = $sp['name'] ?? (self::SP_TYPES[$sp['type'] ?? 0] ?? '未知');
                    $spCounts[$typeName] = ($spCounts[$typeName] ?? 0) + $count;
                }
            }
        }

        $exceptionList = [];
        foreach ($exceptionCounts as $type => $count) {
            $exceptionList[] = ['type' => $type, 'count' => $count];
        }

        $spList = [];
        foreach ($spCounts as $type => $count) {
            $spList[] = ['type' => $type, 'count' => $count];
        }

        return [
            'userid' => $userId,
            'name' => $name,
            'department' => $department,
            'work_days' => $workDays,
            'except_days' => $exceptDays,
            'actual_work_time' => $this->formatDuration($totalWorkSec),
            'standard_work_time' => $this->formatDuration($totalStandardSec),
            'exceptions' => $exceptionList,
            'leave_info' => $spList,
        ];
    }

    /**
     * 判定当日出勤状态
     *
     * @param  array  $exceptions  异常列表
     * @param  array  $spList  假勤列表
     * @return string 状态描述
     */
    private function determineStatus(array $exceptions, array $spList): string
    {
        if (empty($exceptions) && empty($spList)) {
            return '正常';
        }

        $parts = [];
        foreach ($exceptions as $exc) {
            $parts[] = $exc['type'];
        }
        foreach ($spList as $sp) {
            $parts[] = $sp['type'];
        }

        return implode('、', $parts);
    }

    /**
     * 秒数转可读时长
     *
     * @param  int  $seconds  秒数
     * @return string 格式化时长（如 "8小时30分钟"）
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0分钟';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}小时";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}分钟";
        }

        return implode('', $parts) ?: '0分钟';
    }

    /**
     * 格式化假勤时长
     *
     * @param  int  $duration  时长原始值
     * @param  int  $timeType  时长单位：0-按天 1-按小时
     * @return string 格式化时长
     */
    private function formatSpDuration(int $duration, int $timeType): string
    {
        if ($duration <= 0) {
            return '0';
        }

        if ($timeType === 0) {
            // 按天
            $days = round($duration / 86400, 1);

            return "{$days}天";
        }

        // 按小时
        $hours = round($duration / 3600, 1);

        return "{$hours}小时";
    }
}
