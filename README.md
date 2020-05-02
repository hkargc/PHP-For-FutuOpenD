futu-api 3.20 (富途交易开放平台) PHP 接口<br />

已测试环境: CentOS 7 + PHP 7 + Swoole 4.5.0 + FutuOpenD 2.12<br />

http://www.php.net/<br />
https://www.centos.org/<br />
http://pecl.php.net/package/swoole<br />

富途网关启动:<br />
https://futunnopen.github.io/futu-api-doc/intro/intro.html<br />

sh# /path/to/FutuOpenD -cfg_file=/path/to/FutuOpenD.xml -console=0 -lang=en<br />

同步模式: sh# /path/to/php demo_get.php<br />

异步模式: sh# /path/to/php demo_push.php<br />