<?php

namespace App\Console\Commands;

use App\Models\WecomApiDoc;
use App\Services\WecomApiDocService;
use Illuminate\Console\Command;

class FetchWecomApiDocsCommand extends Command
{
    protected $signature = 'wecom:fetch-docs
        {--import : 仅导入 docConfig 到数据库}
        {--fetch : 抓取文档内容}
        {--doc-id= : 只抓取指定 doc_id}
        {--retry : 重试之前失败的}';

    protected $description = '抓取企业微信官方 API 文档';

    /**
     * 执行命令
     * 不带参数时先 import 再 fetch（完整流程）
     *
     * @param  WecomApiDocService  $service  文档抓取服务
     * @return int 命令退出码
     */
    public function handle(WecomApiDocService $service): int
    {
        $importOnly = $this->option('import');
        $fetchOnly = $this->option('fetch');
        $docId = $this->option('doc-id');
        $retry = $this->option('retry');

        // 不带任何参数时执行完整流程
        $runAll = ! $importOnly && ! $fetchOnly && ! $docId && ! $retry;

        // Step 1: 导入 docConfig
        if ($importOnly || $runAll) {
            $this->importDocConfig($service);

            if ($importOnly) {
                return self::SUCCESS;
            }
        }

        // Step 2: 抓取文档内容
        if ($docId) {
            return $this->fetchSingleDoc($service, (int) $docId);
        }

        if ($fetchOnly || $retry || $runAll) {
            return $this->fetchDocs($service, $retry);
        }

        return self::SUCCESS;
    }

    /**
     * 导入 docConfig.json 到数据库
     *
     * @param  WecomApiDocService  $service  文档抓取服务
     */
    private function importDocConfig(WecomApiDocService $service): void
    {
        $this->info('正在导入 docConfig...');
        $count = $service->importDocConfig();
        $this->info("导入完成，共 {$count} 条记录。");
    }

    /**
     * 抓取指定 doc_id 的单个文档
     *
     * @param  WecomApiDocService  $service  文档抓取服务
     * @param  int  $docId  文档 ID
     * @return int 命令退出码
     */
    private function fetchSingleDoc(WecomApiDocService $service, int $docId): int
    {
        $doc = WecomApiDoc::where('doc_id', $docId)->first();

        if (! $doc) {
            $this->error("未找到 doc_id={$docId} 的记录，请先执行 --import。");

            return self::FAILURE;
        }

        $this->info("正在抓取 doc_id={$docId}（{$doc->title}）...");

        if ($service->fetchAndSave($doc)) {
            $this->info('抓取成功。');

            return self::SUCCESS;
        }

        $this->error('抓取失败。');

        return self::FAILURE;
    }

    /**
     * 批量抓取文档内容
     * 支持断点续传，只抓取 status=0 或 status=2（重试）的记录
     *
     * @param  WecomApiDocService  $service  文档抓取服务
     * @param  bool  $retry  是否重试失败的记录
     * @return int 命令退出码
     */
    private function fetchDocs(WecomApiDocService $service, bool $retry): int
    {
        // 只抓取文档页（type=1），分类节点无需抓取
        $query = WecomApiDoc::where('type', 1);

        if ($retry) {
            $query->where('status', 2);
            $this->info('重试模式：抓取之前失败的记录...');
        } else {
            $query->where('status', 0);
        }

        $docs = $query->get();

        if ($docs->isEmpty()) {
            $this->info('没有需要抓取的文档。');

            return self::SUCCESS;
        }

        $total = $docs->count();
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($docs as $doc) {
            // 分类节点跳过（双重保险）
            if ($doc->doc_id <= 0) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ($service->fetchAndSave($doc)) {
                $success++;
            } else {
                $failed++;
            }

            $bar->advance();

            // 请求间隔 1-2 秒，避免被限流
            usleep(rand(1000000, 2000000));
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("抓取完成：成功 {$success}，失败 {$failed}，跳过 {$skipped}，总计 {$total}。");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
