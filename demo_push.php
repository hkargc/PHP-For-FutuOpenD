<?php
/**
 * 异步模式
 */
include(__DIR__ . '/lib/class.futu.php');
if(php_sapi_name() !== 'cli'){
    die();
}

$GLOBALS['ip'] = '127.0.0.1';
$GLOBALS['port'] = 11111;
$GLOBALS['pass'] = '888888';

$GLOBALS['futu'] = new futu($GLOBALS['ip'], $GLOBALS['port'], $GLOBALS['pass']);

Swoole\Coroutine::create(function(){
    try{
        $cli = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
        $cli->set(array(
            'socket_buffer_size' => 1024*1024*32, //32M缓存区
            'open_length_check'     => 1,
            'package_length_type'   => 'V',
            'package_length_offset' => 12,       //第N个字节是包长度的值
            'package_body_offset'   => 44,       //第几个字节开始计算长度
            'package_max_length'    => 8*1024*1024,  //协议最大长度
            'open_tcp_nodelay' => false
        ));
        
        if($cli->connect($GLOBALS['ip'], $GLOBALS['port'], 3) === false){
            $cli->close();
            return true;
        }
        
        $GLOBALS['futu']->push($cli); //关键
        
        $cli->lasttime = time(); //最后心跳时间
    
        $Timer = Swoole\Timer::tick(1000, function($Timer, $cli){ //每N秒执行
            if(! $GLOBALS['futu']->InitConnect()){ //初始化连接
                return true;
            }
            if(! $GLOBALS['futu']->Trd_UnlockTrade(true)){ //解锁交易
                return true;
            }
            if(! $GLOBALS['futu']->Trd_GetAccList()){ //获取账户
                return true;
            }
            if(! $GLOBALS['futu']->Trd_SubAccPush()){ //订阅订单推送
                return true;
            }
            if(time() - $cli->lasttime >= 10){
                $cli->lasttime = time();
                $GLOBALS['futu']->KeepAlive(); //保持连接
            }
            if($GLOBALS['lock']){ //已经订阅
                return true;
            }
            $GLOBALS['lock'] = true;
            
            $GLOBALS['futu']->Qot_GetStaticInfo(0, ['00700','HSImain']);
            $GLOBALS['futu']->Qot_Sub(['00700','HSImain'], 11, true, true, [1], false);
        }, $cli);
    
        while($data = $cli->recv()){
            if(! $a = $GLOBALS['futu']->decode($data, '')){
        		exit(0);
        	}
        	if(! $proto = (int)$a['proto']){
        	    exit(0);
        	}
        	if(! $a = $a['s2c']){
        	    exit(0);
        	}

        	switch ($proto){
        		case 1001: //初始化连接
        			$GLOBALS['futu']->connID = (string)$a['connID'];
        			$GLOBALS['futu']->loginUserID = (string)$a['loginUserID'];
        		break;
        		case 1003: //系统推送通知
        		break;
        		case 1004: //保持连接
        		break;
        		case 2001: //获取交易账号
        			foreach ((array)$a['accList'] as $v){
        				foreach ((array)$v['trdMarketAuthList'] as $vv){ //可拥有多个交易市场权限,目前仅单个
        					$GLOBALS['futu']->accList[$vv][$v['trdEnv']] = (string)$v['accID'];
        				}
        			}
        		break;
        		case 2005: //解锁完成
        			$GLOBALS['futu']->unlock = true;
        		break;
        		case 2008: //订阅订单推送(说明环境准备就绪)
        			$GLOBALS['futu']->accPush = true;
        		break;
        		case 2208: //推送订单更新(说明订单有了变化)
        
        		break;
        		case 2218: //推送新成交(说明持仓有了变化)

        		break;
        		case 3001: //订阅或者反订阅
        		break;
        		case 3005: //推送股票基本报价

        		break;
        		case 3007: //推送K线
					print_r($a);
        		break;
        		case 3009: //推送分时
        		break;
        		case 3011: //推送逐笔
        		    $code = $a['security']['code'];

        		break;
        		case 3013: //推送买卖盘
        			$code = $a['security']['code'];

        		break;
        		case 3015: //推送经纪队列
        		break;
        		case 3019: //到价提醒通知
        		break;
        		case 3202: //静态信息
        		    print_r($a);
                break;
        		case 3203: //快照信息
        		    print_r($a);
        		break;
        		default:
        			
        		break;
        	}
        };
    }catch(Swoole\ExitException $e){
        Swoole\Timer::clear($Timer);
    }
});