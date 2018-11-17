<?php
namespace app\index\controller;
use QL\QueryList;
use think\facade\Config;
class Index
{
    public function index()
    {
        exit;
        $url = 'http://www.xicidaili.com';
        $ql = QueryList::getInstance();
        $portList = array();
        $header = [
            'timeout' => 120,
            'headers' => [
               'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36',
            ]
        ];
        $data = $ql->get($url, array(), $header)->encoding('UTF-8')->rules([
            'ip' => ['td:eq(1)','text','',function($content){
                return $content;
            }],
            'port' => ['td:eq(2)', 'text', '', function($content) {
                return $content;
             }]
        ])->range('#ip_list tr')->query()->getData();
        $portList =  $data->all();

        $redis = new \Redis();
        $redis->connect(Config::get('redis.host'), Config::get('redis.port'));
        $redis->auth(Config::get('redis.auth'));
        $index = 1;
        foreach ($portList as $k => $v) {
            if(empty($v['ip']) || empty($v['port'])) continue;
            $item = $v['ip'].':'.$v['port'];
            try{
                //$redis->zAdd(Config::get('redis.ips_free'), $index, $item);
            }catch(Exception $e){
                echo $e->getMessage()."\n";
            }

        }

    }

    public function hello($name = 'ThinkPHP5')
    {
        $ql = QueryList::get('http://spider.weiaierchang.cn/spider.html');
        $html = $ql->getHtml();
        $isExistHtml = false;
        $data = QueryList::html($html)->rules([
            'url' => array('a','href'),
            'name' => array('a','alt')
        ])->range('.list-content>.li-itemmod')->query()->getData();
        $title = QueryList::html($html)->find('title')->text();
        echo $title;
        $communitys = $data->all();
        print_r($communitys);

        if (empty($communitys)) {
            $data = QueryList::html($html)->rules([
                'url' => array('.pic','href')
            ])->range('.key-list>.item-mod')->query()->getData();
            $title = QueryList::html($html)->find('title')->text();
            $communitys = $data->all();
            print_r($communitys);
        }
        exit;




        //

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
                        $data['lat'] = $ele[1];
                    }
                    if ($ele[0] == 'l2') {
                        $data['lng'] = $ele[1];
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
        $data['info'] = QueryList::html($html)->find('.comm-brief-mod>p')->text();//
        print_r($data);

        exit;
        $isExistHtml = QueryList::html($html)->find('.div-border')->count();


        print_r($isExistHtml);

        if(count($data->all()) == 0 && $isExistHtml == 0) {
            print_r('---allow---'.PHP_EOL);
            print_r($data->all());
            $isExistHtml = QueryList::html($html)->find('.area-bd')->count();
            $data  = QueryList::html($html)->find('.area-bd>.filter')->children()->map(function($item){
                if($item->is('a') && $item->text() != '全部'){
                    return ['name' => $item->text(), 'url' => $item->href];
                } else {
                    return [];
                }
            });

        }

        $districts = $data->all();

        print_r($isExistHtml);
        if(count($districts) > 0 || $isExistHtml > 0){
            print_r($districts);
            echo '----成功.'.PHP_EOL;
        } else {
            echo '----失败.'.PHP_EOL;
        }
    }

    public function zhimaagent() {
        $redis = new \Redis();
        $redis->connect(Config::get('redis.host'), Config::get('redis.port'));
        $redis->auth(Config::get('redis.auth'));
        // $url = 'http://webapi.http.zhimacangku.com/getip?num=20&type=2&pro=&city=0&yys=0&port=11&pack=14312&ts=0&ys=0&cs=0&lb=1&sb=0&pb=4&mr=1&regions=';
        //$url = 'http://webapi.http.zhimacangku.com/getip?num=20&type=2&pro=&city=0&yys=0&port=11&pack=26448&ts=0&ys=0&cs=0&lb=1&sb=0&pb=4&mr=1&regions=';
        $ql = QueryList::get($url);
        $html = $ql->getHtml();
        $ipList = json_decode($html, true);
        print_r($ipList);
        foreach ($ipList['data'] as $k => $v) {
            $redis->zAdd(Config::get('redis.ips_free'), 1, $v['ip'].':'.$v['port']);
        }
    }
}
