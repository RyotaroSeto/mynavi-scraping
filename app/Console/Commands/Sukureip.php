<?php

namespace App\Console\Commands;

use App\Models\MynaviUrl;
use App\Models\MynaviJobs;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Sukureip extends Command
{
    // マイナビ情報
    const HOST = 'https://tenshoku.mynavi.jp';

    // マイナビCSVファイル名
    const FILE_PATH = 'app/mynavi_jobs.csv';

    // マイナビページ取得数番号
    const PAGE_NUM = 1;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sukureip:mynabi';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sukureip';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // 既存のレコード削除
        $this->truncateTables();

        // URLをスクレイピングしてDBに保存
        $this->saveUrls();
        $this->saveJobs();

        // CSVに出力
        $this->exportCsv();
    }

    // 指定テーブルのレコード全て削除
    private function truncateTables()
    {
        DB::table('mynavi_urls')->truncate();
        DB::table('mynavi_jobs')->truncate();
    }

    // スクレイピングしてmynavi_urlsに登録
    private function saveUrls()
    {
        // rangeでページ数を置き換える。
        // 全て取得したい場合はページ数を取得すること。
        foreach (range(1, $this::PAGE_NUM) as $num) {
            $url = $this::HOST . '/list/pg' . $num . '/';
            $crawler = \Goutte::request('GET', $url);
            $urls = $crawler->filter('.cassetteRecruit__copy > a')->each(function ($node) {
                $href = $node->attr('href');
                return [
                    'url' => substr($href, 0, strpos($href, '/', 1) + 1),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            });
            DB::table('mynavi_urls')->insert($urls);
            sleep(30);
        }
    }

    // mynavi_urlsのurlを下にスクレイピングし、その情報をmynavi_jobsに登録。
    private function saveJobs()
    {
        foreach (MynaviUrl::all() as $mynaviUrl) {
            $url = $this::HOST . $mynaviUrl->url;
            $crawler = \Goutte::request('GET', $url);

            MynaviJobs::create([
                'url' => $url,
                'title' => $this->getTitle($crawler),
                'company_name' => $this->getCompanyName($crawler),
                'features' => $this->getFeatures($crawler),
            ]);
            sleep(30);
        }
    }

    // スクレイピングタイトル名取得
    private function getTitle($crawler)
    {
        return $crawler->filter('.occName')->text();
    }

    // スクレイピング企業名取得
    private function getCompanyName($crawler)
    {
        return $crawler->filter('.companyName')->text();
    }

    // スクレイピングラベル取得
    private function getFeatures($crawler)
    {
        $features = $crawler->filter('.cassetteRecruit__attribute.cassetteRecruit__attribute-jobinfo .cassetteRecruit__attributeLabel > span')
            ->each(
                function ($node) {
                    return $node->text();
                }
            );
        return implode(',', $features);
    }

    // CSV出力処理
    private function exportCsv()
    {
        $file = fopen(storage_path($this::FILE_PATH), 'w');
        if (!$file) {
            throw new \Exception('CSVファイルの作成に失敗しました');
        }

        if (!fputcsv($file, ['id', 'url', 'title', 'company_name', 'features'])) {
            throw new \Exception('ヘッダーの書き込みに失敗しました');
        };

        foreach (MynaviJobs::all() as $Job) {
            if (!fputcsv($file, [$Job->id, $Job->url, $Job->title, $Job->company_name, $Job->features])) {
                throw new \Exception('中身の書き込みに失敗しました');
            }
        }

        fclose($file);
    }
}
