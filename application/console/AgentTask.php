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
class AgentTask extends Command
{
    protected $server;
    protected $redis;

    // 命令行配置函数
    protected function configure()
    {
        $this->setName('agent:start')->setDescription('HTTP代理!');
    }

    // 设置命令返回信息
    protected function execute(Input $input, Output $output)
    {
        $this->server = new \swoole_server('0.0.0.0', 9501);
        $this->redis = new \Redis();
        $this->redis->connect(Config::get('redis.host'), Config::get('redis.port'));
        $this->redis->auth(Config::get('redis.auth'));

        // server 运行前配置
        $this->server->set([
            'worker_num'      => 4,
            'daemonize'       => false,
            'task_worker_num' => 4,  # task 进程数
            'log_file' => '/www/wwwroot/spider.5156dgjob.com/logs/cron_get_agent.log',
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

    // http://dynamic.goubanjia.com/dynamic/get/9b5318ca9855fdea66482986f83a8d0e.html?sep=3 从goubanjia获取IP
    public function onWorkerStart(\swoole_server $server, $worker_id)
    {
        if( $worker_id == 0 )
        {
            swoole_timer_tick(5000, function ($timer) {
                $url = 'http://dynamic.goubanjia.com/dynamic/get/9b5318ca9855fdea66482986f83a8d0e.html?sep=6&rnd='.rand(0, 9999);
                $ql = QueryList::get($url);
                $ipstr = $ql->getHtml();
                $ips = explode(';', $ipstr);
                foreach ($ips as $k => $v) {
                    if (empty($v)) continue;
                    $result = $this->redis->zAdd(Config::get('redis.ips_free'), time().'', $v);
                    if($result) {
                        $this->redis->incrBy(Config::get('redis.ips_count').":".date('Ymd'), 1);
                    }
                }
            });
        }
    }

    // 主进程启动时回调函数
    public function onWorkerStartXici(\swoole_server $server, $worker_id)
    {
//        if( $worker_id == 0 )
//        {
//            swoole_timer_tick(2*60*1000, function ($timer) {
//                if(!$this->hasAgent()){
//                    echo '不能采集'.PHP_EOL;
//                    swoole_timer_clear($timer);
//                    return false;
//                }
//                $insertData = array();
//                $allowIps = $this->getAgents(1);
//                $url = $this->getxiciUrl();
//                $ql = QueryList::getInstance();
//                $ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');
//                $html = $ql->browser($url, false, ['--proxy' => $allowIps[0], '--proxy-type' => 'https'])->getHtml();
//                $data = QueryList::html($html)->rules([
//                    'ip' => ['td:eq(1)','text','',function($content){
//                        return $content;
//                    }],
//                    'port' => ['td:eq(2)', 'text', '', function($content) {
//                        return $content;
//                    }],
//                    'type' => ['td:eq(5)', 'text', '', function($content) {
//                        return $content;
//                    }]
//                ])->range('#ip_list tr')->query()->getData();
//                $portList =  $data->all();
//                $itemCount = 0;
//                foreach ($portList as $k => $v) {
//                    if(empty($v['ip']) || empty($v['port']) || $v['type'] != 'HTTPS') continue;
//                    $item = $v['ip'].':'.$v['port'];
//                    try{
//                        $result = $this->redis->zAdd(Config::get('redis.ips_free'), time().'.'.$k, $item);
//                        if($result) {
//                            $itemCount++;
//                        }
//                    }catch(Exception $e){
//                        echo $e->getMessage()."\n";
//                    }
//                }
//                if($itemCount > 0) {
//                    $this->redis->incrBy(Config::get('redis.ips_count').":".date('Ymd'), $itemCount);
//                }
//                echo '获取IP数量:'.$itemCount.PHP_EOL;
//            });
//        }
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
        $ips = array();
        $ips_free = $this->redis->zRange(Config::get('redis.ips_free'), 0, $count-1, false);
        foreach ($ips_free as $k => $v)
        {
            array_push($ips, $v);
            $this->redis->zRem(Config::get('redis.ips_free'), $v);
            $this->redis->incrBy(Config::get('redis.ips_count').":".date('Ymd'), -1);
        }
        return $ips;
    }

    // 检查是否存在代理IP
    private function hasAgent()
    {
        return $this->redis->zCard(Config::get('redis.ips_free')) > 0 ? true : false;
    }

    // 获取一个Agent采集地址
    private function getxiciUrl()
    {
        $urls = array('http://www.xicidaili.com', 'http://www.xicidaili.com/nn', 'http://www.xicidaili.com/nt', 'http://www.xicidaili.com/nt');
        return $urls[array_rand($urls, 1)];
    }

}