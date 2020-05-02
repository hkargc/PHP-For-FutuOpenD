<?php
/**
 * 富途行情及交易接口 
 * @author https://github.com/hkargv
 * https://futunnopen.github.io/futu-api-doc/index.html
 */
class futu{
	/**
	 * @var Swoole\Client
	 */
	private $cli = null;
	private $die = false;
	private $push = false; //是否推送模式
	
	private $host = '127.0.0.1';
	private $port = '25100';
	private $pass = '';
	/**
	 * 请求的序列号
	 * @var integer
	 */
	private $sequence = 0;
	/**
	 * 心跳包定时器
	 * @var integer
	 */
	private $timer = 0;
	/**
	 * 初始化的返回
	 * @var integer
	 */
	private $serverVer = 0;
	/**
	 * 初始化的返回
	 * @var integer
	 */
	private $keepAliveInterval = 0;
	/**
	 * 同一个连接只解锁一次
	 * @var string
	 */
	public $unlock = null;
	/**
	 * 订单推送订阅
	 */
	public $accPush = false;
	/**
	 * 行情市场:0未知1港股2港期11美股12美期21沪股22深股
	 * @var integer
	 */
	public $market = 1;
	/**
	 * 交易环境:0仿真1真实
	 * @var integer
	 */
	public $trdEnv = 1;
	/**
	 * 交易市场:0未知1港股2美股3大陆4香港A股通5期货市场
	 * @var integer
	 */
	public $trdMarket = 1;
	/**
	 * 是否通讯加密,需同时设置$private_key(对性能肯定有影响)
	 * @var bool
	 */
	public $encrypt = false;
	/**
	 * 密钥文件绝对路径,与FutuOpenD配置文件中的一致
	 * @var string
	 */
	public $private_key = __DIR__.'/private.key';
	/**
	 * 交易账号列表
	 * @var array
	 */
	public $accList = [];
	public $connID = 0;
	public $loginUserID = 0;
	/**
	 * 初始化接口返回的加密密码
	 * @var string
	 */
	public $connAESKey = '';

