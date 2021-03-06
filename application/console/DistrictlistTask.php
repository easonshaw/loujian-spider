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
use think\facade\Config;
class DistrictlistTask extends Command
{
    protected $server;
    protected $redis;
    protected $platform;

    protected function configure()
    {
        $this->setName('districtlist:start')->setDescription('安居客小区信息采集-区域资源列表!');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->server = new \swoole_server('0.0.0.0', 9503);
        $this->redis = new \Redis();
        $this->redis->connect(Config::get('redis.host'), Config::get('redis.port'));
        $this->redis->auth(Config::get('redis.auth'));
        $this->platform = 'ANJUKE';

        // server 运行前配置
        $this->server->set([
            'worker_num'      => 4,
            'daemonize'       => false,
            'task_worker_num' => 4,  # task 进程数
            'log_file' => '/www/wwwroot/spider.5156dgjob.com/logs/cron_get_districtlist.log',
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
                if(!$this->hasAgent()){
                    echo '不能采集-代理IP不存在'.PHP_EOL;
                    return false;
                }
                if(!$this->hasDistrict()){
                    echo '不能采集-城市地区信息为空'.PHP_EOL;
                    return false;
                }

                $insertData = array();
                $allowIps = $this->getAgents(); //默认取一个代理IP
                $Urls = $this->getDistrict();    //默认取一个城市URL
                //$Urls = array('http://spider.weiaierchang.cn/spider.html');
                $isExistHtml = false;

                $ql = QueryList::getInstance();
                $ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');
                $html = $ql->browser($Urls[0], false, ['--proxy' => $allowIps[0]])->getHtml();
                $isExistHtml = QueryList::html($html)->find('.list-content')->count();
                if ($isExistHtml == 0) {
                    $isExistHtml = QueryList::html($html)->find('.list-contents')->count();
                }
                $data  = QueryList::html($html)->find('.list-content>.sortby>.tit>em:eq(1)')->text();
                if ($data == '0') {
                    $this->removeDistrict($Urls[0]);
                }

                if (empty($data)) {
                    $data  = $loupansum =  QueryList::html($html)->find('.sort-condi>.result>em')->text();
                    if ($loupansum == '0') {
                        $this->removeDistrict($Urls[0]);
                    }
                }

                $title = QueryList::html($html)->find('title')->text();

                if ($isExistHtml > 0 && $data > 0)
                {
                    $insertCount = 0;
                    $loop = ceil($data / 30);
                    for ($i=1; $i <= $loop; $i++) {
                        $result = $this->redis->zAdd(Config::get('redis.districtlist_set'), $data, $Urls[0].'p'.$i.'/');
                        if($result > 0) $insertCount++;
                    }
                    if ($insertCount > 0){
                        $this->removeDistrict($Urls[0]);
                        echo $allowIps[0].'---'.$Urls[0].'----代理成功.'.PHP_EOL;
                    } else {
                        echo $allowIps[0].'---'.$Urls[0].'----代理失败.'.PHP_EOL;
                    }
                } else {
                    echo $title.$allowIps[0].'---'.$Urls[0].'----代理失败.'.PHP_EOL;
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
//        存入REDIS队列
//        $ips = array();
//        $ips_free = $this->redis->zRange(Config::get('redis.ips_free'), 0, $count-1, false);
//        foreach ($ips_free as $k => $v)
//        {
//            array_push($ips, $v);
//            $this->redis->zRem(Config::get('redis.ips_free'), $v);
//        }
//        return $ips;
    }

    // 检查是否存在代理IP
    private function hasAgent()
    {
        return true;
        // return $this->redis->zCard(Config::get('redis.ips_free')) > 0 ? true : false;
    }

    // 拿一个解析URL
    private function getDistrict($count = 1)
    {
        return $this->redis->zRange(Config::get('redis.district_set'), 0, $count-1, false);
    }

    // 采集完后删除城市URL
    private function removeDistrict($url) {
        $this->redis->zRem(Config::get('redis.district_set'), $url);
    }

    // 检查是否存在运行采集的城市区域库
    private function hasDistrict()
    {
        return $this->redis->zCard(Config::get('redis.district_set')) > 0 ? true : false;
    }

}