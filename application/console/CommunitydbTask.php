<?php
/**
 * Created by PhpStorm.
 * User: EASON
 * Date: 2018/7/18
 * Time: 14:28
 */
namespace app\Console;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\db;
use GuzzleHttp\Client;
use QL\QueryList;
use QL\Ext\PhantomJs;
use think\Exception;
use think\facade\Config;
class CommunitydbTask extends Command
{
    protected $server;
    protected $redis;
    protected $platform;

    protected function configure()
    {
        $this->setName('communitydb:start')->setDescription('安居客小区信息采集-入库社区信息!');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->server = new \swoole_server('0.0.0.0', 9504);
        $this->redis = new \Redis();
        $this->redis->connect(Config::get('redis.host'), Config::get('redis.port'));
        $this->redis->auth(Config::get('redis.auth'));
        $this->platform = 'ANJUKE';

        // server 运行前配置
        $this->server->set([
            'worker_num'      => 4,
            'daemonize'       => false,
            'task_worker_num' => 4,  # task 进程数
            'log_file' => '/www/wwwroot/spider.5156dgjob.com/logs/cron_communitydb.log',
        ]);

        // 注册回调函数
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Receive', [$this, 'onReceive']);
        $this->server->on('Task', [$this, 'onTask']);
        $this->server->on('Finish', [$this, 'onFinish']);
        $this->server->on('Close', [$this, 'onClose']);
        $this->server->start();
    }

    // 主进程启动时回调函数
    public function onStart(\swoole_server $server)
    {
        echo "START".PHP_EOL;

    }

    // 主进程启动时回调函数
    public function onWorkerStart(\swoole_server $server, $worker_id)
    {
        if( $worker_id == 0 )
        {
            swoole_timer_tick(200, function ($timer) {
                if(!$this->hasCommunity()){
                    echo '不能采集-社区库为空'.PHP_EOL;
                    return false;
                }
                $allowIps = $this->getAgents(); //默认取一个代理IP
                $Urls = $this->getCommunity();    //默认取一个城市URL
                $isExistHtml = false;
                $score = 0;

                $ql = QueryList::getInstance();
                $ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');
                $html = $ql->browser($Urls[0], false, ['--proxy' => $allowIps[0]])->getHtml();
                $title = QueryList::html($html)->find('title')->text();

                $data = array();
                $data['name'] = QueryList::html($html)->find('.comm-title>h1')->text();
                $data['address'] = QueryList::html($html)->find('.comm-title>h1>.sub-hd')->text();
                $data['name'] = trim(str_replace($data['address'], '', $data['name']));
                $mapurl = QueryList::html($html)->find('.comm-title>a')->href;
                if ( !empty($mapurl) ) {
                    $mapArray = parse_url($mapurl);
                    if (!empty($mapArray['fragment'])) {
                        foreach (explode('&', $mapArray['fragment']) as $k => $v) {
                            $ele = explode('=', $v);
                            if ($ele[0] == 'l1') {
                                $data['latitude'] = $ele[1];
                            }
                            if ($ele[0] == 'l2') {
                                $data['longitude'] = $ele[1];
                            }
                        }
                    }
                }
                $data['price']  = QueryList::html($html)->find('.basic-infos-box>.price>span')->text();
                $data['type'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(0)')->text();
                $data['management_fee'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(1)')->text();
                $data['size'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(2)')->text();
                $data['houses'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(3)')->text();
                $data['year'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(4)')->text();
                $data['parkings'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(5)')->text();
                $data['volume'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(6)')->text();
                $data['greening'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(7)')->text();
                $data['producer'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(8)')->text();
                $data['management'] = QueryList::html($html)->find('.basic-infos-box>.basic-parms-mod>dd:eq(9)')->text();
                $data['info'] = QueryList::html($html)->find('.comm-brief-mod>p')->text();
                $data['__url'] = $Urls[0];
                $data['__time'] = time();
                try {
                    $addId = Db::name('communitys')->insertGetId($data);
                    if(!empty($addId)) {
                        $this->removeCommunity($Urls[0]);
                    }
                    echo '成功写入'.$addId.PHP_EOL;
                } catch (Exception $e) {
//                    print_r('Title:'.$title.PHP_EOL);
//                    print_r($data);
                    echo '写入失败'.PHP_EOL;
                    echo 'Message: ' .$e->getMessage().'Url：'.$Urls[0].PHP_EOL;
                }
            });
        }
    }

    // 建立连接时回调函数
    public function onConnect(\swoole_server $server, $fd, $from_id)
    {
        echo "Connect".PHP_EOL;
    }

    // 收到信息时回调函数
    public function onReceive(\swoole_server $server, $fd, $from_id, $data)
    {
        echo "message: {$data} form Client: {$fd} ".PHP_EOL;

        // 投递异步任务
        $task_id = $server->task($data);
        echo "Dispath AsyncTask: id={$task_id}".PHP_EOL;

        // 将受到的客户端消息再返回给客户端
        $server->send($fd, "Message form Server: {$data}， task_id: {$task_id}".PHP_EOL, $data);
    }

    // 异步任务处理函数
    public function onTask(\swoole_server $server, $task_id, $from_id, $data)
    {
        echo "{$task_id}, Task Completed ".PHP_EOL;

        //返回任务执行的结果
        $server->finish("$task_id -> OK".PHP_EOL);
    }

    // 异步任务完成通知 Worker 进程函数
    public function onFinish(\swoole_server $server, $task_id, $data)
    {
        echo "AsyncTask[{$task_id}] Finish: {$data} ".PHP_EOL;
    }

    // 关闭连时回调函数
    public function onClose(\swoole_server $server, $fd, $from_id)
    {
        echo "Close".PHP_EOL;
    }

    // 拿一个代理IP，并从集合删除
    private function getAgents($count = 1)
    {
        $url = 'http://dynamic.goubanjia.com/dynamic/get/9b5318ca9855fdea66482986f83a8d0e.html?sep=6&rnd='.rand(0, 9999);
        $ql = QueryList::get($url);
        $ipstr = $ql->getHtml();
        $ips = explode(';', $ipstr);
        return $ips;
    }
    // 拿一个社区URL
    private function getCommunity($count = 1)
    {
        return $this->redis->zRange(Config::get('redis.community_set'), 0, $count-1, false);
    }

    // 采集完后删除社区URL
    private function removeCommunity($url) {
        $this->redis->zRem(Config::get('redis.community_set'), $url);
    }

    // 检查是否存在运行采集的社区库
    private function hasCommunity()
    {
        return $this->redis->zCard(Config::get('redis.community_set')) > 0 ? true : false;
    }

}