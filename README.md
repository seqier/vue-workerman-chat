## 安装说明 

* chat_server 是使用了workerman实现的服务端，直接点击start_for_win.bat 启动即可，没有在linux下面测
* chat_client 是前端，基于vue实现
* 我使用的是win10的环境，本地要安装mysql和redis，数据库配置都在Event.php那个文件里面
* chat_client缺库的话就使用npm安装
* chat_server缺库的话就使用composer安装 
* 就这些了，很简单


## 功能说明

* 群聊
* 登陆、退出登陆
* 掉线重连
* 登陆和掉线重连后获取离线消息
* 超过设定时间自动退出登陆
* 消息持久化

## 效果图

### 登陆/注册界面：

<img src="https://github.com/seqier/vue-workerman-chat/blob/master/screen/1.png">
<img src="https://github.com/seqier/vue-workerman-chat/blob/master/screen/4.png">

### 聊天界面：

<img src="https://github.com/seqier/vue-workerman-chat/blob/master/screen/3.png">

### 群信息界面：

<img src="https://github.com/seqier/vue-workerman-chat/blob/master/screen/2.png">