<?php

/**
 * 富途行情及交易接口
 * @author https://github.com/hkargc/PHP-For-FutuOpenD
 * @link https://openapi.futunn.com/futu-api-doc/
 */
if (function_exists('futu_protobuf_autoloader') === false) {

	function futu_protobuf_autoloader($class) {
		if (strncmp($class, 'Futu\\', 5) === 0) {
			include_once(__DIR__ . '/' . str_replace('\\', '/', $class) . '.php');
		}
	}

	spl_autoload_register('futu_protobuf_autoloader');
}

class futu {

	/**
	 * @var Swoole\Client
	 */
	private $cli = null;

	/**
	 * 有错误是否直接退出
	 * @var bool
	 */
	private $die = false;

	/**
	 * 是否推送模式
	 * @var bool
	 */
	private $push = false;

	/**
	 * ip
	 * @var string
	 */
	private $host = '127.0.0.1';

	/**
	 * api_port
	 * @var string
	 */
	private $port = '11111';

	/**
	 * 交易解锁密码
	 * @var string
	 */
	private $pass = '888888';

	/**
	 * 请求的序列号
	 * @var integer
	 */
	private $serialNo = 0;

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
	 * 初始化的返回,心跳间隔
	 * @var integer
	 */
	private $keepAliveInterval = 5;

	/**
	 * AES加密通信CBC加密模式的iv,固定为16字节长字符串
	 * @var string
	 */
	private $aesCBCiv = '';

	/**
	 * 用户类型:1牛牛用户2MooMoo用户
	 * @var integer
	 */
	private $userAttribution = 0;

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
	 * 交易环境:0仿真1真实
	 * @var integer
	 */
	public $trdEnv = 1;

	/**
	 * 行情市场:1香港市场11美国市场21沪股市场22深股市场31新加坡市场41日本市场51澳大利亚市场61马来西亚市场71加拿大市场81外汇市场
	 * @var integer
	 */
	public $qotMarket = 1;

	/**
	 * 交易市场:1香港[证券,期权]2美国[证券,期权]3大陆市场[模拟]4A股通5期货6新加坡8澳洲10香港期货模拟11美国期货模拟12新加坡期货模拟13日本期货模拟15日本市场111马来西亚112加拿大113香港基金123美国基金
	 * @var integer
	 */
	public $trdMarket = 1;

	/**
	 * 交易证券市场[仅下单处] 1香港市场（股票、窝轮、牛熊、期权、期货等）;2美国市场（股票、期权、期货等）;31沪股市场（股票）;32深股市场（股票）;41新加坡市场（期货）;51日本市场（期货）61澳大利亚;71马来西亚;81加拿大;91外汇
	 * @var type
	 */
	public $secMarket = 1;

	/**
	 * 交易日市场calendar[仅交易日接口] 1香港市场（含股票、ETFs、窝轮、牛熊、期权、非假期交易期货；不含假期交易期货）2美国市场（含股票、ETFs、期权；不含期货）3A股市场4深（沪）股通5港股通（深、沪）6日本期货7新加坡期货
	 * @var type
	 */
	public $calMarket = 1;

	/**
	 * 账户所属券商:0模拟;1:富途证券(香港);2:moomoo证券(美国);3:moomoo证券(新加坡);4:moomoo证券(澳大利亚)
	 * @var type
	 */
	public $securityFirm = 1;

	/**
	 * 通讯加密,需同时设置$private_key(对性能肯定有影响) -1不加密;0富途修改过的AES的ECB加密模式;1标准的AES的ECB加密模式;2标准的AES的CBC加密模式
	 * @var bool
	 */
	private $PacketEncAlgo = -1;

	/**
	 * 密钥文件绝对路径,与FutuOpenD配置文件中的一致
	 * @var string
	 */
	private $private_key = __DIR__ . '/private.key';

	/**
	 * 交易账号列表
	 * @var array
	 */
	public $accList = [];

	/**
	 * 连接ID
	 * @var integer
	 */
	public $connID = 0;

	/**
	 *
	 * @var integer
	 */
	public $loginUserID = 0;

	/**
	 * 协议格式类型,0为Protobuf格式,1为Json格式
	 * @var integer
	 */
	public $ProtoFmt = 1;

	/**
	 * 初始化接口返回的加密密码
	 * @var string
	 */
	private $connAESKey = '';

	/**
	 * 协议映射
	 * @var array
	 */
	private $protos = array(
		1001 => 'InitConnect', //初始化连接
		1002 => 'GetGlobalState', //获取全局状态
		1003 => 'Notify', //事件通知推送
		1004 => 'KeepAlive', //保活心跳
		2001 => 'Trd_GetAccList', //获取交易业务账户列表
		2005 => 'Trd_UnlockTrade', //解锁或锁定交易
		2008 => 'Trd_SubAccPush', //订阅业务账户的交易推送数据
		2101 => 'Trd_GetFunds', //获取账户资金
		2102 => 'Trd_GetPositionList', //获取账户持仓
		2111 => 'Trd_GetMaxTrdQtys', //获取最大交易数量
		2201 => 'Trd_GetOrderList', //获取订单列表
		2202 => 'Trd_PlaceOrder', //下单
		2205 => 'Trd_ModifyOrder', //修改订单
		2208 => 'Trd_UpdateOrder', //推送订单状态变动通知
		2211 => 'Trd_GetOrderFillList', //获取成交列表
		2218 => 'Trd_UpdateOrderFill', //推送成交通知
		2221 => 'Trd_GetHistoryOrderList', //获取历史订单列表
		2222 => 'Trd_GetHistoryOrderFillList', //获取历史成交列表
		2223 => 'Trd_GetMarginRatio', //获取融资融券数据
		2225 => 'Trd_GetOrderFee', //获取费用
		2226 => 'Trd_FlowSummary', //查询账户现金流水
		3001 => 'Qot_Sub', //订阅或者反订阅
		3003 => 'Qot_GetSubInfo', //获取订阅信息
		3004 => 'Qot_GetBasicQot', //获取股票基本报价
		3005 => 'Qot_UpdateBasicQot', //推送股票基本报价
		3006 => 'Qot_GetKL', //获取 K 线
		3007 => 'Qot_UpdateKL', //推送 K 线
		3008 => 'Qot_GetRT', //获取分时
		3009 => 'Qot_UpdateRT', //推送分时
		3010 => 'Qot_GetTicker', //获取逐笔
		3011 => 'Qot_UpdateTicker', //推送逐笔
		3012 => 'Qot_GetOrderBook', //获取买卖盘
		3013 => 'Qot_UpdateOrderBook', //推送买卖盘
		3014 => 'Qot_GetBroker', //获取经纪队列
		3015 => 'Qot_UpdateBroker', //推送经纪队列
		3019 => 'Qot_UpdatePriceReminder', //到价提醒通知
		3103 => 'Qot_RequestHistoryKL', //在线获取单只股票一段历史 K 线
		3104 => 'Qot_RequestHistoryKLQuota', //获取历史 K 线额度
		3105 => 'Qot_RequestRehab', //在线获取单只股票复权信息
		3202 => 'Qot_GetStaticInfo', //获取股票静态信息
		3203 => 'Qot_GetSecuritySnapshot', //获取股票快照
		3204 => 'Qot_GetPlateSet', //获取板块集合下的板块
		3205 => 'Qot_GetPlateSecurity', //获取板块下的股票
		3206 => 'Qot_GetReference', //获取正股相关股票
		3207 => 'Qot_GetOwnerPlate', //获取股票所属板块
		3209 => 'Qot_GetOptionChain', //获取期权链
		3210 => 'Qot_GetWarrant', //获取窝轮
		3211 => 'Qot_GetCapitalFlow', //获取资金流向
		3212 => 'Qot_GetCapitalDistribution', //获取资金分布
		3213 => 'Qot_GetUserSecurity', //获取自选股分组下的股票
		3214 => 'Qot_ModifyUserSecurity', //修改自选股分组下的股票
		3215 => 'Qot_StockFilter', //获取条件选股
		3217 => 'Qot_GetIpoList', //获取新股
		3218 => 'Qot_GetFutureInfo', //获取期货合约资料
		3219 => 'Qot_RequestTradeDate', //获取市场交易日，在线拉取不在本地计算
		3220 => 'Qot_SetPriceReminder', //设置到价提醒
		3221 => 'Qot_GetPriceReminder', //获取到价提醒
		3222 => 'Qot_GetUserSecurityGroup', //获取自选股分组列表
		3223 => 'Qot_GetMarketState', //获取指定品种的市场状态
		3224 => 'Qot_GetOptionExpirationDate' //获取期权到期日
	);

