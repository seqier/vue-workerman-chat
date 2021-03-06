<?php

/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use Workerman\Worker;
use \GatewayWorker\Lib\Gateway;
use Workerman\Lib\Timer;
use Clue\React\Redis\Factory;
use Clue\React\Redis\Client;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events {

    /**
     * 新建一个类的静态成员，用来保存数据库实例
     */
    private static $db = null;
    private static $redis = null;
    private static $redis_react = null;
    private static $expire = 600;

    /**
     * 进程启动后初始化数据库和redis连接
     */
    public static function onWorkerStart($worker) {





        if (empty(self::$db)) {
            self::$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '123456', 'chat');
        }



        if (empty(self::$redis)) {


            self::$redis = new Predis\Client([
                'scheme' => 'tcp',
                'host' => 'localhost',
                'port' => '6379'
            ]);
            echo self::$redis->select(1);


            self::$redis_react = (new Factory(Worker::getEventLoop()))->createClient('localhost:6379');
        }

        // 设置定时器，异步消息落地
        Timer::add(1, function() {
            self::$redis_react->then(function (Client $client) {
                $client->rpop('message')->then(function ($message) {
                    if ($message) {
                        // echo $message . PHP_EOL;
                        $message_data = unserialize($message);
                        $image = parse_url($message_data['image'])['path'];
                        self::$db->insert('chat_message')
                                ->cols([
                                    'room_id' => $message_data['room_id'],
                                    'time' => $message_data['time'],
                                    'to_user_id' => $message_data['to_client_id'],
                                    'from_user_id' => $message_data['user_id'],
                                    'from_user_name' => $message_data['nickname'],
                                    'image' => $image,
                                    'content' => $message_data['content']
                                ])
                                ->query();
                    }
                });
            });
        });
    }

    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * 
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id) {

        echo '有新的连接：', $client_id, PHP_EOL;

        // 向当前client_id发送数据 
        //Gateway::sendToClient($client_id, "Hello $client_id\r\n");
        // 向所有人发送
        //Gateway::sendToAll("$client_id login\r\n");
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message) {



        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:", json_encode($_SESSION), " onMessage:", $message, PHP_EOL;



        // 客户端传递的是json数据
        $message_data = json_decode($message, true);


        if (!$message_data)
            return;



        // 非法请求
        if (!isset($message_data['room_id'])) {
            throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
            return;
        }

        if (isset($message_data['access_token']) && $message_data['access_token'] != 'null') {
            $access_token = self::$redis->get($message_data['access_token']);
        } else {
            // 没有access_token或者access_token过期
            $access_token = false;
        }
        // var_dump($access_token);
        if (!in_array($message_data['type'], ['login', 'check_user', 'register']) && !$access_token) {
            self::logout($client_id, $message_data);
            return;
        }


        switch ($message_data['type']) {


            // 服务端回应客户端的心跳
            case 'ping':
                // var_dump($message_data['access_token']);
                self::$redis->expire($message_data['access_token'], self::$expire);
                $return = self::success(['type' => 'pong']);
                Gateway::sendToCurrentClient($return);
                return;


            // 客户端登录 message格式: {type:login, client_name:xx,password:yy, room_id:1}，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
                $_SESSION['room_id'] = $message_data['room_id'];
                $_SESSION['client_id'] = $client_id;
                self::login($client_id, $message_data);
                return;


            // 注册账户
            case 'register':
                // 获取redis的数据，检查重连
                $_SESSION['room_id'] = $message_data['room_id'];
                $_SESSION['client_id'] = $client_id;
                self::register($client_id, $message_data);
                return;



            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'say':
                self::say($client_id, $message_data);
                return;



            // 客户端退出登录 message: {type:say, to_client_id:xx, content:xx}
            case 'logout':
                self::logout($client_id, $message_data);
                return;




            // 客户端重连
            case 'relogin':
                // 获取redis的数据，检查重连
                $_SESSION['room_id'] = $message_data['room_id'];
                $_SESSION['client_id'] = $client_id;
                self::relogin($client_id, $message_data);
                return;





            // 检查注册账户
            case 'check_user':
                // 获取redis的数据，检查重连
                $_SESSION['room_id'] = $message_data['room_id'];
                $_SESSION['client_id'] = $client_id;
                self::checkUser($client_id, $message_data);
                return;
        }





        // 向所有人发送 
        // Gateway::sendToAll("$client_id said $message\r\n");
    }

    /**
     * 检查用户账户
     */
    public static function checkUser($client_id, $message_data) {


        // var_dump($message_data);
        $username = $message_data['username'];

        // 检查用户信息
        $data = self::$db->select('*')
                ->from('chat_user')
                ->where('username = :username')
                ->bindValues(['username' => base64_decode($username)])
                ->single();

        if (!empty($data)) {
            $message = self::success([
                        'msg_type' => 'check_user',
                        'user_exist' => 1,
                        'time' => date('Y-m-d H:i:s')
            ]);
        } else {
            $message = self::success([
                        'msg_type' => 'check_user',
                        'user_exist' => 0,
                        'time' => date('Y-m-d H:i:s')
            ]);
        }
        Gateway::sendToCurrentClient($message);
        // var_dump($message);
    }

    /**
     * 当客户端重连时
     * @param integer $client_id 客户端id
     * @param array $message_data 消息体
     */
    public static function relogin($client_id, $message_data) {


        // 更改用户信息
        $user_data = unserialize(self::$redis->get($message_data['access_token']));
        // var_dump($user_data);
        $user_data['client_id'] = $client_id;

        // 在未登陆状态下重连
        if ($message_data['access_token'] == 'null') {
            return;
        }
        self::$redis->setex($message_data['access_token'], self::$expire, serialize($user_data));

        $list = self::getAllUserInfo();
        // var_dump($list);
        $user_num = count($list);
        // 给当前用户发送用户列表和aceess_token
        $new_message = self::success([
                    'msg_type' => 'relogin_msg',
                    'from_user_id' => $user_data['user_id'],
                    'access_token' => $message_data['access_token'],
                    'user_list' => $list,
                    'user_num' => $user_num,
                    'user_data' => $user_data,
                    'image' => $user_data['image'], // 方便拼接host，增加一个image字段
        ]);

        // 要加入群才能接收消息
        Gateway::joinGroup($client_id, $message_data['room_id']);
        Gateway::sendToCurrentClient($new_message);

        // 发送聊天记录
        self::sendMsgRecord();
        return;
    }

    /**
     * 当客户退出登录时
     * @param integer $client_id 客户端id
     * @param array $message_data 消息体
     */
    public static function logout($client_id, $message_data) {



        echo 'method:logout:', PHP_EOL;


        //print_r($message_data);
        //print_r(self::$redis->exists($message_data['access_token']));
        // 清除access_token

        $access_exists = self::$redis->get($message_data['access_token']);

        if (!empty($access_exists)) {
            self::$redis->del($message_data['access_token']);
        }

        Gateway::leaveGroup($client_id, $message_data['room_id']);

        $list = self::getAllUserInfo();
        $user_num = count($list);

        $message = self::success(['msg_type' => 'logout']);
        Gateway::sendToCurrentClient($message);

        // 如果没有access_token，则不广播消息
        if ($message_data['access_token'] == 'null')
            return;

        $message_to_all = self::success([
                    'msg_type' => 'logout_msg',
                    'to_user_id' => 0,
                    'nickname' => $message_data['nickname'],
                    'user_list' => $list,
                    'user_num' => $user_num,
                    'time' => date('Y-m-d H:i:s')
        ]);
        Gateway::sendToGroup($message_data['room_id'], $message_to_all);
        return;
    }

    /**
     * 当客户发消息
     * @param integer $client_id 客户端id
     * @param array $message_data 消息体
     */
    public static function say($client_id, $message_data) {




        $user_data = unserialize(self::$redis->get($message_data['access_token']));




        // 异步缓存消息到redis
        //   self::$redis->set('message', serialize($message_data));
        self::$redis_react->then(function (Client $client) use ($message_data) {
            // var_dump($message_data);
            $client->lpush('message', serialize($message_data));
        });
        // 私聊TODO
        if ($message_data['to_client_id'] != 0) {
            $new_message = array(
                'msg_type' => 'say',
                'from_client_id' => $client_id,
                'from_client_name' => $_SESSION['nickname'],
                'to_client_id' => $message_data['to_client_id'],
                'content' => "<b>对你说: </b>" . nl2br(htmlspecialchars($message_data['content'])),
                'time' => date('Y-m-d H:i:s'),
            );
            Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));
            $new_message['content'] = "<b>你对" . htmlspecialchars($message_data['to_client_name']) . "说: </b>" . nl2br(htmlspecialchars($message_data['content']));
            return Gateway::sendToCurrentClient(json_encode($new_message));
        }

        // 群聊
        $new_message = self::success([
                    'msg_type' => 'say',
                    'from_user_id' => $user_data['user_id'],
                    'from_client_name' => $user_data['nickname'],
                    'to_client_id' => 0,
                    'content' => nl2br(htmlspecialchars($message_data['content'])),
                    'image' => $user_data['image'],
                    'time' => date('Y-m-d H:i:s'),
        ]);

        // var_dump($new_message);

        echo('-------------------------------------------');
        print_r($message_data['room_id']);
        echo('-------------------------------------------');

        Gateway::sendToGroup($message_data['room_id'], $new_message);
        return;
    }

    /**
     * 注册用户
     */
    public static function register($client_id, $message_data) {
        echo 'method:register', PHP_EOL;
        $username = base64_decode($message_data['username']);
        $password = $message_data['password'];
        // 插入数据库

        $insert_id = self::$db->insert('chat_user')->cols([
                    'username' => $username,
                    'password' => $password,
                    'nickname' => $username,
                    'image' => '/static/no_pic.png',
                    'sign' => '这家伙很懒，什么都没有留下',
                    'update_time' => date('Y-m-d H:i:s')
                ])->query();

        if (!empty($insert_id)) {
            $message = self::success([
                        'msg_type' => 'register',
                        'time' => date('Y-m-d H:i:s')
            ]);
        } else {
            $message = self::error(10087, [
                        'msg_type' => 'register',
                        'time' => date('Y-m-d H:i:s')
            ]);
        }

        Gateway::sendToCurrentClient($message);
        // var_dump($message);
    }

    /**
     * 当客户端登陆时
     * @param integer $client_id 客户端id
     * @param array $message_data 消息体
     */
    public static function login($client_id, $message_data) {


        echo 'method:login', PHP_EOL;
        // 验证账号密码
        $user_data = self::$db->select('username, nickname, user_id, image, sign, update_time')
                ->from('chat_user')
                ->where('username=' . "'{$message_data['client_name']}'")
                ->where('password=' . "'{$message_data['password']}'")
                ->row();
        if (!$user_data) {
            $return = self::error(10086, '用户名或密码错误！');
            Gateway::sendToCurrentClient($return);
            return;
        }



        // 存储用户信息
        $access_token = sha1($client_id);
        $cache = [
            'client_id' => $client_id,
            'room_id' => $message_data['room_id'],
            'user_id' => $user_data['user_id'],
            'username' => $user_data['username'],
            'nickname' => $user_data['nickname'],
            'sign' => $user_data['sign'],
            'image' => $user_data['image']
        ];
        self::$redis->setex($access_token, self::$expire, serialize($cache));



        $list = self::getAllUserInfo();
        $user_num = count($list);


        // 给当前用户发送用户列表和aceess_token
        $new_message = self::success([
                    'msg_type' => 'login_msg',
                    'from_user_id' => $user_data['user_id'],
                    'access_token' => $access_token,
                    'user_list' => $list,
                    'user_data' => $user_data,
                    'image' => $cache['image'], // 方便拼接host，增加一个image字段
        ]);
        Gateway::sendToCurrentClient($new_message);



        self::sendMsgRecord();

        // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
        $message_to_all = self::success([
                    'msg_type' => 'sys_msg',
                    'to_user_id' => 0,
                    'nickname' => $user_data['nickname'],
                    'user_list' => $list,
                    'user_num' => $user_num,
                    'time' => date('Y-m-d H:i:s')
        ]);
        Gateway::joinGroup($client_id, $message_data['room_id']);
        Gateway::sendToGroup($message_data['room_id'], $message_to_all);
        return;
    }

    /**
     * 给当前用户发送聊天记录
     */
    public static function sendMsgRecord() {
        // 给当前用户发送聊天记录
        $data = self::$db->select('*')
                ->from('chat_message')
                ->where('to_user_id = :to_user_id AND room_id = :room_id')
                ->orderByDESC(['time'])
                ->limit(10)->offset(0)
                ->bindValues(['to_user_id' => 0, 'room_id' => 1])
                ->query();

        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $mysql_message[] = [
                    'msg_type' => 'say',
                    'from_user_id' => $value['from_user_id'],
                    'from_client_name' => $value['from_user_name'],
                    'to_client_id' => 0,
                    'content' => nl2br(htmlspecialchars($value['content'])),
                    'image' => $value['image'],
                    'time' => $value['time']
                ];
            }

            $mysql_message = [
                'status' => 1,
                'code' => 200,
                'msg' => 'success',
                'msg_type' => 'my_msg',
                'data' => $mysql_message
            ];

            $message = json_encode($mysql_message, JSON_UNESCAPED_UNICODE);
            Gateway::sendToCurrentClient($message);
            // var_dump($message);
        }
    }

    /**
     * 以用户ID为键，获取房间所有用户的信息
     */
    public static function getAllUserInfo($key = 'user_id') {


        // 获取房间内所有用户列表 
        $cache_arr = self::$redis->keys('*');
        if (empty($cache_arr)) {
            return null;
        }


        print_r($cache_arr);

        foreach ($cache_arr as &$value) {
            // print_r(self::$redis->get($value));
            $old_list[] = unserialize(self::$redis->get($value));
        }

        $list = array_combine(array_column($old_list, 'user_id'), $old_list);
        return $list;
    }

    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     */
    public static function onClose($client_id) {
        
        
         // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";

        // 从房间的客户端列表中删除
        return;
        // 向所有人发送 
        // GateWay::sendToAll("$client_id logout\r\n");
    }

    /**
     * 封装错误信息
     *
     * @param  int    $code
     * @param  string $msg
     * @return json
     */
    public static function error($code, $msg) {
        $data = [
            'status' => 0,
            'code' => $code,
            'msg' => $msg,
        ];
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 封装成功返回信息
     *
     * @param  mixed $data
     * @return json
     */
    public static function success($data) {
        $data = [
            'status' => 1,
            'code' => 200,
            'msg' => 'success',
            'data' => $data
        ];
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

}
