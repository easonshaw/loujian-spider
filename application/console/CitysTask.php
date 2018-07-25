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
use GuzzleHttp\Client;
use QL\QueryList;

class CitysTask extends Command
{
    protected $server;

    // 命令行配置函数
    protected function configure()
    {
        // setName 设置命令行名称
        // setDescription 设置命令行描述
        $this->setName('citys:start')->setDescription('安居客小区信息采集-城市列表!');
    }

    // 设置命令返回信息
    protected function execute(Input $input, Output $output)
    {
        $this->server = new \swoole_server('0.0.0.0', 9501);

        // server 运行前配置
        $this->server->set([
            'worker_num'      => 4,
            'daemonize'       => false,
            'task_worker_num' => 4,  # task 进程数
            'log_file' => '/www/wwwroot/spider.weiaierchang.cn/cron_get_citys.log',
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
            swoole_timer_tick(5000, function () {
                $url = 'https://www.anjuke.com/sy-city.html';
                $t = time();
                echo date('Y-m-d H:i:s',$t).":Start get citys cron===========================================:".PHP_EOL;
                $data = QueryList::get($url)->rules([
                    'link' => ['a','href'],
                    'text' => ['a','text']
                ])->range('.letter_city')->query()->getData();
                print_r($data->all());
                /*$client = new Client(['base_uri' => $url, 'timeout'  => 2.0]);
                $response = $client->request('GET', '/');
                $body = $response->getBody();
                print_r((string)$body);*/
                echo date('Y-m-d H:i:s',$t).":End get citys cron==============================================:".PHP_EOL;
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
}