	/**
	 * @param string $host
	 * @param string $port
	 * @param string $pass 交易解锁密码
	 */
	public function __construct($host, $port, $pass = '') {
		$this->host = $host;
		$this->port = $port;
		$this->pass = $pass;

		if (!class_exists('Swoole\Client')) { //强制使用
			die('http://pecl.php.net/package/swoole');
		}
	}

	/**
	 * @param Swoole\Coroutine\Client $cli
	 * @return boolean
	 */
	public function push($cli) {
		if (!$cli instanceof Swoole\Coroutine\Client) {
			return false;
		}
		$this->cli = $cli;
		$this->push = true; //是否推送模式
	}

	private function connect() {
		if ($this->cli === null) {
			$this->timer = time(); //初始化心跳时间
			if (filter_var($this->host, FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_IPV4])) {
				$type = SWOOLE_SOCK_TCP;
			} else if (filter_var($this->host, FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_IPV6])) {
				$type = SWOOLE_SOCK_TCP6;
			} else if (is_file($this->host)) {
				$type = SWOOLE_SOCK_UNIX_STREAM;
			}
			$this->cli = new Swoole\Client($type);
			$this->cli->set(array(
				'socket_buffer_size' => 1024 * 1024 * 32, //32M缓存区
				'open_length_check' => 1,
				'package_length_type' => 'V',
				'package_length_offset' => 12, //第N个字节是包长度的值
				'package_body_offset' => 44, //第几个字节开始计算长度
				'package_max_length' => 8 * 1024 * 1024, //协议最大长度
				'open_tcp_nodelay' => false
			));
			if (!@$this->cli->connect($this->host, $this->port, 8)) {
				$this->errorlog('Connect Error.' . socket_strerror($this->cli->errCode), 0);
			}
		}
		if (!$this->cli) {
			$this->errorlog('Client Error.', 0);
		}
		if ($this->timer && (time() - $this->timer >= $this->keepAliveInterval)) { //每N秒发一次心跳(只有同步模式会初始化时间)
			$this->timer = time(); //锁住
			$this->KeepAlive();
		}
		return $this->cli;
	}

	/**
	 * 关闭连接,销毁之前的数据
	 */
	public function close() {
		if ($this->cli instanceof Swoole\Coroutine\Client) {
			$this->cli->close();
			exit(0);
		}

		if ($this->cli instanceof Swoole\Client) {
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
	public function InitConnect() {
		if ($this->connID) { //已经初始化过
			return $this->connID;
		}
		$C2S = array(
			"clientVer" => -1, //pb模式不能是0
			'clientID' => '0', //pb模式不能为空
			'recvNotify' => $this->push ? true : false,
			'packetEncAlgo' => (int) $this->PacketEncAlgo,
			'pushProtoFmt' => (int) $this->ProtoFmt,
			'programmingLanguage' => ''
		);
		if (!$ret = $this->send('1001', $C2S)) {
			return '';
		}

		$this->serverVer = (int) $ret['serverVer'];
		$this->loginUserID = (string) $ret['loginUserID']; //uint64
		$this->connID = (string) $ret['connID']; //uint64
		$this->connAESKey = (string) $ret['connAESKey'];
		$this->keepAliveInterval = (int) $ret['keepAliveInterval'];
		$this->aesCBCiv = (string) $ret['aesCBCiv'];
		$this->userAttribution = (int) $ret['userAttribution'];

		return $this->connID;
	}

	/**
	 * 获取全局状态 
	 * @return array
	 */
	public function GetGlobalState() {
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'userID' => 1 //历史原因,目前已废弃
		);
		if (!$ret = $this->send('1002', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 保活心跳
	 * @return int
	 */
	public function KeepAlive() {
		if (!$this->InitConnect()) {
			return 0;
		}
		$C2S = array(
			'time' => (int) time()
		);
		if (!$ret = $this->send('1004', $C2S)) {
			return 0;
		}

		return (int) $ret['time'];
	}

	/**
	 * 订阅或者反订阅,同时注册或者取消推送(股票个数*K线种类<=100)
	 * @param array $codes
	 * @param array $subTypeList 1报价;2摆盘;4逐笔;5分时;6日K;7五分K;8十五分K;9三十K;10六十K;11一分K;12周K;13月K;14经纪队列;15季K;16年K;17三分K;
	 * @param bool $isSubOrUnSub true订阅false反订阅(pb协议不支持反订阅)
	 * @param bool $isRegOrUnRegPush 是否注册或反注册该连接上面行情的推送,该参数不指定不做注册反注册操作
	 * @param array $regPushRehabTypeList 复权类型:0不复权1前复权2后复权
	 * @param bool $isFirstPush 注册后如果本地已有数据是否首推一次已存在数据
	 * @param bool $isUnsubAll 一键取消当前连接的所有订阅,当被设置为true时忽略其他参数
	 * @param bool $isSubOrderBookDetail 是否订阅摆盘明细
	 * @param bool $extendedTime 是否允许美股盘前盘后数据
	 * @return bool
	 */
	public function Qot_Sub($codes, $subTypeList, $isSubOrUnSub = true, $isRegOrUnRegPush = null, $regPushRehabTypeList = [], $isFirstPush = false, $isUnsubAll = false, $isSubOrderBookDetail = false, $extendedTime = false) {
		if (!$this->InitConnect()) {
			return false;
		}
		$C2S = array(
			'isSubOrUnSub' => (bool) $isSubOrUnSub, //true订阅false反订阅(pb协议不支持反订阅)
			'isFirstPush' => (bool) $isFirstPush,
			'isUnsubAll' => (bool) $isUnsubAll
		);
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (($isUnsubAll == false) && empty($securityList)) {
			return false;
		}
		if ($securityList) {
			$C2S['securityList'] = $securityList;
		}
		if ($subTypeList = (array) $subTypeList) { //订阅数据类型
			$C2S['subTypeList'] = array_unique(array_values($subTypeList));
		}
		if ($isRegOrUnRegPush !== null) {
			$C2S['isRegOrUnRegPush'] = (bool) $isRegOrUnRegPush;
		}
		if ($isRegOrUnRegPush && $regPushRehabTypeList) {
			$C2S['regPushRehabTypeList'] = (array) $regPushRehabTypeList;
		}
		if ($isSubOrderBookDetail) {
			$C2S['isSubOrderBookDetail'] = (bool) $isSubOrderBookDetail;
		}
		if ($extendedTime) {
			$C2S['extendedTime'] = (bool) $extendedTime;
		}
		if (!$ret = $this->send('3001', $C2S)) {
			return false;
		}

		return isset($ret['result']) ? (bool) $ret['result'] : true;
	}

	/**
	 * 获取订阅信息
	 * @param bool $isReqAllConn 是否返回所有连接的订阅状态,不传或者传false只返回当前连接数据
	 */
	public function Qot_GetSubInfo($isReqAllConn = false) {
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'isReqAllConn' => (bool) $isReqAllConn
		);
		if (!$ret = $this->send('3003', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取股票基本行情
	 * @param array $codes
	 * @return array
	 */
	public function Qot_GetBasicQot($codes) {
		if (!$this->Qot_Sub($codes, [1], true)) {
			return array();
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return array();
		}
		$C2S = array(
			'securityList' => (array) $securityList
		);
		if (!$ret = $this->send('3004', $C2S)) {
			return array();
		}
		return (array) $ret['basicQotList'];
	}

	/**
	 * 获取K线
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $reqNum K线条数
	 * @param int $rehabType 复权类型:0不复权1前复权2后复权(pb协议不能传0)
	 */
	public function Qot_GetKL($code, $klType, $reqNum = 1000, $rehabType = 1) {
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
		if (!$this->Qot_Sub($code, [$map[$klType]], true)) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'klType' => (int) $klType,
			'reqNum' => (int) $reqNum,
			'rehabType' => (int) $rehabType //pb协议不能传0
		);
		if (!$ret = $this->send('3006', $C2S)) {
			return array();
		}

		return (array) $ret['klList'];
	}

	/**
	 * 获取分时
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetRT($code) {
		if (!$this->Qot_Sub($code, [5], true)) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			)
		);
		if (!$ret = $this->send('3008', $C2S)) {
			return array();
		}
		return (array) $ret['rtList'];
	}

	/**
	 * 获取逐笔
	 * @param string $code
	 * @param int $maxRetNum
	 * @return array
	 */
	public function Qot_GetTicker($code, $maxRetNum = 1000) {
		if (!$this->Qot_Sub($code, [4], true)) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'maxRetNum' => (int) $maxRetNum
		);
		if (!$ret = $this->send('3010', $C2S)) {
			return array();
		}
		return (array) $ret['tickerList'];
	}

	/**
	 * 获取买卖盘
	 * @param string $code
	 * @param int $num
	 * @return array
	 */
	public function Qot_GetOrderBook($code, $num = 10) {
		if (!$this->Qot_Sub($code, [2], true)) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'num' => (int) $num
		);
		if (!$ret = $this->send('3012', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取经纪队列
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetBroker($code) {
		if (!$this->Qot_Sub($code, [14], true)) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			)
		);
		if (!$ret = $this->send('3014', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取单只股票一段历史K线(有限额)分K提供最近8年数据,日K及以上提供近10年数据
	 * @param string $code
	 * @param int $klType K线类型:1一分K;2日K;3周K;4月K;5年K;6五分K;7十五分K;8三十分K;9六十分K;10三分K;11季K
	 * @param int $beginTime
	 * @param int $endTime
	 * @param int $maxAckKLNum 最多返回多少根K线,如果未指定表示不限制
	 * @param int $nextReqKey 分页请求key(在返回的数组中)
	 * @param int $needKLFieldsFlag 指定返回K线结构体特定某几项数据,KLFields枚举值或组合,如果未指定返回全部字段
	 * @param int $rehabType 复权类型:0不复权1前复权2后复权(pb协议不能传0)
	 * @param bool $extendedTime 是否获取美股盘前盘后数据,当前仅支持1分K
	 * @return array
	 */
	public function Qot_RequestHistoryKL($code, $klType, $beginTime, $endTime, $maxAckKLNum = 0, $nextReqKey = null, $needKLFieldsFlag = [], $rehabType = 1, $extendedTime = false) {
		if (empty($nextReqKey)) {
			if (!$this->limit(__LINE__, 30, 60, 0)) {
				return array();
			}
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'klType' => (int) $klType,
			'beginTime' => date("Y-m-d H:i:s", $beginTime),
			'endTime' => date("Y-m-d H:i:s", $endTime),
			'rehabType' => (int) $rehabType //pb协议不能传0
		);
		if ($maxAckKLNum) {
			$C2S['maxAckKLNum'] = (int) $maxAckKLNum;
		}
		if ($nextReqKey) {
			$C2S['nextReqKey'] = (string) $nextReqKey;
		}
		if ($needKLFieldsFlag) {
			$C2S['needKLFieldsFlag'] = (array) $needKLFieldsFlag;
		}
		if ($extendedTime) {
			$C2S['extendedTime'] = (bool) $extendedTime;
		}
		if (!$ret = $this->send('3103', $C2S)) {
			return array();
		}
		return $maxAckKLNum ? [(string) $ret['nextReqKey'], (array) $ret['klList']] : (array) $ret['klList'];
	}

	/**
	 * 拉取历史K线已经用掉的额度
	 * @param bool $bGetDetail 是否拉取详细列表
	 * @return array
	 */
	public function Qot_RequestHistoryKLQuota($bGetDetail = false) {
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'bGetDetail' => (bool) $bGetDetail
		);
		if (!$ret = $this->send('3104', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取复权信息
	 * @param array $code
	 * @return array/false
	 */
	public function Qot_RequestRehab($code) {
		if (!$this->limit(__LINE__, 30, 60, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			)
		);
		if (!$ret = $this->send('3105', $C2S)) {
			return array();
		}
		return (array) $ret['rehabList'];
	}

	/**
	 * 获取股票列表
	 * @param int $secType 0未知或指定股票1债券2权证3正股4基金5涡轮6指数7板块8期权9板块集合10期货
	 * @param array $codes 股票,若该参数存在,忽略其他参数
	 * @return array
	 */
	public function Qot_GetStaticInfo($secType, $codes = array()) {
		if (!$this->InitConnect()) {
			return array();
		}

		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if ($securityList) {
			$C2S = array(
				'securityList' => (array) $securityList
			);
		} else {
			$C2S = array(
				'market' => (int) $this->qotMarket,
				'secType' => (int) $secType
			);
		}
		if (!$ret = $this->send('3202', $C2S)) {
			return array();
		}
		return (array) $ret['staticInfoList'];
	}

	/**
	 * 获取一批股票的快照信息,每次最多400支
	 * @param array $codes
	 * @return array
	 */
	public function Qot_GetSecuritySnapshot($codes) {
		if ($this->push == false) {
			if (!$this->limit(__LINE__, 30, 60, 0)) {
				return array();
			}
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return array();
		}
		$C2S = array(
			'securityList' => (array) $securityList
		);
		if (!$ret = $this->send('3203', $C2S)) {
			return array();
		}
		return (array) $ret['snapshotList'];
	}

	/**
	 * 获取板块集合下的板块(30秒10次)
	 * @param int $plateSetType 0所有版块1行业板块2地域板块港美股市场的地域分类数据暂为空3概念版块4其他板块仅用于3207（获取股票所属板块）协议返回,不可作为其他协议的请求参数 (pb协议不能传0)
	 * @return array
	 */
	public function Qot_GetPlateSet($plateSetType) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'plateSetType' => (int) $plateSetType, //pb协议不能传0
			'market' => $this->qotMarket
		);
		if (!$ret = $this->send('3204', $C2S)) {
			return array();
		}
		return (array) $ret['plateInfoList'];
	}

	/**
	 * 获取板块下的股票(30秒10次)
	 * @param string $code 版块编号(如期货板块:BK1986)
	 * @param int $sortField 排序字段
	 * @param bool $ascend 是否升序
	 * @return array
	 */
	public function Qot_GetPlateSecurity($code, $sortField = 1, $ascend = true) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$C2S = array(
			'plate' => array(
				'code' => (string) $code,
				'market' => $this->qotMarket($code)
			),
			'sortField' => (int) $sortField,
			'ascend' => (bool) $ascend
		);
		if (!$ret = $this->send('3205', $C2S)) {
			return array();
		}
		return (array) $ret['staticInfoList'];
	}

	/**
	 * 获取正股相关股票
	 * @param string $code 正股代码
	 * @param int $referenceType 1正股相关的窝轮;2期货主连的相关合约
	 * @return array
	 */
	public function Qot_GetReference($code, $referenceType = 1) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'referenceType' => (int) $referenceType
		);
		if (!$ret = $this->send('3206', $C2S)) {
			return array();
		}
		return (array) $ret['staticInfoList'];
	}

	/**
	 * 获取股票所属板块(30秒10次)
	 * @param array $codes 最多200个,仅支持正股和指数
	 */
	public function Qot_GetOwnerPlate($codes) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return array();
		}
		$C2S = array(
			'securityList' => (array) $securityList
		);
		if (!$ret = $this->send('3207', $C2S)) {
			return array();
		}
		return (array) $ret['ownerPlateList'];
	}

	/**
	 * 获取期权链
	 */
	public function Qot_GetOptionChain() {
		
	}

	/**
	 * 获取期权链到期日
	 * @param string $code
	 * @param int $indexOptionType 1普通的指数期权;2小型指数期权
	 * @return array
	 */
	public function Qot_GetOptionExpirationDate($code, $indexOptionType = 1) {
		if (!$this->limit(__LINE__, 30, 60, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'owner' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			),
			'indexOptionType' => (int) $indexOptionType
		);
		if (!$ret = $this->send('3224', $C2S)) {
			return array();
		}
		return (array) $ret['dateList'];
	}

	/**
	 * 获取涡轮
	 * @param array $filter
	 * @param number $begin 数据起始点(pb协议不能传0)
	 * @param number $num 请求数据个数,最大200
	 * @param number $sortField 根据哪个字段排序
	 * @param string $ascend 升序ture,降序false(pb协议不支持false)
	 */
	public function Qot_GetWarrant($code, $filter = array(), $begin = 0, $num = 200, $sortField = 10, $ascend = true) {
		if (!$this->limit(__LINE__, 30, 60, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'begin' => (int) $begin,
			'num' => (int) $num,
			'sortField' => (int) $sortField,
			'ascend' => (bool) $ascend //pb协议不支持false
		);
		foreach ((array) $filter as $k => $v) {
			$C2S[$k] = $v;
		}
		if ($code) {
			$C2S['owner'] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$ret = $this->send('3210', $C2S)) {
			return array();
		}
		$gets = array();
		foreach ((array) $ret['warrantDataList'] as $v) {
			if (!$v['code'] = (string) $v['stock']['code']) {
				continue;
			}
			if (!$v['owner'] = (string) $v['owner']['code']) {
				continue;
			}

			unset($v['stock']);

			$gets[$v['code']] = $v;
		}
		return (array) $gets;
	}

	/**
	 * 获取资金流向,仅支持正股、窝轮和基金
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetCapitalFlow($code) {
		if (!$this->limit(__LINE__, 30, 30, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			)
		);
		if (!$ret = $this->send('3211', $C2S)) {
			return array();
		}
		return (array) $ret['flowItemList'];
	}

	/**
	 * 获取资金分布
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetCapitalDistribution($code) {
		if (!$this->limit(__LINE__, 30, 30, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			)
		);
		if (!$ret = $this->send('3212', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取自选股分组下的股票
	 * @param string $groupName
	 * @return array/false
	 */
	public function Qot_GetUserSecurity($groupName) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$C2S = array(
			'groupName' => (string) $groupName
		);
		if (!$ret = $this->send('3213', $C2S)) {
			return array();
		}
		return (array) $ret['staticInfoList'];
	}

	/**
	 * 修改自选股分组下的股票
	 * @param string $groupName
	 * @param int $op 1新增 2删除3移出
	 * @param array $codes
	 * @return boolean
	 */
	public function Qot_ModifyUserSecurity($groupName, $op, $codes) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return false;
		}

		$C2S = array(
			'groupName' => (string) $groupName,
			'op' => (int) $op,
			'securityList' => (array) $securityList
		);
		if (!$ret = $this->send('3214', $C2S)) {
			return false;
		}
		return (bool) $ret;
	}

	/**
	 * 获取条件选股
	 * @param array $filter
	 * @param number $plate
	 * @param number $begin (pb协议不能传0)
	 * @param number $num
	 * @return array
	 */
	public function Qot_StockFilter($filter = array(), $plate = 0, $begin = 0, $num = 200) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'begin' => (int) $begin, //pb协议不能传0
			'num' => (int) $num,
			'market' => (int) $this->qotMarket
		);
		foreach ((array) $filter as $k => $v) {
			$C2S[$k] = $v;
		}
		if ($plate) {
			$C2S['plate'] = array(
				'market' => (int) $this->qotMarket,
				'code' => (string) $plate
			);
		}
		if (!$ret = $this->send('3215', $C2S)) {
			return array();
		}
		$gets = array();
		foreach ($ret['dataList'] as $v) {
			if (!$v['code'] = $v['security']['code']) {
				continue;
			}
			$v['market'] = $v['security']['market'];

			unset($v['security']);

			$gets[$v['code']] = $v;
		}
		return (array) $gets;
	}

	/**
	 * 获取IPO信息
	 * @return array
	 */
	public function Qot_GetIpoList() {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'market' => (int) $this->qotMarket
		);
		if (!$ret = $this->send('3217', $C2S)) {
			return array();
		}
		return (array) $ret['ipoList'];
	}

	/**
	 * 获取期货合约资料
	 * @param array $codes 传入股票最多 200 个
	 * @return array
	 */
	public function Qot_GetFutureInfo($codes) {
		if (!$this->limit(__LINE__, 30, 30, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return array();
		}
		$C2S = array(
			'securityList' => (array) $securityList
		);
		if (!$ret = $this->send('3218', $C2S)) {
			return array();
		}
		return (array) $ret['futureInfoList'];
	}

	/**
	 * 在线请求交易日
	 * @param int $beginTime
	 * @param int $endTime
	 * @param int $calMarket 1香港市场（含股票、ETFs、窝轮、牛熊、期权、非假期交易期货；不含假期交易期货）2美国市场（含股票、ETFs、期权；不含期货）3A股市场4深（沪）股通5港股通（深、沪）6日本期货7新加坡期货
	 * @param string $code 如果指定则为该标的所在市场的交易日
	 * @return array tradeDateType 0全天;1上午;2下午
	 */
	public function Qot_RequestTradeDate($beginTime, $endTime, $calMarket = 1, $code = null) {
		if (!$this->limit(__LINE__, 30, 30, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'market' => (int) $calMarket,
			'beginTime' => date('Y-m-d', $beginTime), //开始时间字符串
			'endTime' => date('Y-m-d', $endTime) //结束时间字符串
		);
		if ($code) {
			$C2S['security'] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$ret = $this->send('3219', $C2S)) {
			return array();
		}
		return (array) $ret['tradeDateList'];
	}

	/**
	 * 设置到价提醒
	 * @param string $code
	 * @param int $op 操作类型:1新增 2删除 3启用 4禁用 5修改6删除该支股票下所有到价提醒
	 * @param int $key 到价提醒的标识,Qot_GetPriceReminder协议可获得,用于指定要操作的到价提醒项,对于新增的情况不需要填
	 * @param int $type Qot_Common::PriceReminderType,提醒类型,删除/启用/禁用的情况下会忽略该字段 
	 * @param int $freq Qot_Common::PriceReminderFreq,提醒频率类型,删除/启用/禁用的情况下会忽略该字段 1持续提醒 2每日一次 3仅提醒一次
	 * @param float $value 提醒值,删除/启用/禁用的情况下会忽略该字段
	 * @param string $note 用户设置到价提醒时的标注,最多10个字符,删除/启用/禁用的情况下会忽略该字段
	 */
	public function Qot_SetPriceReminder($code, $op, $key = 0, $type = 0, $freq = 0, $value = 0, $note = '') {
		if (!$this->limit(__LINE__, 30, 60, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'security' => array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code,
			),
			'op' => (int) $op
		);
		if ($key = (int) $key) {
			$C2S['key'] = $key;
		}
		if ($type = (int) $type) {
			$C2S['type'] = $type;
		}
		if ($freq = (int) $freq) {
			$C2S['freq'] = $freq;
		}
		if ($value = (float) $value) {
			$C2S['value'] = $value;
		}
		if ($note = (string) $note) {
			$C2S['note'] = $note;
		}
		if (!$ret = $this->send('3220', $C2S)) {
			return array();
		}
		return (array) $ret;
	}

	/**
	 * 获取到价提醒
	 * @param string $code
	 * @return array
	 */
	public function Qot_GetPriceReminder($code = null) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}
		$C2S = array(
			'market' => (int) $this->qotMarket
		);
		if ($code) {
			$C2S['security'] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$ret = $this->send('3221', $C2S)) {
			return array();
		}
		$gets = array();
		foreach ((array) $ret['priceReminderList'] as $v) {
			if (!$v['code'] = $v['security']['code']) {
				continue;
			}
			$v['market'] = $v['security']['market'];

			unset($v['security']);

			$gets[$v['code']] = $v;
		}

		return (array) $gets;
	}

	/**
	 * 获取自选股分组列表
	 * @param int $groupType 1自定义分组2系统分组3全部分组
	 * @return array/false
	 */
	public function Qot_GetUserSecurityGroup($groupType) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}
		$C2S = array(
			'groupType' => (int) $groupType
		);
		if (!$ret = $this->send('3222', $C2S)) {
			return array();
		}
		return (array) $ret['groupList'];
	}

	/**
	 * 获取指定品种的市场状态
	 * @return array
	 */
	public function Qot_GetMarketState($codes) {
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		if (!$this->InitConnect()) {
			return array();
		}

		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList = (array) $securityList) {
			return array();
		}
		$C2S = array(
			'securityList' => $securityList
		);
		if (!$ret = $this->send('3223', $C2S)) {
			return array();
		}
		return (array) $ret['marketInfoList'];
	}

	/**
	 * 
	 * @param int $trdMarket
	 * @return int
	 */
	public function accID(int $trdMarket): string {
		if (!$accList = (array) $this->Trd_GetAccList()) {
			return '';
		}
		if (!$trdMarket = (int)$trdMarket) {
			$trdMarket = $this->trdMarket;
		}
		$accIDList = [];
		$simAccType = 0; //模拟交易账号类型 0非模拟账户 1股票模拟账户（仅用于交易证券类产品，不支持交易期权）2期权模拟账户（仅用于交易期权，不支持交易股票证券类产品）3期货模拟账户
		if (empty($this->trdEnv)) { //模拟交易
			$simAccType = 1; //默认股票模拟,不支持期权模拟
			if (in_array($trdMarket, [10, 11, 12, 13], true)) { //期货模拟
				$simAccType = 3;
			}
		}
		foreach ($accList as $a) {
			if ($a['accStatus']) {
				continue;
			}
			if (intval($a['securityFirm']) != $this->securityFirm) {
				continue;
			}
			if (intval($a['trdEnv']) != $this->trdEnv) {
				continue;
			}
			if (!in_array($trdMarket, (array) $a['trdMarketAuthList'])) {
				continue;
			}
			if (intval($a['simAccType']) != $simAccType) {
				continue;
			}
			if (empty($a['accID'])) {
				continue;
			}
			$accIDList[] = (string) $a['accID'];
		}
		if (empty($accIDList)) {
			return '';
		}
		return array_shift($accIDList);
	}

	/**
	 * 获取交易业务账户列表
	 * @return array
	 */
	public function Trd_GetAccList() {
		if ($this->accList) {
			return $this->accList;
		}
		if (!$this->InitConnect()) {
			return array();
		}

		$C2S = array(
			'userID' => (string) $this->loginUserID, //目前已废弃
			'trdCategory' => 0, //交易品类:1证券2期货
			'needGeneralSecAccount' => true //是否返回综合账户
		);
		if (!$ret = $this->send('2001', $C2S)) {
			return array();
		}
		return $this->accList = (array) $ret['accList'];
	}

	/**
	 * 解锁交易(30秒10次)
	 * @param string $unlock true解锁false锁定(pb协议不支持false)
	 * @return bool
	 */
	public function Trd_UnlockTrade($unlock) {

		$unlock = (bool) $unlock;

		if ($this->unlock === $unlock) {
			return true;
		}
		if ($this->trdEnv == 0) { //仿真环境无需解锁
			return $this->unlock = true;
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return false;
		}
		if (!$this->InitConnect()) {
			return false;
		}

		$C2S = array(
			'unlock' => (bool) $unlock, //pb协议不支持false
			'pwdMD5' => md5($this->pass),
			'securityFirm' => 1 //0未知;1富途证券香港;2富途证券美国;3富途证券新加坡
		);
		if (!$ret = $this->send('2005', $C2S)) {
			return false;
		}

		$this->unlock = $unlock;

		return true;
	}

	/**
	 * 订阅交易推送
	 * @param type $trdMarkets
	 * @return bool|array
	 */
	public function Trd_SubAccPush($trdMarkets) {
		if ($this->accPush) {
			return true;
		}
		$accIDList = array();
		foreach ((array) $trdMarkets as $trdMarket) {
			if ($accID = (string) $this->accID($trdMarket)) {
				$accIDList[] = $accID;
			}
		}
		if (!$accIDList) {
			return false;
		}

		$C2S = array(
			'accIDList' => (array) $accIDList
		);
		if (!$ret = $this->send('2008', $C2S)) {
			return array();
		}
		return $this->accPush = (bool) $ret;
	}

	/**
	 * 获取账户资金
	 * @param bool $refreshCache 是否强制从服务器获取数据
	 * @param int $currency 1港币,2美元,3离岸人民币;货币种类,期货账户必填,其它账户忽略
	 * @return array
	 */
	public function Trd_GetFunds($refreshCache = false, $currency = 1) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if ($refreshCache) {
			if (!$this->limit(__LINE__, 30, 10, 0)) {
				return array();
			}
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'refreshCache' => (bool) $refreshCache
		);
		if (in_array($this->trdMarket, [5])) { //期货账户必填,其它账户忽略
			$C2S['currency'] = (int) $currency;
		}
		if (!$ret = $this->send('2101', $C2S)) {
			return array();
		}
		return (array) $ret['funds'];
	}

	/**
	 * 查询交易业务账户的持仓列表
	 * @param array $codeList
	 * @param array $idList
	 * @param int $filterMarket 指定交易市场,参见TrdMarket的枚举定义
	 * @param number $filterPLRatioMin
	 * @param number $filterPLRatioMax
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetPositionList($codeList = [], $idList = [], $filterMarket = 0, $filterPLRatioMin = 0, $filterPLRatioMax = 0, $refreshCache = false) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if ($refreshCache) {
			if (!$this->limit(__LINE__, 30, 10, 0)) {
				return array();
			}
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'refreshCache' => (bool) $refreshCache
		);
		if ($codeList) {
			$C2S['filterConditions']['codeList'] = (array) $codeList;
		}
		if ($idList) {
			$C2S['filterConditions']['idList'] = (array) $idList;
		}
		if ($filterMarket) {
			$C2S['filterConditions']['filterMarket'] = (int) $filterMarket;
		}
		if ($filterPLRatioMin) {
			$C2S['filterPLRatioMin'] = (float) $filterPLRatioMin;
		}
		if ($filterPLRatioMax) {
			$C2S['filterPLRatioMax'] = (float) $filterPLRatioMax;
		}
		if (!$ret = $this->send('2102', $C2S)) {
			return array();
		}
		return (array) $ret['positionList'];
	}

	/**
	 * 获取最大交易数量(30秒10次)
	 * @param string $code
	 * @param float $price
	 * @param int $orderType 1限价单2市价单(仅美股)5绝对限价订单6竞价订单7竞价限价订单8特别限价订单
	 * @param string $orderIDEx
	 * @param string $adjustPrice
	 * @param number $adjustSideAndLimit
	 * @return array
	 */
	public function Trd_GetMaxTrdQtys($code, $price, $orderType = 1, $orderIDEx = '', $adjustPrice = false, $adjustSideAndLimit = 0) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'orderType' => (int) $orderType,
			'code' => (string) $code,
			'price' => (float) $price
		);
		if ($orderIDEx) {
			$C2S['orderIDEx'] = (string) $orderIDEx;
		}
		if ($adjustPrice) {
			$C2S['adjustPrice'] = (bool) $adjustPrice;
		}
		if ($adjustSideAndLimit) {
			$C2S['adjustSideAndLimit'] = (float) $adjustSideAndLimit;
		}
		if (!$ret = $this->send('2111', $C2S)) {
			return array();
		}
		return (array) $ret['maxTrdQtys'];
	}

	/**
	 * 查询未完成订单
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中5已提交等待成交10部分成交11全部成交14部分成交且剩余部分已撤单15全部已撤单且无成交21下单失败服务拒绝22已失效23已删除24成交被撤销
	 * @param array $codeList
	 * @param array $orderIDExList
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetOrderList($filterStatusList = [], $beginTime = 0, $endTime = 0, $codeList = [], $orderIDExList = [], $filterMarket = 0, $refreshCache = false) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if ($refreshCache) {
			if (!$this->limit(__LINE__, 30, 10, 0)) {
				return array();
			}
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'refreshCache' => (bool) $refreshCache
		);
		if ($filterStatusList) {
			$C2S['filterStatusList'] = (array) $filterStatusList;
		}
		if ($beginTime) {
			$C2S['filterConditions']['beginTime'] = date('Y-m-d H:i:s', $beginTime);
		}
		if ($endTime) {
			$C2S['filterConditions']['endTime'] = date('Y-m-d H:i:s', $endTime);
		}
		if ($codeList) {
			$C2S['filterConditions']['codeList'] = (array) $codeList;
		}
		if ($orderIDExList) {
			$C2S['filterConditions']['orderIDExList'] = (array) $orderIDExList;
		}
		if ($filterMarket) {
			$C2S['filterConditions']['filterMarket'] = (int) $filterMarket;
		}
		if (!$ret = $this->send('2201', $C2S)) {
			return array();
		}

		return (array) $ret['orderList'];
	}

	/**
	 * 下单(30秒15次)
	 * @param string $code 股票代码
	 * @param int $trdSide 0未知1买入2卖出3沽入4沽出
	 * @param float $qty
	 * @param float $price
	 * @param int $orderType 1限价单2市价单(仅美股)5绝对限价订单6竞价订单7竞价限价订单8特别限价订单
	 * @param bool $adjustPrice 是否调整价格:如果挂单价格不合理是否调整到合理的档位
	 * @param float $adjustSideAndLimit 如果调整价格,是向上调整(正)还是向下调整(负),最多调整多少百分比
	 * @param int $secMarket 证券所属市场 1香港市场（股票、窝轮、牛熊、期权、期货等）;2美国市场（股票、期权、期货等）;31沪股市场（股票）;32深股市场（股票）;41新加坡市场（期货）;51日本市场（期货）61澳大利亚;71马来西亚;81加拿大;91外汇
	 * @param string $remark 用户备注字符串,最多只能传64字节,可用于标识订单唯一信息等,下单填上订单结构就会带上
	 * @param int $timeInForce 0当日有效1撤单前有效最多持续90自然日
	 * @param bool $fillOutsideRTH 是否允许盘前盘后成交仅适用于美股限价单
	 * @return orderIDEx 订单在富途服务器上的ID
	 */
	public function Trd_PlaceOrder($code, $trdSide, $qty, $price, $orderType = 1, $adjustPrice = false, $adjustSideAndLimit = 0, $remark = '', $timeInForce = 0, $fillOutsideRTH = false) {
		if (!$this->Trd_UnlockTrade(true)) {
			return 0;
		}
		if (!$accID = (string) $this->accID(0)) {
			return 0;
		}
		if (!$this->limit(__LINE__, 30, 15, 0.02)) {
			return 0;
		}
		if (!in_array((int) $trdSide, [1, 2, 3, 4], true)) {
			return 0;
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'packetID' => array(
				'connID' => (string) $this->connID,
				'serialNo' => (int) $this->serialNo
			),
			'code' => (string) $code,
			'trdSide' => in_array((int) $trdSide, [1, 4], true) ? 1 : 2,
			'orderType' => (int) $orderType,
			'qty' => (float) $qty,
			'price' => (float) $price,
			'secMarket' => (int) $this->secMarket,
			'remark' => (string) $remark
		);
		if ($adjustPrice) {
			$C2S['adjustPrice'] = (bool) $adjustPrice;
		}
		if ($adjustSideAndLimit) {
			$C2S['adjustSideAndLimit'] = (float) $adjustSideAndLimit;
		}
		if ($timeInForce) {
			$C2S['timeInForce'] = (int) $timeInForce;
		}
		if ($fillOutsideRTH) {
			$C2S['fillOutsideRTH'] = (bool) $fillOutsideRTH;
		}
		if (!$ret = $this->send('2202', $C2S)) {
			return 0;
		}
		return (string) $ret['orderIDEx'];
	}

	/**
	 * 修改订单(改价/改量/改状态等)(30秒20次)
	 * @param string $orderIDEx $forAll为true时传空(但用pb协议不支持传空)
	 * @param int $modifyOrderOp 0未知1改单(价格/数量)2撤单3失效4生效5删除
	 * @param float $qty
	 * @param float $price
	 * @param bool $forAll
	 * @param bool $adjustPrice
	 * @param float $adjustSideAndLimit
	 * @return string orderIDEx
	 */
	public function Trd_ModifyOrder($orderIDEx, $modifyOrderOp, $qty = 0, $price = 0, $forAll = false, $adjustPrice = false, $adjustSideAndLimit = 0) {
		if (!$this->Trd_UnlockTrade(true)) {
			return 0;
		}
		if (!$accID = (string) $this->accID(0)) {
			return 0;
		}
		if (!$this->limit(__LINE__, 30, 20, 0.04)) {
			return 0;
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'packetID' => array(
				'connID' => (string) $this->connID,
				'serialNo' => (int) $this->serialNo
			),
			'orderID' => 1, //兼容pb协议,实测会忽略此参数
			'orderIDEx' => (string) $orderIDEx,
			'modifyOrderOp' => (int) $modifyOrderOp,
			'forAll' => (bool) $forAll
		);
		if ($modifyOrderOp == 1) {
			$C2S['qty'] = (float) $qty;
			$C2S['price'] = (float) $price;
		}
		if ($adjustPrice) {
			$C2S['adjustPrice'] = (bool) $adjustPrice;
		}
		if ($adjustSideAndLimit) {
			$C2S['adjustSideAndLimit'] = (float) $adjustSideAndLimit;
		}
		if (!$ret = $this->send('2205', $C2S)) {
			return 0;
		}
		return (string) $ret['orderIDEx'];
	}

	/**
	 * 查询当日成交
	 * @param array $codeList
	 * @param array $idList
	 * @param bool $refreshCache
	 * @return array
	 */
	public function Trd_GetOrderFillList($codeList = [], $idList = [], $filterMarket = 0, $refreshCache = false) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if ($refreshCache) {
			if (!$this->limit(__LINE__, 30, 10, 0)) {
				return array();
			}
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'refreshCache' => (bool) $refreshCache
		);
		if ($codeList) {
			$C2S['filterConditions']['codeList'] = (array) $codeList;
		}
		if ($idList) {
			$C2S['filterConditions']['idList'] = (array) $idList;
		}
		if ($filterMarket) {
			$C2S['filterConditions']['filterMarket'] = (int) $filterMarket;
		}
		if (!$ret = $this->send('2211', $C2S)) {
			return array();
		}
		return (array) $ret['orderFillList'];
	}

	/**
	 * 查询历史订单(30秒10次)
	 * @param int $beginTime
	 * @param int $endTime
	 * @param array $filterStatusList 状态-1未知0未提交1等待提交2提交中5已提交等待成交10部分成交11全部成交14部分成交且剩余部分已撤单15全部已撤单且无成交21下单失败服务拒绝22已失效23已删除24成交被撤销
	 * @param array $codeList 股票代码过滤['00700','00388']
	 * @param array $idList 订单ID过滤
	 * @return array
	 */
	public function Trd_GetHistoryOrderList($beginTime, $endTime, $filterStatusList = [], $codeList = [], $idList = [], $filterMarket = 0) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'filterConditions' => array(
				'beginTime' => date('Y-m-d H:i:s', $beginTime),
				'endTime' => date('Y-m-d H:i:s', $endTime)
			)
		);
		if ($codeList) {
			$C2S['filterConditions']['codeList'] = (array) $codeList;
		}
		if ($idList) {
			$C2S['filterConditions']['idList'] = (array) $idList;
		}
		if ($filterMarket) {
			$C2S['filterConditions']['filterMarket'] = (int) $filterMarket;
		}
		if ($filterStatusList) {
			$C2S['filterStatusList'] = (array) $filterStatusList;
		}
		if (!$ret = $this->send('2221', $C2S)) {
			return array();
		}
		return (array) $ret['orderList'];
	}

	/**
	 * 查询历史成交(30秒10次)
	 * @param int $beginTime
	 * @param int $endTime
	 * @param array $codeList
	 * @param array $idList
	 * @param int $filterMarket
	 * @return array
	 */
	public function Trd_GetHistoryOrderFillList($beginTime, $endTime, $codeList = [], $idList = [], $filterMarket = 0) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'filterConditions' => array(
				'beginTime' => date('Y-m-d H:i:s', $beginTime),
				'endTime' => date('Y-m-d H:i:s', $endTime)
			)
		);
		if ($codeList) {
			$C2S['filterConditions']['codeList'] = (array) $codeList;
		}
		if ($idList) {
			$C2S['filterConditions']['idList'] = (array) $idList;
		}
		if ($filterMarket) {
			$C2S['filterConditions']['filterMarket'] = (int) $filterMarket;
		}
		if (!$ret = $this->send('2222', $C2S)) {
			return array();
		}

		return (array) $ret['orderFillList'];
	}

	/**
	 * 查询股票的融资融券数据
	 * @param array $codes 数量上限是100个
	 * @return array
	 */
	public function Trd_GetMarginRatio($codes) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		$securityList = array();
		foreach ((array) $codes as $code) {
			$securityList[] = array(
				'market' => $this->qotMarket($code),
				'code' => (string) $code
			);
		}
		if (!$securityList) {
			return array();
		}

		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'securityList' => $securityList
		);
		if (!$ret = $this->send('2223', $C2S)) {
			return array();
		}
		return (array) $ret['marginRatioInfoList'];
	}

	/**
	 * 
	 * @param type $orderIdExList
	 * @return array
	 */
	public function Trd_GetOrderFee($orderIdExList) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 10, 0)) {
			return array();
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'orderIdExList' => (array) $orderIdExList
		);
		if (!$ret = $this->send('2225', $C2S)) {
			return array();
		}
		return (array) $ret['orderFeeList'];
	}

	/**
	 * 查询交易业务账户在指定日期的现金流水数据
	 * @param int $clearingDate
	 * @param int $cashFlowDirection 1现金流入;2现金流出
	 * @return array
	 */
	public function Trd_FlowSummary($clearingDate, $cashFlowDirection = 0) {
		if (!$accID = (string) $this->accID(0)) {
			return array();
		}
		if (!$this->limit(__LINE__, 30, 20, 0)) {
			return array();
		}
		$C2S = array(
			'header' => array(
				'trdEnv' => (int) $this->trdEnv,
				'trdMarket' => (int) $this->trdMarket,
				'accID' => (string) $accID
			),
			'clearingDate' => date('Y-m-d', (int) $clearingDate),
			'cashFlowDirection' => (int) $cashFlowDirection
		);
		if (!$ret = $this->send('2226', $C2S)) {
			return array();
		}

		return (array) $ret['flowSummaryInfoList'];
	}

	/**
	 * 编码
	 * @param int $proto
	 * @param string $C2S
	 * @return boolean|string
	 */
	public function encode($proto, $C2S) {
		if (!$proto = (int) $proto) {
			return false;
		}

		$C2S = json_encode(array('c2s' => $C2S));
		if (json_last_error() !== JSON_ERROR_NONE) {
			return false;
		}
		if ($this->ProtoFmt === 0) {
			$class = "Futu\\" . $this->protos[$proto] . "\Request";
			$c2s = new $class();

			$c2s->mergeFromJsonString($C2S, true);
			$C2S = $c2s->serializeToString();
		}

		$body = $C2S; //默认不加密

		if (($this->PacketEncAlgo != -1) && ($proto == 1001)) {
			$private_pkey = openssl_pkey_get_private(file_get_contents($this->private_key));
			$details_pkey = openssl_pkey_get_details($private_pkey); //由私钥计算得到公钥
			$public_pkey = openssl_pkey_get_public($details_pkey['key']);

			$C2S_encrypted = '';
			for ($i = 0, $s = substr($C2S, 0, 100); $s; $i++, $s = substr($C2S, $i * 100, 100)) {
				$encrypted = '';
				openssl_public_encrypt($s, $encrypted, $public_pkey, OPENSSL_PKCS1_PADDING);

				$C2S_encrypted .= $encrypted;
			}
			$body = $C2S_encrypted;
		}
		if (($this->PacketEncAlgo == 0) && ($proto != 1001)) {

			$mod = strlen($C2S) % 16;

			$multiplier = $mod ? (16 - $mod) : 0;

			$body = openssl_encrypt($C2S . str_repeat("\0", $multiplier), 'AES-128-ECB', $this->connAESKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

			$body .= str_repeat("\0", 15);
			$body .= chr($mod);
		}
		if (($this->PacketEncAlgo == 1) && ($proto != 1001)) {
			$body = openssl_encrypt($C2S, "aes-128-ecb", $this->connAESKey, OPENSSL_RAW_DATA);
		}
		if (($this->PacketEncAlgo == 2) && ($proto != 1001)) {
			$body = openssl_encrypt($C2S, "aes-128-cbc", $this->connAESKey, OPENSSL_RAW_DATA, $this->aesCBCiv);
		}
		$ret = 'FT';
		$ret .= pack("V", (int) $proto); //协议ID
		$ret .= pack("C", (int) $this->ProtoFmt); //协议格式类型,0为Protobuf格式,1为Json格式
		$ret .= pack("C", 0); //协议版本,用于迭代兼容
		$ret .= pack("V", ++$this->serialNo); //包序列号,用于对应请求包和回包
		$ret .= pack("V", strlen($body)); //包体长度
		$ret .= sha1($C2S, true); //包体原始数据(解密后)的SHA1哈希值
		$ret .= pack("@8"); //保留8字节扩展
		$ret .= $body;

		return (string) $ret;
	}

	/**
	 * 解码回包
	 * @param string $recv
	 * @return array
	 */
	public function decode($recv) {
		if (empty($recv) === true) {
			return array();
		}

		$head = substr($recv, 0, 44);
		$body = substr($recv, 44);

		$pack = unpack("CF/CT/Vproto/CProtoFmt/CProtoVer/VSerialNo/VBodyLen", $head, 0);

		if (($this->PacketEncAlgo != -1) && ($pack['proto'] == 1001)) {
			$private_pkey = openssl_pkey_get_private(file_get_contents($this->private_key));

			$body_decrypted = '';
			for ($i = 0, $s = substr($body, 0, 128); $s; $i++, $s = substr($body, $i * 128, 128)) {
				$decrypted = '';

				openssl_private_decrypt($s, $decrypted, $private_pkey, OPENSSL_PKCS1_PADDING);

				$body_decrypted .= $decrypted;
			}
			$body = $body_decrypted;
		}
		if (($this->PacketEncAlgo == 0) && ($pack['proto'] != 1001)) {
			$mod = ord(substr($body, -1)); //补了多少个0

			$body = openssl_decrypt(substr($body, 0, -16), 'AES-128-ECB', $this->connAESKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

			$body = $mod ? substr($body, 0, $mod - 16) : $body;
		}
		if (($this->PacketEncAlgo == 1) && ($pack['proto'] != 1001)) {
			$body = openssl_decrypt($body, "aes-128-ecb", $this->connAESKey, OPENSSL_RAW_DATA);
		}
		if (($this->PacketEncAlgo == 2) && ($pack['proto'] != 1001)) {
			$body = openssl_decrypt($body, "aes-128-cbc", $this->connAESKey, OPENSSL_RAW_DATA, $this->aesCBCiv);
		}
		if ($this->ProtoFmt === 0) {
			$class = "Futu\\" . $this->protos[$pack['proto']] . "\Response";
			$s2c = new $class();
			$s2c->mergeFromString($body);
			$body = $s2c->serializeToJsonString();
		}
		$ret = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->errorlog("json Error:{$pack['proto']} - {$body}", 1);
			return array();
		}
		if (isset($ret['retType']) && ($ret['retType'] != 0)) {
			if (in_array($pack['proto'], array(3001, 3203)) && preg_match('/(\d{5,})/i', trim($ret['retMsg']), $m)) { //找不到标的
			}
			$this->errorlog("ret Error:{$pack['proto']} - {$ret['retType']}:{$ret['retMsg']}", 4);
			return array();
		}
		if (isset($ret['errCode']) && ($ret['errCode'] != 0)) {
			$this->errorlog("err Error:{$pack['proto']} - {$ret['errCode']}:{$ret['retMsg']}", 1);
			return array();
		}
		return array('proto' => $pack['proto'], 's2c' => isset($ret['s2c']) ? $ret['s2c'] : ['result' => true]);
	}

	/**
	 * 模拟富途回包
	 * @param int $proto
	 * @param array $S2C
	 * @return bool
	 */
	function serialize($proto, $S2C) {
		if (!$proto = (int) $proto) {
			return false;
		}

		$S2C = json_encode(array('s2c' => $S2C, 'retType' => 0, 'retMsg' => '', 'errCode' => 0));
		if (json_last_error() !== JSON_ERROR_NONE) {
			return false;
		}
		$class = "Futu\\" . $this->protos[$proto] . "\Response";
		$s2c = new $class();

		$s2c->mergeFromJsonString($S2C, true);
		$S2C = $s2c->serializeToString();

		$body = $S2C; //默认不加密

		$ret = 'FT';
		$ret .= pack("V", (int) $proto); //协议ID
		$ret .= pack("C", 0); //协议格式类型,0为Protobuf格式,1为Json格式
		$ret .= pack("C", 0); //协议版本,用于迭代兼容
		$ret .= pack("V", ++$this->serialNo); //包序列号,用于对应请求包和回包
		$ret .= pack("V", strlen($body)); //包体长度
		$ret .= sha1($S2C, true); //包体原始数据(解密后)的SHA1哈希值
		$ret .= pack("@8"); //保留8字节扩展
		$ret .= $body;

		return (string) $ret;
	}

	/**
	 * 私有限额方法
	 * @param int $proto 限额类型
	 * @param int $sec 多少秒
	 * @param int $cnt 多少次 比如订单为30秒20次
	 * @return boolean 是否在限额内
	 */
	private function limit($proto, $sec, $cnt, $delay) {
		if (!$proto = (int)$proto) {
			return false;
		}
		if ($this->push) {
			return true;
		}

		return true;

		$sec *= 1000000000;
		$delay *= 1000000000;

		$now = hrtime(true);

		$cacheKey = "AA-FUTU:{$this->host}:{$this->port}:{$this->trdEnv}:{$proto}";

		while ($ago = (int) $redis->rPop($cacheKey)) { //先排除过去
			if ($now - $ago <= $sec) {
				$redis->rPush($cacheKey, $ago);

				break;
			}
		}

		$length = $redis->lLen($cacheKey);
		if ($length >= $cnt) {
			return false;
		}

		$a = $redis->lrange($cacheKey, 0, 0);
		if ($length && $delay && ($now - $a[0] <= $delay)) {
			return false;
		}
		return $redis->lPush($cacheKey, $now) <= $cnt ? true : false;
	}

	/**
	 * 根据code获取行情市场:1香港市场11美国市场21沪股市场22深股市场31新加坡市场41日本市场
	 * @param string $code
	 */
	private function qotMarket($code) {
		$m = array(
			'YMmain' => 11,
			'NQmain' => 11,
			'MYMmain' => 11,
			'MNQmain' => 11,
			'NKmain' => 31,
			'NK225main' => 41
		);
		$qotMarket = null;
		$secMarket = null;
		if (preg_match('/\d{6}/', $code)) { //纯6位数字为沪深
			if (preg_match("/^((600)|(601)|(603))\d{3}$/", $code)) { //沪市主板
				$qotMarket = 21;
				$secMarket = 31;
			}
			if (preg_match("/^(688)\d{3}$/", $code)) { //沪市科创板
				$qotMarket = 21;
				$secMarket = 31;
			}
			if (preg_match("/^((000)|(002))\d{3}$/", $code)) { //深市主板
				$qotMarket = 22;
				$secMarket = 32;
			}
			if (preg_match("/^(300)\d{3}$/", $code)) { //深市‌创业板
				$qotMarket = 22;
				$secMarket = 32;
			}
		}
		return $m[$code] ? $m[$code] : ($qotMarket ?? $this->qotMarket);
	}

	/**
	 * @param int $proto
	 * @param array $C2S
	 * @return array
	 */
	private function send($proto, $C2S) {
		if (!$this->connect()) {
			return array();
		}
		if (!$data = $this->encode($proto, $C2S)) {
			return array();
		}

		if (!$length = @$this->cli->send("{$data}")) {
			$this->errorlog("Send Error:{$proto} - " . swoole_last_error() . " - " . swoole_strerror(swoole_last_error(), 9), 0);
			return array();
		}

		if ($this->push) { //推送模式不需要接收返回(此处必须返回空值)
			return array();
		}

		if (!$recv = @$this->cli->recv()) {
			$this->errorlog("Recv Error:{$proto} - " . swoole_last_error() . " - " . swoole_strerror(swoole_last_error(), 9), 0);
			return array();
		}

		if (!$ret = $this->decode($recv)) {
			return array();
		}
		if ($ret['proto'] && ($ret['proto'] != $proto) && !in_array($proto, [3001])) {
			$this->errorlog("proto Error:{$proto}", 1);
			return array();
		}

		return (array) $ret['s2c'];
	}

	/**
	 * 记录错误,加上断线自动重连
	 * @param string $msg
	 * @param number $level 0退出+日志1断线+日志2退出3断线4日志
	 * @return boolean
	 */
	private function errorlog($msg, $level = 0) {

		//in_array($level, [0, 1, 4]) && file_put_contents(); //错误日志

		print_r(array($this->host . ":" . $this->port, $msg));

		in_array($level, [1, 3]) && $this->close();

		in_array($level, [0, 2]) && die();

		$this->die && die("<center><h1>\n" . date('Y') . " Bad Gateway @Futu.\n</h1></center>");

		return false;
	}

	public function __destruct() {
		$this->close();
	}
}