	/**
	 * @param string $host
	 * @param string $port
	 * @param string $pass 交易解锁密码
	 */
	public function __construct($host, $port, $pass=''){
		$this->host = $host;
		$this->port = $port;
		$this->pass = $pass;
		
		if(! class_exists('Swoole\Client')){
			die('http://pecl.php.net/package/swoole');
		}
	}
	/**
	 * @param Swoole\Coroutine\Client $cli
	 * @return boolean
	 */
	public function push($cli){
	    if(! $cli instanceof Swoole\Coroutine\Client){
			return false;
		}
		$this->cli = $cli;
		$this->push = true; //是否推送模式
	}
	private function connect(){
	    if($this->cli === null){
			$this->timer = time(); //初始化心跳时间
			$this->cli = new Swoole\Client(preg_match('/^[0-9\.]+$/', $this->host)?(SWOOLE_SOCK_TCP/**|SWOOLE_KEEP**/):(SWOOLE_SOCK_UNIX_STREAM/**|SWOOLE_KEEP**/));
			$this->cli->set(array(
					'socket_buffer_size' => 1024*1024*32, //32M缓存区
					'open_length_check'     => 1,
					'package_length_type'   => 'L',
					'package_length_offset' => 12,       //第N个字节是包长度的值
					'package_body_offset'   => 44,       //第几个字节开始计算长度
					'package_max_length'    => 8*1024*1024,  //协议最大长度
					'open_tcp_nodelay' => false
			));
			if(! @$this->cli->connect($this->host, $this->port, 8)){
				$this->errorlog('Connect Error.'.socket_strerror($this->cli->errCode), 0);
			}
		}
		if(! $this->cli){
			$this->errorlog('Client Error.', 0);
		}
		if($this->timer && (time() - $this->timer >= 20)){ //每N秒发一次心跳(只有同步模式会初始化时间)
			$this->timer = time(); //锁住
			$this->KeepAlive();
		}
		return $this->cli;
	}
	/**
	 * 关闭连接,销毁之前的数据
	 */
	public function close(){
	    if($this->cli instanceof Swoole\Coroutine\Client){
	        $this->cli->close();
	        exit(0);
	    }
	    
	    if($this->cli instanceof Swoole\Client){
		    $this->cli->isConnected() && $this->cli->close(true);
		}
		
		$this->cli = null;
		$this->connID = 0;
		$this->unlock = false;
	}
	/**
	 * 初始化连接
	 * @return string
	 */
	public function InitConnect(){
		if($this->connID){ //已经初始化过
			return $this->connID;
		}
		$C2S = array(
				"clientVer" => 0,
				'clientID' => '0',
		        'pushProtoFmt' => 1, //0:pb,1:json
		        'packetEncAlgo' => -1,
				'recvNotify' => $this->push ? true : false,
				);
		if(! $ret = $this->send('1001', $C2S)){
			return false;
		}
	
		$this->connID = (string)$ret['connID']; //uint64
		$this->loginUserID = (string)$ret['loginUserID']; //uint64
		$this->connAESKey = (string)$ret['connAESKey'];
		$this->serverVer = (int)$ret['serverVer'];
		$this->keepAliveInterval = (int)$ret['keepAliveInterval'];

		return $this->connID;
	}
	/**
	 * 获取全局状态 
	 * @return array
	 */
	public function GetGlobalState(){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'userID' => (string)$this->loginUserID,
				);
		if(! $ret = $this->send('1002', $C2S)){
			return array();
		}
		return (array)$ret;
	}
	/**
	 * 保活心跳
	 * @return int
	 */
	public function KeepAlive(){
		if(! $this->InitConnect()){
			return 0;
		}
		$C2S = array(
				'time' => (int)time(),
		);
		if(! $ret = $this->send('1004', $C2S)){
			return 0;
		}

		return (int)$ret['time'];
	}
	/**
	 * 订阅或者反订阅,同时注册或者取消推送(股票个数*K线种类<=100)
	 * @param array $codes
	 * @param array $subTypeList 1报价;2摆盘;4逐笔;5分时;6日K;7五分K;8十五分K;9三十K;10六十K;11一分K;12周K;13月K;14经纪队列;15季K;16年K;17三分K;18委托明细
	 * @param bool $isSubOrUnSub true订阅false反订阅
	 * @param bool $isRegOrUnRegPush 是否注册或反注册该连接上面行情的推送,该参数不指定不做注册反注册操作
	 * @param array $regPushRehabTypeList 复权类型:0不复权1前复权2后复权
	 * @param bool $isFirstPush 注册后如果本地已有数据是否首推一次已存在数据
	 * @param bool $isUnsubAll 一键取消当前连接的所有订阅,当被设置为true时忽略其他参数
	 * @return bool
	 */
	public function Qot_Sub($codes, $subTypeList, $isSubOrUnSub=true, $isRegOrUnRegPush=null, $regPushRehabTypeList=[], $isFirstPush=false, $isUnsubAll=false){
		if(! $this->InitConnect()){
			return false;
		}
		$C2S = array(
		    'isSubOrUnSub' => (bool)$isSubOrUnSub, //true订阅false反订阅
		    'isFirstPush' => (bool)$isFirstPush,
		    'isUnsubAll' => (bool)$isUnsubAll
		);
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => $this->market,
					'code' => (string)$code,
			);
		}
		if(($isUnsubAll == false) && empty($securityList)){
		    return false;
		}
		if($securityList){
		    $C2S['securityList'] = $securityList;
		}
		if($subTypeList = (array)$subTypeList){ //订阅数据类型
		    $C2S['subTypeList'] = array_unique(array_values($subTypeList));
		}
		if($isRegOrUnRegPush !== null){
			$C2S['isRegOrUnRegPush'] = (bool)$isRegOrUnRegPush;
		}
		if($isRegOrUnRegPush && $regPushRehabTypeList){
			$C2S['regPushRehabTypeList'] = (array)$regPushRehabTypeList;
		}
		if(! $ret = $this->send('3001', $C2S)){
			return false;
		}
		return (bool)$ret;
	}
	/**
	 * 注册行情推送(可用Qot_Sub替代)
	 * @param array $codes
	 * @param array $subTypeList 1报价;2摆盘;4逐笔;5分时;6日K;7五分K;8十五分K;9三十K;10六十K;11一分K;12周K;13月K;14经纪队列;15季K;16年K;17三分K
	 * @param bool $isRegOrUnReg
	 * @param array $rehabTypeList
	 * @param bool $isFirstPush
	 * @return boolean
	 */
	public function Qot_RegQotPush($codes, $subTypeList, $isRegOrUnReg, $rehabTypeList=[1], $isFirstPush=false){
		if(! $this->InitConnect()){
			return false;
		}
		if(! $this->Qot_Sub($codes, $subTypeList, true, $isRegOrUnReg, $rehabTypeList, $isFirstPush)){
			return false;
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => $this->market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return false;
		}
		$C2S = array(
				'securityList' => (array)$securityList,
				'subTypeList' => (array)$subTypeList, //订阅数据类型
				'isRegOrUnReg' => (bool)$isRegOrUnReg, //true订阅false反订阅
				'isFirstPush' => (bool)$isFirstPush,
				'rehabTypeList' => (array)$rehabTypeList,
		);
		if(! $ret = $this->send('3002', $C2S)){
			return false;
		}
		
		return (bool)$ret;
	}
	/**
	 * 获取订阅信息
	 * @param bool $isReqAllConn 是否返回所有连接的订阅状态
	 */
	public function Qot_GetSubInfo($isReqAllConn){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'isReqAllConn' => (bool)$isReqAllConn,
		);
		if(! $ret = $this->send('3003', $C2S)){
			return array();
		}
		return (array)$ret;
	}
	/**
	 * 获取股票基本行情
	 * @param array $codes
	 * @return array
	 */
	public function Qot_GetBasicQot($codes){
		if(! $this->Qot_Sub($codes, [1], true)){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => $this->market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return array();
		}
		$C2S = array(
				'securityList' => (array)$securityList,
		);
		if(! $ret = $this->send('3004', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['basicQotList'] as $v){
			
			if(! $v['code'] = $v['security']['code']){
				continue;
			}
			$v['market'] = $v['security']['market'];
			unset($v['security']);

			$gets[$v['code']] = $v;
		}
		return (array)$gets;
	}
	/**
	 * 获取K线
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $reqNum K线条数
	 * @param int $rehabType 复权类型:0不复权1前复权2后复权
	 */
	public function Qot_GetKL($code, $klType, $reqNum=1000, $rehabType=1){
		$map = array(
			1 => 11,
			2 => 6,
			3 => 12,
			4 => 13,
			5 => 16,
			6 => 7,
			7 => 8,
			8 => 9,
			9 => 10,
			10 => 17,
			11 => 15
		);
		if(! $this->Qot_Sub($code, [$map[$klType]], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				),
				'klType' => (int)$klType,
				'reqNum' => (int)$reqNum,
				'rehabType' => (int)$rehabType,				
		);
		if(! $ret = $this->send('3006', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['klList'] as $v){
			$v['avgPrice'] = $v['closePrice'];
			$v['price'] = $v['closePrice']; //方便前端

			$gets[$v['timestamp']] = $v;
		}
		ksort($gets, SORT_NUMERIC);
		return (array)$gets;
	}
	/**
	 * 获取分时
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetRT($code){
		if(! $this->Qot_Sub($code, [5], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				)
		);
		if(! $ret = $this->send('3008', $C2S)){
			return array();
		}

		$gets = array();
		foreach ((array)$ret['rtList'] as $v){
			$v['closePrice'] = $v['price']; //方便前端
			
			$gets[$v['timestamp']] = $v;
		}
		ksort($gets, SORT_NUMERIC);
		return (array)$gets;
	}
	/**
	 * 获取逐笔
	 * @param string $code
	 * @param int $maxRetNum
	 * @return array
	 */
	public function Qot_GetTicker($code, $maxRetNum=1000){
		if(! $this->Qot_Sub($code, [4], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				),
				'maxRetNum' => (int)$maxRetNum,
		);
		if(! $ret = $this->send('3010', $C2S)){
			return array();
		}
		return (array)$ret['tickerList'];
	}
	/**
	 * 获取买卖盘
	 * @param string $code
	 * @param int $num
	 * @return array
	 */
	public function Qot_GetOrderBook($code, $num=10){
		if(! $this->Qot_Sub($code, [2], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				),
				'num' => (int)$num,
		);
		if(! $ret = $this->send('3012', $C2S)){
			return array();
		}

		return array('buy'=>(array)$ret['orderBookBidList'], 'sell'=>(array)$ret['orderBookAskList']);
	}
	/**
	 * 获取经纪队列
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetBroker($code){
		if(! $this->Qot_Sub($code, [14], true)){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				)
		);
		if(! $ret = $this->send('3014', $C2S)){
			return array();
		}
		return array((array)$ret['brokerAskList'], (array)$ret['brokerBidList']);
	}
	/**
	 * 获取委托明细
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetOrderDetail($code){
	    if(! $this->Qot_Sub($code, [18], true)){
	        return array();
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        )
	    );
	    if(! $ret = $this->send('3016', $C2S)){
	        return array();
	    }
	    return (array)$ret;
	}
	/**
	 * 获取单只股票一段历史K线(有限额)
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $beginTime
	 * @param int $endTime
	 * @param int $maxAckKLNum 最多返回多少根K线,如果未指定表示不限制
	 * @param int $needKLFieldsFlag 指定返回K线结构体特定某几项数据,KLFields枚举值或组合,如果未指定返回全部字段
	 * @param int $rehabType 复权类型
	 */
	public function Qot_RequestHistoryKL($code, $klType, $beginTime, $endTime, $maxAckKLNum=0, $needKLFieldsFlag=[], $rehabType=1){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        ),
	        'klType' => (int)$klType,
	        'beginTime' => date("Y-m-d H:i:s", $beginTime),
	        'endTime' => date("Y-m-d H:i:s", $endTime),
	        'rehabType' => (int)$rehabType,
	    );
	    if($maxAckKLNum){
	        $C2S['maxAckKLNum'] = (int)$maxAckKLNum;
	    }
	    if($needKLFieldsFlag){
	        $C2S['needKLFieldsFlag'] = (array)$needKLFieldsFlag;
	    }
	    if(! $ret = $this->send('3103', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['klList'] as $v){
	        $v['avgPrice'] = $v['closePrice'];
	        $v['price'] = $v['closePrice']; //方便前端
	        $gets[$v['timestamp']] = $v;
	    }
	    ksort($gets, SORT_NUMERIC);
	    return (array)$gets;
	}
	/**
	 * 拉取历史K线已经用掉的额度
	 * @param bool $bGetDetail 是否拉取详细列表
	 */
	public function Qot_RequestHistoryKLQuota($bGetDetail){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'bGetDetail' => (bool)$bGetDetail,
	    );
	    if(! $ret = $this->send('3104', $C2S)){
	        return array();
	    }
	    foreach ((array)$ret['detailList'] as $k => $v){
	        $ret['detailList'][$k]['code'] = $v['security']['code'];
	        $ret['detailList'][$k]['market'] = $v['security']['market'];
	        
	        unset($ret['detailList'][$k]['security']);
	    }
	    return (array)$ret;
	}
	/**
	 * 获取复权信息
	 * @param array $codes
	 * @return array/false
	 */
	public function Qot_RequestRehab($code){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return false;
	    }
	    if(! $this->InitConnect()){
	        return false;
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        )
	    );
	    if(! $ret = $this->send('3105', $C2S)){
	        return array();
	    }
	    
	    return (array)$ret['rehabList'];
	}
	/**
	 * 获取股票列表
	 * @param int $secType 0未知或指定股票1债券2权证3正股4基金5涡轮6指数7板块8期权9板块集合
	 * @param array $codes 股票,若该参数存在,忽略其他参数
	 * @return array
	 */
	public function Qot_GetStaticInfo($secType, $codes=array()){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'market' => $this->market,
	        'secType' => (int)$secType,
	    );
	    $securityList = array();
	    foreach ((array)$codes as $code){
	        $securityList[] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if($securityList){
	        $C2S['securityList'] = (array)$securityList;
	    }
	    if(! $ret = $this->send('3202', $C2S)){
	        return array();
	    }
	    
	    $gets = array();
	    foreach ((array)$ret['staticInfoList'] as $v){
	        if($v['basic']){
	            $v['basic']['code'] = $v['basic']['security']['code'];
	            $v['basic']['market'] = $v['basic']['security']['market'];
	            unset($v['basic']['security']);
	        }
	        if($v['warrantExData']){
	            $v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
	            $v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
	            unset($v['warrantExData']['owner']);
	        }
	        
	        $a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
	        if(! $a['code']){
	            continue;
	        }
	        
	        $gets[$a['code']] = $a;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取一批股票的快照信息,每次最多200支(30秒10次)
	 * @param array $codes
	 * @return array
	 */
	public function Qot_GetSecuritySnapshot($codes){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $securityList = array();
	    foreach ((array)$codes as $code){
	        if($GLOBALS['FUTU_DELISTED'][$code]){
	            continue;
	        }
	        $securityList[] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if(! $securityList){
	        return array();
	    }
	    $C2S = array(
	        'securityList' => (array)$securityList,
	    );
	    if(! $ret = $this->send('3203', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['snapshotList'] as $v){
	        if($v['basic']){
	            $v['basic']['code'] = $v['basic']['security']['code'];
	            $v['basic']['market'] = $v['basic']['security']['market'];
	            $v['basic']['secType'] = $v['basic']['type'];
	            unset($v['basic']['security'], $v['basic']['type']);
	        }
	        if($v['warrantExData']){
	            $v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
	            $v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
	            $v['warrantExData']['type'] = $v['warrantExData']['warrantType'];
	            unset($v['warrantExData']['owner'],$v['warrantExData']['warrantType']);
	        }
	        $a = array_merge((array)$v['basic'], (array)$v['equityExData'], (array)$v['warrantExData'], (array)$v['plateExData'], (array)$v['indexExData']);
	        if(! $a['code']){
	            continue;
	        }
	        
	        $gets[$a['code']] = $a;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取板块集合下的板块(30秒10次)
	 * @param int $plateSetType 0所有版块1行业板块2地域板块3概念版块
	 * @return array
	 */
	public function Qot_GetPlateSet($plateSetType){
		if(! $this->limit(__LINE__, 30, 10)){
		    return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'plateSetType' => (int)$plateSetType,
				'market' => $this->market,
		);
		if(! $ret = $this->send('3204', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['plateInfoList'] as $v){
			$gets[] = array('market'=>$v['plate']['market'],'code'=>$v['plate']['code'],'name'=>$v['name']);
		}
		return (array)$gets;
	}
	/**
	 * 获取板块下的股票(30秒10次)
	 * @param string $code 版块编号(如期货板块:BK1986)
	 * @param int $sortField 排序字段
	 * @param bool $ascend 是否升序
	 * @return array
	 */
	public function Qot_GetPlateSecurity($code, $sortField=1, $ascend=true){
		if(! $this->limit(__LINE__, 30, 10)){
		    return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'plate' => array(
						'code' => (string)$code,
						'market' => $this->market,
				),
		        'sortField' => (int)$sortField,
		        'ascend' => (bool)$ascend,
		);
		if(! $ret = $this->send('3205', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['staticInfoList'] as $v){
			if($v['basic']){
				$v['basic']['code'] = $v['basic']['security']['code'];
				$v['basic']['market'] = $v['basic']['security']['market'];
				unset($v['basic']['security']);
			}
			if($v['warrantExData']){
				$v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
				$v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
				unset($v['warrantExData']['owner']);
			}
			
			$a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
			if(! $a['code']){
				continue;
			}
			
			$gets[$a['code']] = $a;
		}
		return (array)$gets;
	}
	/**
	 * 获取正股相关股票
	 * @param string $code 正股代码
	 * @param int $referenceType 1正股相关的窝轮;2期货主连的相关合约
	 * @return array
	 */
	public function Qot_GetReference($code, $referenceType=1){
		if(! $this->InitConnect()){
			return array();
		}
		$C2S = array(
				'security' => array(
						'market' => $this->market,
						'code' => (string)$code,
				),
				'referenceType' => (int)$referenceType,
		);
		if(! $ret = $this->send('3206', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['staticInfoList'] as $v){
			if($v['basic']){
				$v['basic']['code'] = $v['basic']['security']['code'];
				$v['basic']['market'] = $v['basic']['security']['market'];
				unset($v['basic']['security']);
			}
			if($v['warrantExData']){
				$v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
				$v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
				unset($v['warrantExData']['owner']);
			}
			
			$a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
			if(! $a['code']){
				continue;
			}
			
			$gets[$a['code']] = $a;
		}
		return (array)$gets;
	}
	/**
	 * 获取股票所属板块(30秒10次)
	 * @param array $codes 最多200个,仅支持正股和指数
	 */
	public function Qot_GetOwnerPlate($codes){
		if(! $this->limit(__LINE__, 30, 10)){
		    return array();
		}
		if(! $this->InitConnect()){
			return array();
		}
		$securityList = array();
		foreach ((array)$codes as $code){
			$securityList[] = array(
					'market' => $this->market,
					'code' => (string)$code,
			);
		}
		if(! $securityList){
			return array();
		}
		$C2S = array(
				'securityList' => (array)$securityList,
		);
		if(! $ret = $this->send('3207', $C2S)){
			return array();
		}
		$gets = array();
		foreach ((array)$ret['ownerPlateList'] as $v){
			foreach ((array)$v['plateInfoList'] as $vv){
				
				$vv['plate_code'] = $vv['plate']['code'];
				unset($vv['plate']);
				
				$gets[$v['security']['code']][] = $vv;
			}
		}
		return (array)$gets;
	}
	/**
	 * 获取持股变化列表
	 */
	public function Qot_GetHoldingChangeList(){
		
	}
	/**
	 * 获取期权链
	 */
	public function Qot_GetOptionChain(){
		
	}
	/**
	 * 获取涡轮 https://futunnopen.github.io/futu-api-doc/protocol/quote_protocol.html#qot-getwarrant-proto-3210
	 * @param array $filter
	 * @param number $begin 数据起始点
	 * @param number $num 请求数据个数,最大200
	 * @param number $sortField 根据哪个字段排序 https://futunnopen.github.io/futu-api-doc/protocol/base_define.html#sortfield
	 * @param string $ascend 升序ture,降序false
	 */
	public function Qot_GetWarrant($owner, $filter=array(), $begin=0, $num=200, $sortField=10, $ascend=false){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'begin' => (int)$begin,
	        'num' => (int)$num,
	        'sortField' => (int)$sortField,
	        'ascend' => (bool)$ascend
	    );
	    foreach ((array)$filter as $k => $v){
	        $C2S[$k] = $v;
	    }
	    if($owner = trim($owner)){
	        $C2S['owner'] = array(
	            'market' => $this->market,
	            'code' => (string)$owner,
	        );
	    }
	    if(! $ret = $this->send('3210', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['warrantDataList'] as $v){
	        if(! $v['code'] = (string)$v['stock']['code']){
	            continue;
	        }
	        if(! $v['owner'] = (string)$v['owner']['code']){
	            continue;
	        }
	        
	        unset($v['stock']);
	        
	        $gets[$v['code']] = $v;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取资金流向
	 * @param string $code
	 * @return boolean|array|array
	 */
	public function Qot_GetCapitalFlow($code){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
		if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        )
	    );
	    if(! $ret = $this->send('3211', $C2S)){
	        return array();
	    }
	    return (array)$ret['flowItemList'];
	}
	/**
	 * 获取资金分布
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetCapitalDistribution($code){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
		if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        )
	    );
	    if(! $ret = $this->send('3212', $C2S)){
	        return array();
	    }
	    return (array)$ret;
	}
	/**
	 * 获取自选股分组下的股票
	 * @param string $groupName
	 * @return array
	 */
	public function Qot_GetUserSecurity($groupName){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
		if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'groupName' => (string)$groupName
	    );
	    if(! $ret = $this->send('3213', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['staticInfoList'] as $v){
	        if($v['basic']){
	            $v['basic']['code'] = $v['basic']['security']['code'];
	            $v['basic']['market'] = $v['basic']['security']['market'];
	            unset($v['basic']['security']);
	        }
	        if($v['warrantExData']){
	            $v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
	            $v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
	            unset($v['warrantExData']['owner']);
	        }
	        
	        $a = array_merge((array)$v['basic'], (array)$v['warrantExData']);
	        if(! $a['code']){
	            continue;
	        }
	        
	        $gets[$a['code']] = $a;
	    }
	    return (array)$gets;
	}
	/**
	 * 修改自选股分组下的股票
	 * @param string $groupName
	 * @param int $op 1新增 2删除3移出
	 * @param array $codes
	 * @return boolean
	 */
	public function Qot_ModifyUserSecurity($groupName, $op, $codes){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return false;
	    }
		if(! $this->InitConnect()){
	        return false;
	    }
	    $securityList = array();
	    foreach ((array)$codes as $code){
	        $securityList[] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if(! $securityList){
	        return false;
	    }
	    
	    $C2S = array(
	        'groupName' => (string)$groupName,
	        'op' => (int)$op,
	        'securityList' => (array)$securityList,
	    );
	    if(! $ret = $this->send('3214', $C2S)){
	        return false;
	    }
	    return (bool)$ret;
	}
	/**
	 * 获取条件选股
	 * @param array $filter
	 * @param number $plate
	 * @param number $begin
	 * @param number $num
	 * @return array
	 */
	public function Qot_StockFilter($filter=array(), $plate=0, $begin=0, $num=10){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'begin' => (int)$begin,
	        'num' => (int)$num,
	        'market' => $this->market
	    );
	    foreach ((array)$filter as $k => $v){
	        $C2S[$k] = $v;
	    }
	    if($plate = trim($plate)){
	        $C2S['plate'] = array(
	            'market' => $this->market,
	            'code' => (string)$plate,
	        );
	    }
	    if(! $ret = $this->send('3215', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ($ret['dataList'] as $v){
	        if(! $v['code'] = $v['security']['code']){
	            continue;
	        }
	        $v['market'] = $v['security']['market'];
	        
	        unset($v['security']);
	        
	        $gets[$v['code']] = $v;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取股票代码变更信息
	 * @param array $codes
	 * @param array $typeList 根据类型筛选1创业板转主板2买卖未缴款供股权3更改买卖单位4拆股5合股6股份先并后拆7股份先拆后并8其他
	 * @param array $timeFilter 根据时间筛选
	 * @return array
	 */
	public function Qot_GetCodeChange($codes, $typeList, $timeFilter){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = $securityList = array();
	    foreach ((array)$codes as $code){
	        $securityList[] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if($securityList){
	        $C2S['securityList'] = $securityList;
	    }
	    if($typeList = (array)$typeList){
	        $C2S['typeList'] = $typeList;
	    }
	    if($timeFilter = (array)$timeFilter){
	        $C2S['timeFilter'] = $timeFilter;
	    }
	    if($placeHolder = (int)$placeHolder){ //占位
	        $C2S['placeHolder'] = $placeHolder;
        }
        if(! $C2S){
            return array();
        }
	    if(! $ret = $this->send('3216', $C2S)){
	        return array();
	    }
	    return (array)$ret['codeChangeList'];
	}
	/**
	 * 获取IPO信息
	 * @return array
	 */
	public function Qot_GetIpoList(){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'market' => $this->market
	    );
	    if(! $ret = $this->send('3217', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['ipoList'] as $v){
    	    if($v['basic']){
    	        $v['basic']['code'] = $v['basic']['security']['code'];
    	        $v['basic']['market'] = $v['basic']['security']['market'];
    	        unset($v['basic']['security']);
    	    }
    	    $a = array_merge((array)$v['basic'], (array)$v['hkExData']);
    	    if(! $a['code']){
    	        continue;
    	    }
    	    
    	    $gets[] = $a;
	    }
	    
	    return (array)$gets;
	}
	/**
	 * 获取期货合约资料
	 * @param array $codes
	 * @return array|boolean
	 */
	public function Qot_GetFutureInfo($codes){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $securityList = array();
	    foreach ((array)$codes as $code){
	        $securityList[] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if(! $securityList){
	        return false;
	    }
	    $C2S = array(
	        'securityList' => (array)$securityList
	    );
	    if(! $ret = $this->send('3218', $C2S)){
	        return false;
	    }
	    $gets = array();
	    foreach ((array)$ret['futureInfoList'] as $v){
	        if($v['basic']){
	            $v['basic']['code'] = $v['basic']['security']['code'];
	            $v['basic']['market'] = $v['basic']['security']['market'];
	            unset($v['basic']['security']);
	        }
	        if($v['warrantExData']){
	            $v['warrantExData']['owner_code'] = $v['warrantExData']['owner']['code'];
	            $v['warrantExData']['owner_market'] = $v['warrantExData']['owner']['market'];
	            unset($v['warrantExData']['owner']);
	        }
	        $v['code'] = $v['security']['code'];
	        $v['market'] = $v['security']['market'];
	        unset($v['security']);
	        
	        $v['owner_code'] = $v['owner']['code'];
	        $v['owner_market'] = $v['owner']['market'];
	        unset($v['owner']);

	        $gets[$v['code']] = $v;
	    }
	    return (array)$gets;
	}
	/**
	 * 在线请求交易日
	 * @param int $beginTime
	 * @param int $endTime
	 * @return array tradeDateType: 0全天;1上午;2下午
	 */
	public function Qot_RequestTradeDate($beginTime, $endTime){
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'market' => $this->market,
	        'beginTime' => date('Y-m-d', $beginTime), //开始时间字符串
	        'endTime' => date('Y-m-d', $endTime) //结束时间字符串
	    );
	    if(! $ret = $this->send('3219', $C2S)){
	        return array();
	    }
	    
	    $gets = array();
	    foreach ((array)$ret['tradeDateList'] as $v){
	        if(! $timestamp = (int)$v['timestamp']){
	            continue;
	        }
	        $gets[$timestamp] = array($timestamp, $v['tradeDateType']);
	    }
	    ksort($gets, SORT_NUMERIC);
	    
	    return array_values($gets);
	}
	/**
	 * 设置到价提醒
	 * @param string $code
	 * @param int $op 操作类型:1新增 2删除 3启用 4禁用 5修改
	 * @param int $key 到价提醒的标识,Qot_GetPriceReminder协议可获得,用于指定要操作的到价提醒项,对于新增的情况不需要填
	 * @param int $type Qot_Common::PriceReminderType,提醒类型,删除/启用/禁用的情况下会忽略该字段 https://futunnopen.github.io/futu-api-doc/protocol/base_define.html#priceremindertype
	 * @param int $freq Qot_Common::PriceReminderFreq,提醒频率类型,删除/启用/禁用的情况下会忽略该字段 1持续提醒 2每日一次 3仅提醒一次
	 * @param float $value 提醒值,删除/启用/禁用的情况下会忽略该字段
	 * @param string $note 用户设置到价提醒时的标注,最多10个字符,删除/启用/禁用的情况下会忽略该字段
	 */
	public function Qot_SetPriceReminder($code, $op, $key=0, $type=0, $freq=0, $value=0, $note=''){
	    if(! $this->limit(__LINE__, 30, 60)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'security' => array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        ),
	        'op' => (int)$op
	    );
	    if($key = (int)$key){
	        $C2S['key'] = $key;
	    }
	    if($type = (int)$type){
	        $C2S['type'] = $type;
	    }
	    if($freq = (int)$freq){
	        $C2S['freq'] = $freq;
	    }
	    if($value = (float)$value){
	        $C2S['value'] = $value;
	    }
	    if($note = (string)$note){
	        $C2S['note'] = $note;
	    }
	    if(! $ret = $this->send('3220', $C2S)){
	        return array();
	    }
	    return (array)$ret;
	}
	/**
	 * 获取到价提醒
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetPriceReminder($code=''){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'market' => $this->market
	    );
	    if($code = trim($code)){
	        $C2S['security'] = array(
	            'market' => $this->market,
	            'code' => (string)$code,
	        );
	    }
	    if(! $ret = $this->send('3221', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['priceReminderList'] as $v){
	        if(! $v['code'] = $v['security']['code']){
	            continue;
	        }
	        $v['market'] = $v['security']['market'];
	        
	        unset($v['security']);
	        
	        $gets[$v['code']] = $v;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取自选股分组列表
	 * @param int $groupType 1自定义分组2系统分组3全部分组
	 * @return array
	 */
	public function Qot_GetUserSecurityGroup($groupType){
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    if(! $this->InitConnect()){
	        return array();
	    }
	    $C2S = array(
	        'groupType' => (int)$groupType
	    );
	    if(! $ret = $this->send('3222', $C2S)){
	        return array();
	    }
	    $gets = array();
	    foreach ((array)$ret['groupList'] as $v){
	        $gets[] = $v;
	    }
	    return (array)$gets;
	}
	/**
	 * 获取交易账户列表
	 * @return array
	 */
	public function Trd_GetAccList(){
		if($this->accList){
			return $this->accList;
		}
		if(! $this->InitConnect()){
			return array();
		}
		
		$C2S = array(
				'userID' => (string)$this->loginUserID,
		);
		if(! $ret = $this->send('2001', $C2S)){
			return array();
		}
		foreach ((array)$ret['accList'] as $v){
			foreach ((array)$v['trdMarketAuthList'] as $trdMarket){ //可拥有多个交易市场权限,目前仅单个
				$this->accList[$trdMarket][$v['trdEnv']] = (string)$v['accID']; 
			}
		}
		return (array)$this->accList;
	}
	/**
	 * 解锁交易(30秒10次)
	 * @param string $unlock true解锁false锁定
	 * @return bool
	 */
	public function Trd_UnlockTrade($unlock){
	    
	    $unlock = (bool)$unlock;
	    
	    if($this->unlock === $unlock){
	        return true;
	    }
	    if($this->trdEnv == 0){ //仿真环境无需解锁
	        return $this->unlock = true;
	    }
	    if(! $this->limit(__LINE__, 30, 10)){
	        return false;
	    }
	    if(! $this->InitConnect()){
	        return false;
	    }
	    
	    $C2S = array(
	        'unlock' => (bool)$unlock,
	        'pwdMD5' => md5($this->pass),
	    );
	    if(! $ret = $this->send('2005', $C2S)){
	        return false;
	    }
	    
	    $this->unlock = $unlock;
	    
	    return true;
	}
	/**
	 * 订阅接收交易账户的推送数据
	 * @return array
	 */
	public function Trd_SubAccPush(){
		if($this->accPush){
			return true;
		}
		if(! $this->Trd_UnlockTrade(true)){
			return false;
		}
		if(! $this->Trd_GetAccList()){
			return false;
		}
		if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
			return false;
		}
		$C2S = array(
				'accIDList' => (array)$accID,
		);
		if(! $ret = $this->send('2008', $C2S)){
			return array();
		}
		
		return $this->accPush = (bool)$ret;
	}
	/**
	 * 获取账户资金
	 * @param bool $refreshCache 是否强制从服务器获取数据
	 * @param int $currency 1港币,2美元,3离岸人民币;货币种类,期货账户必填,其它账户忽略
	 * @return array
	 */
	public function Trd_GetFunds($refreshCache=false, $currency=1){
	    if(! $this->Trd_UnlockTrade(true)){
	        return array();
	    }
	    if(! $this->Trd_GetAccList()){
	        return array();
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return array();
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'refreshCache' => (bool)$refreshCache,
	    );
	    if(in_array($this->trdMarket, [5])){ //期货账户必填,其它账户忽略
	        $C2S['currency'] = (int)$currency;
	    }
	    if(! $ret = $this->send('2101', $C2S)){
	        return array();
	    }
	    return (array)$ret['funds'];
	}
	/**
	 * 获取持仓列表
	 * @param array $codeList
	 * @param array $idList
	 * @param number $filterPLRatioMin
	 * @param number $filterPLRatioMax
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetPositionList($codeList=[], $idList=[], $filterPLRatioMin=0, $filterPLRatioMax=0, $refreshCache=false){
	    if(! $this->Trd_UnlockTrade(true)){
	        return array();
	    }
	    if(! $this->Trd_GetAccList()){
	        return array();
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return array();
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'refreshCache' => (bool)$refreshCache
	    );
	    if($codeList){
	        $C2S['filterConditions']['codeList'] = (array)$codeList;
	    }
	    if($idList){
	        $C2S['filterConditions']['idList'] = (array)$idList;
	    }
	    if($filterPLRatioMin){
	        $C2S['filterPLRatioMin'] = (float)$filterPLRatioMin;
	    }
	    if($filterPLRatioMax){
	        $C2S['filterPLRatioMax'] = (float)$filterPLRatioMax;
	    }
	    if(! $ret = $this->send('2102', $C2S)){
	        return array();
	    }
	    return (array)$ret['positionList'];
	}
	/**
	 * 获取最大交易数量(30秒10次)
	 * @param string $code
	 * @param float $price
	 * @param int $orderType 1普通单2市价单(仅美股)5绝对限价订单6竞价订单7竞价限价订单8特别限价订单
	 * @param number $orderID
	 * @param string $adjustPrice
	 * @param number $adjustSideAndLimit
	 * @return array
	 */
	public function Trd_GetMaxTrdQtys($code, $price, $orderType=1, $orderID=0, $adjustPrice=false, $adjustSideAndLimit=0){
	    if(! $this->Trd_UnlockTrade(true)){
	        return array();
	    }
	    if(! $this->Trd_GetAccList()){
	        return array();
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return array();
	    }
	    if(! $this->limit(__LINE__, 30, 10)){
	        return array();
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'orderType' => (int)$orderType,
	        'code' => (string)$code,
	        'price' => (float)$price,
	    );
	    if($orderID){
	        $C2S['orderID'] = (string)$orderID;
	    }
	    if($adjustPrice){
	        $C2S['adjustPrice'] = (bool)$adjustPrice;
	    }
	    if($adjustSideAndLimit){
	        $C2S['adjustSideAndLimit'] = (float)$adjustSideAndLimit;
	    }
	    if(! $ret = $this->send('2111', $C2S)){
	        return array();
	    }
	    return (array)$ret['maxTrdQtys'];
	}
	/**
	 * 获取订单列表
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中3提交失败4处理超时结果未知5已提交待成交10部分成交11全部成交12撤单剩余部分13撤单中14剩余部分撤单成功15全部已撤单21下单失败22已失效23已删除
	 * @param array $codeList
	 * @param array $idList
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetOrderList($filterStatusList=[], $beginTime=0, $endTime=0, $codeList=[], $idList=[], $refreshCache=false){
	    if(! $this->Trd_UnlockTrade(true)){
	        return array();
	    }
	    if(! $this->Trd_GetAccList()){
	        return array();
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return array();
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'refreshCache' => (bool)$refreshCache
	    );
	    if($filterStatusList){
	        $C2S['filterStatusList'] = (array)$filterStatusList;
	    }
	    if($beginTime){
	        $C2S['filterConditions']['beginTime'] = date('Y-m-d H:i:s', $beginTime);
	    }
	    if($endTime){
	        $C2S['filterConditions']['endTime'] = date('Y-m-d H:i:s', $endTime);
	    }
	    if($codeList){
	        $C2S['filterConditions']['codeList'] = (array)$codeList;
	    }
	    if($idList){
	        $C2S['filterConditions']['idList'] = (array)$idList;
	    }
	    if(! $ret = $this->send('2201', $C2S)){
	        return array();
	    }
	    
	    return (array)$ret['orderList'];
	}
	/**
	 * 下单(30秒15次)
	 * @param string $code 股票代码
	 * @param int $trdSide 0未知1买入2卖出3卖空4买回
	 * @param float $qty
	 * @param float $price
	 * @param int $orderType 0未知1普通单2市价单(仅美股)5绝对限价订单6竞价订单7竞价限价订单8特别限价订单
	 * @param bool $adjustPrice 是否调整价格:如果挂单价格不合理是否调整到合理的档位
	 * @param float $adjustSideAndLimit 如果调整价格,是向上调整(正)还是向下调整(负),最多调整多少百分比
	 * @param int $secMarket 证券所属市场 1港股正股/涡轮;2美股正股/期权;31沪市;32深市
	 * @param string $remark 用户备注字符串,最多只能传64字节,可用于标识订单唯一信息等,下单填上订单结构就会带上
	 * @return id 订单ID
	 */
	public function Trd_PlaceOrder($code, $trdSide, $qty, $price, $orderType=1, $adjustPrice=false, $adjustSideAndLimit=0, $secMarket=1, $remark=''){
	    if(! $this->Trd_UnlockTrade(true)){
	        return 0;
	    }
	    if(! $this->Trd_GetAccList()){
	        return 0;
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return 0;
	    }
	    if(! $this->limit(__LINE__, 30, 15)){
	        return 0;
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'packetID' => array(
	            'connID' => (string)$this->connID,
	            'serialNo' => (int)$this->sequence
	        ),
	        'code' => (string)$code,
	        'trdSide' => (int)$trdSide,
	        'orderType' => (int)$orderType,
	        'qty' => (float)$qty,
	        'price' => (float)$price,
	        'secMarket' => (int)$secMarket,
	        'remark' => (string)$remark
	    );
	    if($adjustPrice){
	        $C2S['adjustPrice'] = (bool)$adjustPrice;
	    }
	    if($adjustSideAndLimit){
	        $C2S['adjustSideAndLimit'] = (float)$adjustSideAndLimit;
	    }
	    if(! $ret = $this->send('2202', $C2S)){
	        return 0;
	    }
	    return (string)$ret['orderID'];
	}
	/**
	 * 修改订单(改价/改量/改状态等)(30秒20次)
	 * @param string $orderID $forAll为true时传0
	 * @param int $modifyOrderOp 0未知1改单(价格/数量)2撤单3失效4生效5删除
	 * @param float $qty
	 * @param float $price
	 * @param bool $forAll
	 * @param bool $adjustPrice
	 * @param float $adjustSideAndLimit
	 * @return number
	 */
	public function Trd_ModifyOrder($orderID, $modifyOrderOp, $qty=0, $price=0, $forAll=false, $adjustPrice=false, $adjustSideAndLimit=0){
	    if(! $this->Trd_UnlockTrade(true)){
	        return 0;
	    }
	    if(! $this->Trd_GetAccList()){
	        return 0;
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return 0;
	    }
	    if(! $this->limit(__LINE__, 30, 20)){
	        return 0;
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'packetID' => array(
	            'connID' => (string)$this->connID,
	            'serialNo' => (int)$this->sequence
	        ),
	        'orderID' => (string)$orderID,
	        'modifyOrderOp' => (int)$modifyOrderOp,
	        'forAll' => (bool)$forAll,
	    );
	    if($modifyOrderOp == 1){
	        $C2S['qty'] = (float)$qty;
	        $C2S['price'] = (float)$price;
	    }
	    if($adjustPrice){
	        $C2S['adjustPrice'] = (bool)$adjustPrice;
	    }
	    if($adjustSideAndLimit){
	        $C2S['adjustSideAndLimit'] = (float)$adjustSideAndLimit;
	    }
	    if(! $ret = $this->send('2205', $C2S)){
	        return 0;
	    }
	    return (string)$ret['orderID'];
	}
	/**
	 * 获取成交列表
	 * @param array $codeList
	 * @param array $idList
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetOrderFillList($codeList=[], $idList=[], $refreshCache=false){
	    if(! $this->Trd_UnlockTrade(true)){
	        return array();
	    }
	    if(! $this->Trd_GetAccList()){
	        return array();
	    }
	    if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
	        return array();
	    }
	    $C2S = array(
	        'header' => array(
	            'trdEnv' => $this->trdEnv,
	            'trdMarket' => $this->trdMarket,
	            'accID' => (string)$accID,
	        ),
	        'refreshCache' => (bool)$refreshCache
	    );
	    if($codeList){
	        $C2S['filterConditions']['codeList'] = (array)$codeList;
	    }
	    if($idList){
	        $C2S['filterConditions']['idList'] = (array)$idList;
	    }
	    if(! $ret = $this->send('2211', $C2S)){
	        return array();
	    }
	    return (array)$ret['orderFillList'];
	}
	/**
	 * 获取历史订单列表(30秒10次)
	 * @param int $beginTime
	 * @param int $endTime
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中3提交失败4处理超时结果未知5已提交待成交10部分成交11全部成交12撤单剩余部分13撤单中14剩余部分撤单成功15全部已撤单21下单失败22已失效23已删除
	 * @param array $codeList 股票代码过滤['00700','00388']
	 * @param array $idList 订单ID过滤
	 * @return array
	 */
	public function Trd_GetHistoryOrderList($beginTime, $endTime, $filterStatusList=[], $codeList=[], $idList=[]){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
			return array();
		}
		if(! $this->limit(__LINE__, 30, 10)){
		    return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => $this->trdEnv,
						'trdMarket' => $this->trdMarket,
						'accID' => (string)$accID,
				),
				'filterConditions' => array(
						'beginTime' => date('Y-m-d H:i:s', $beginTime),
						'endTime' => date('Y-m-d H:i:s', $endTime),
				),
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList; 
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList; 
		}
		if($filterStatusList){
			$C2S['filterStatusList'] = (array)$filterStatusList; 
		}
		if(! $ret = $this->send('2221', $C2S)){
			return array();
		}
		return (array)$ret['orderList'];
	}
	/**
	 * 获取历史成交列表(30秒10次)
	 * @param int $beginTime
	 * @param int $endTime
	 * @param array $codeList
	 * @param array $idList
	 * @return array
	 */
	public function Trd_GetHistoryOrderFillList($beginTime, $endTime, $codeList=[], $idList=[]){
		if(! $this->Trd_UnlockTrade(true)){
			return array();
		}
		if(! $this->Trd_GetAccList()){
			return array();
		}
		if(! $accID = (string)$this->accList[$this->trdMarket][$this->trdEnv]){
			return array();
		}
		if(! $this->limit(__LINE__, 30, 10)){
		    return array();
		}
		$C2S = array(
				'header' => array(
						'trdEnv' => $this->trdEnv,
						'trdMarket' => $this->trdMarket,
						'accID' => (string)$accID,
				),
				'filterConditions' => array(
						'beginTime' => date('Y-m-d H:i:s', $beginTime),
						'endTime' => date('Y-m-d H:i:s', $endTime),
				),
		);
		if($codeList){
			$C2S['filterConditions']['codeList'] = (array)$codeList;
		}
		if($idList){
			$C2S['filterConditions']['idList'] = (array)$idList;
		}
		if(! $ret = $this->send('2222', $C2S)){
			return array();
		}

		return (array)$ret['orderFillList'];
	}
	/**
	 * 编码
	 * @param int $proto
	 * @param string $C2S
	 * @return boolean|string
	 */
	public function encode($proto, $C2S){
		if(! $proto = (int)$proto){
			return false;
		}
		
		$body = $C2S; //默认不加密
		
		if($this->encrypt && ($proto == 1001)){

			$private_pkey = openssl_pkey_get_private(file_get_contents($this->private_key));
			$details_pkey = openssl_pkey_get_details($private_pkey); //由私钥计算得到公钥
			$public_pkey = openssl_pkey_get_public($details_pkey['key']);
			
			$C2S_encrypted = '';
			for($i=0, $s=substr($C2S, 0, 100); $s; $i++,$s=substr($C2S, $i*100, 100)){
				$encrypted = '';
				openssl_public_encrypt($s, $encrypted, $public_pkey, OPENSSL_PKCS1_PADDING);
				
				$C2S_encrypted .= $encrypted;
			}
			$body = $C2S_encrypted;
		}
		if($this->encrypt && ($proto != 1001)){
			
			$mod = strlen($C2S)%16;
			
			$multiplier = $mod ? (16 - $mod) : 0;
			
			$body = openssl_encrypt($C2S . str_repeat("\0", $multiplier), 'AES-128-ECB', $this->connAESKey, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING);
			
			$body .= str_repeat("\0", 15);
			$body .= chr($mod);
		}
		
		$ret = 'FT';
		$ret .= pack("L", (int)$proto); //协议ID
		$ret .= pack("C", 1); //协议格式类型,0为Protobuf格式,1为Json格式
		$ret .= pack("C", 0); //协议版本,用于迭代兼容
		$ret .= pack("L", ++$this->sequence); //包序列号,用于对应请求包和回包
		$ret .= pack("L", strlen($body)); //包体长度
		$ret .= sha1($C2S, true); //包体原始数据(解密后)的SHA1哈希值
		$ret .= pack("@8");//保留8字节扩展
		$ret .= $body;
		
		return (string)$ret;
	}
	/**
	 * 解码回包
	 * @param string $recv
	 * @param string $C2S
	 * @return array
	 */
	public function decode($recv, $C2S){
		if(empty($recv) === true){
			return array();
		}
	
		$head = substr($recv, 0, 44);
		$body = substr($recv, 44);
		
		$pack = unpack("CF/CT/Lproto/CProtoFmtType/CProtoVer/LSerialNo/LBodyLen", $head, 0);

		if($this->encrypt && ($pack['proto'] == 1001)){
			
			$private_pkey = openssl_pkey_get_private(file_get_contents($this->private_key));
		
			$body_decrypted = '';
			for($i=0, $s=substr($body, 0, 128); $s; $i++,$s=substr($body, $i*128, 128)){
				$decrypted = '';

				openssl_private_decrypt($s, $decrypted, $private_pkey, OPENSSL_PKCS1_PADDING);

				$body_decrypted .= $decrypted;
			}
			$body = $body_decrypted;
		}
		if($this->encrypt && ($pack['proto'] != 1001)){
			$mod = ord(substr($body, -1)); //补了多少个0

			$body = openssl_decrypt(substr($body, 0, -16), 'AES-128-ECB', $this->connAESKey, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING);

			$body = $mod ? substr($body, 0, $mod-16) : $body;
		}

		if(! $ret = json_decode($body, true)){
			$this->errorlog("json Error:{$pack['proto']}:{$C2S} - {$body}", 1);
			return array();
		}
		if($ret['retType'] != 0){
		    if(in_array($pack['proto'], array(3001,3203)) && preg_match('/(\d{5}$)/i', trim($ret['retMsg']), $m)){
		        $GLOBALS['FUTU_DELISTED'][$m[1]] = 1;
		    }else{
                $this->errorlog("ret Error:{$pack['proto']}:{$C2S} - {$ret['retType']}:{$ret['retMsg']}", in_array($ret['retType'], array(-1)) ? 4 : 0);
		    }
			return array();
		}
		if($ret['errCode'] != 0){
			$this->errorlog("err Error:{$pack['proto']}:{$C2S} - {$ret['errCode']}:{$ret['retMsg']}", 1);
			return array();
		}
		$f = __DIR__ . "/futu_{$pack['proto']}.txt";
		if(false && is_file($f)){ //记录服务器的返回
			file_put_contents($f, var_export($pack, true)."\n".var_export($ret, true));
		}

		return array('proto'=>$pack['proto'], 's2c'=>isset($ret['s2c'])?$ret['s2c']:['result'=>true]);
	}
	/**
	 * 私有限额方法
	 * @param int $typ 限额类型
	 * @param int $sec 多少秒
	 * @param int $cnt 多少次 比如订单为30秒20次
	 * @return boolean 是否在限额内
	 */
	private function limit($typ, $sec, $cnt){
		if(! $typ = of::uint($typ)){
			return false;
		}
		if(! $sec = of::uint($sec)){
			return false;
		}
		if(! $cnt = of::uint($cnt)){
			return false;
		}
        
		//此处实现限额逻辑,可以用一个环形的存储结构
	}
	/**
	 *
	 * @param unknown $Protocol
	 * @param unknown $ReqParam
	 * @return array
	 */
	private function send($proto, $C2S){
		if(! $this->connect()){
			return array();
		}
		if(! $C2S = json_encode(array('c2s' => $C2S))){
			return array();
		}
		
		if(! $data = $this->encode($proto, $C2S)){
			return array();
		}
		
		if(! $length = @$this->cli->send("{$data}")){
		    $this->errorlog("Send Error:{$proto} - {$C2S} - ".swoole_last_error()." - ".swoole_strerror(swoole_last_error(), 9), 0);
			return array();
		}
	
		if($this->push){ //推送模式不需要接收返回(此处必须返回空值)
			return array();
		}
		
		if(! $recv = @$this->cli->recv()){
		    $this->errorlog("Recv Error:{$proto} - {$C2S} - ".swoole_last_error()." - ".swoole_strerror(swoole_last_error(), 9), 0);
			return array();
		}
		
		if(! $ret = $this->decode($recv, $C2S)){
			return array();
		}
		
		if($ret['proto'] && ($ret['proto'] != $proto) && ! in_array($proto, [3001,3002])){
		    $this->errorlog("proto Error:{$proto} - {$C2S}", 1);
			return array();
		}
		
		return (array)$ret['s2c'];
	}
	/**
	 * 记录错误,加上断线自动重连
	 * @param unknown $msg
	 * @param number $level 0退出+日志1断线+日志2退出3断线4日志
	 * @return boolean
	 */
	private function errorlog($msg, $level=0){

	    //in_array($level, [0,1,4]) && file_put_contents(); //此处记录日志
		
		in_array($level, [1,3]) && $this->close();
		
		in_array($level, [0,2]) && die();
		
		$this->die && die("");
		
		return false;
	}
	public function __destruct(){
		
	}
}