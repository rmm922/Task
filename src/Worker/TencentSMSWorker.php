<?php
/**
 * Created by PhpStorm.
 * User: chenbo
 * Date: 18-6-4
 * Time: 下午2:19
 */

namespace SWBT\Worker;



use Pheanstalk\Job;
use Pimple\Container;
use SWBT\Code;

define('SMTP_STATUS_NOT_CONNECTED', 1);
define('SMTP_STATUS_CONNECTED',     2);
/**
 * 邮件发送基类
 */
class smtp
{
    var $connection;
    var $recipients;
    var $headers;
    var $timeout;
    var $errors;
    var $status;
    var $body;
    var $from;
    var $host;
    var $port;
    var $helo;
    var $auth;
    var $user;
    var $pass;

    /**
     *  参数为一个数组
     *  host        SMTP 服务器的主机       默认：localhost
     *  port        SMTP 服务器的端口       默认：25
     *  helo        发送HELO命令的名称      默认：localhost
     *  user        SMTP 服务器的用户名     默认：空值
     *  pass        SMTP 服务器的登陆密码   默认：空值
     *  timeout     连接超时的时间          默认：5
     *  @return  bool
     */
    function __construct($params = array())
    {
        if (!defined('CRLF'))
        {
            define('CRLF', "\r\n");
        }

        $this->timeout  = 10;
        $this->status   = SMTP_STATUS_NOT_CONNECTED;
        $this->host     = 'localhost';
        $this->port     = 25;
        $this->auth     = false;
        $this->user     = '';
        $this->pass     = '';
        $this->errors   = array();

        foreach ($params AS $key => $value)
        {
            $this->$key = $value;
        }

        $this->helo     = $this->host;

        //  如果没有设置用户名则不验证
        $this->auth = ('' == $this->user) ? false : true;
    }

    function connect($params = array())
    {
        if (!isset($this->status))
        {
            $obj = new smtp($params);

            if ($obj->connect())
            {
                $obj->status = SMTP_STATUS_CONNECTED;
            }

            return $obj;
        }
        else
        {
            $this->host = "ssl://" . $this->host;
            
            $this->connection = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

            if ($this->connection === false)
            {
                $this->errors[] = 'Access is denied.';

                return false;
            }

            @socket_set_timeout($this->connection, 0, 250000);

            $greeting = $this->get_data();

            if (is_resource($this->connection))
            {
                $this->status = 2;

                return $this->auth ? $this->ehlo() : $this->helo();
            }
            else
            {
                // log_write($errstr, __FILE__, __LINE__);
                $this->errors[] = 'Failed to connect to server: ' . $errstr;

                return false;
            }
        }
    }

    /**
     * 参数为数组
     * recipients      接收人的数组
     * from            发件人的地址，也将作为回复地址
     * headers         头部信息的数组
     * body            邮件的主体
     */

    function send($params = array())
    {
        foreach ($params AS $key => $value)
        {
            $this->$key = $value;
        }

        if ($this->is_connected())
        {
            //  服务器是否需要验证
            if ($this->auth)
            {
                if (!$this->auth())
                {
                    return false;
                }
            }

            $this->mail($this->from);

            if (is_array($this->recipients))
            {
                foreach ($this->recipients AS $value)
                {
                    $this->rcpt($value);
                }
            }
            else
            {
                $this->rcpt($this->recipients);
            }

            if (!$this->data())
            {
                return false;
            }

            $headers = str_replace(CRLF . '.', CRLF . '..', trim(implode(CRLF, $this->headers)));
            $body    = str_replace(CRLF . '.', CRLF . '..', $this->body);
            $body    = substr($body, 0, 1) == '.' ? '.' . $body : $body;

            $this->send_data($headers);
            $this->send_data('');
            $this->send_data($body);
            $this->send_data('.');

            return (substr($this->get_data(), 0, 3) === '250');
        }
        else
        {
            $this->errors[] = 'Not connected!';

            return false;
        }
    }

    function helo()
    {
        if (is_resource($this->connection)
                AND $this->send_data('HELO ' . $this->helo)
                AND substr($error = $this->get_data(), 0, 3) === '250' )
        {
            return true;
        }
        else
        {
            $this->errors[] = 'HELO command failed, output: ' . trim(substr($error, 3));

            return false;
        }
    }

    function ehlo()
    {
        if (is_resource($this->connection)
                AND $this->send_data('EHLO ' . $this->helo)
                AND substr($error = $this->get_data(), 0, 3) === '250' )
        {
            return true;
        }
        else
        {
            $this->errors[] = 'EHLO command failed, output: ' . trim(substr($error, 3));

            return false;
        }
    }

    function auth()
    {
        if (is_resource($this->connection)
                AND $this->send_data('AUTH LOGIN')
                AND substr($error = $this->get_data(), 0, 3) === '334'
                AND $this->send_data(base64_encode($this->user))            // Send username
                AND substr($error = $this->get_data(),0,3) === '334'
                AND $this->send_data(base64_encode($this->pass))            // Send password
                AND substr($error = $this->get_data(),0,3) === '235' )
        {
            return true;
        }
        else
        {
            $this->errors[] = 'AUTH command failed: ' . trim(substr($error, 3));

            return false;
        }
    }

    function mail($from)
    {
        if ($this->is_connected()
            AND $this->send_data('MAIL FROM:<' . $from . '>')
            AND substr($this->get_data(), 0, 2) === '250' )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    function rcpt($to)
    {
        if ($this->is_connected()
            AND $this->send_data('RCPT TO:<' . $to . '>')
            AND substr($error = $this->get_data(), 0, 2) === '25')
        {
            return true;
        }
        else
        {
            $this->errors[] = trim(substr($error, 3));

            return false;
        }
    }

    function data()
    {
        if ($this->is_connected()
            AND $this->send_data('DATA')
            AND substr($error = $this->get_data(), 0, 3) === '354' )
        {
            return true;
        }
        else
        {
            $this->errors[] = trim(substr($error, 3));

            return false;
        }
    }

    function is_connected()
    {
        return (is_resource($this->connection) AND ($this->status === SMTP_STATUS_CONNECTED));
    }

    function send_data($data)
    {
        if (is_resource($this->connection))
        {
            return fwrite($this->connection, $data . CRLF, strlen($data) + 2);
        }
        else
        {
            return false;
        }
    }

    function get_data()
    {
        $return = '';
        $line   = '';

        if (is_resource($this->connection))
        {
            while (strpos($return, CRLF) === false OR $line{3} !== ' ')
            {
                $line    = fgets($this->connection, 512);
                $return .= $line;
            }

            return trim($return);
        }
        else
        {
            return '';
        }
    }

    /**
     * 获得最后一个错误信息
     *
     * @access  public
     * @return  string
     */
    function error_msg()
    {
        if (!empty($this->errors))
        {
            $len = count($this->errors) - 1;
            return $this->errors[$len];
        }
        else
        {
            return '';
        }
    }
}

class TencentSMSWorker extends BaseWorker implements Worker
{
    private $config;
    public  $pdo;
    public  $redis;
    private $params;

	public function __construct(Container $container, Job $job)
    {
        parent::__construct($container, $job);

        //加载配置文件
        $params            = require('params.php');

        $this->config      = $params['connection'];
        //时间
        date_default_timezone_set('PRC');
        //mysql配置
        $this->pdo   = new \PDO($this->config['mysql_host'], $this->config['mysql_user'], $this->config['mysql_password']);
        //redis配置
        $this->redis = new \Redis();
        $this->redis->connect($this->config['redis_host'], $this->config['redis_port']);
        $this->redis->auth($this->config['redis_auth']);

        //缓存名称
        $this->params      = $params['redis'];
    }

     /**
     * 处理job
     */
    public function handleJob():array
    {   
        try {
            //反序列化对象
            $data_info = json_decode($this->job->getData(),true);
            
            if ($data_info['type'] == 'taskDataSMS') {
                //初始发送短信接口
                $res = $this->actionTaskDataSMS($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMS--短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMS--短信发送-失败' . $res;
                }
            } else if ($data_info['type'] == 'taskDataSMSAbout') {
                //初始发送短信接口
                $res = $this->actionTaskDataSMSAbout($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSAbout--短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSAbout--短信发送-失败' . $res;
                }
            } else if ($data_info['type'] == 'taskDataSMSSendMeet') {//创建会议发起预约
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetSend($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskDataSMSSendMeetBegin' ) {//创建会议发起 配对成功 即将开始    
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetBegin($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--短信发送-失败' . $res;
                }
            } else {
                $msg  = '参数错误';
            }
            $this->logger->info('job处理成功日志信息输出', [
                'id' => $this->job->getId(),
                'data' => $msg
            ]);
            return [
                'code' => Code::$success
            ];

        } catch (\Exception $e) {
            $this->logger->info('运行错误捕捉', ['id' => $this->job->getId(), 'data' => $e->getMessage()]);
            return ['code' => Code::$success];
        }
    }

    /**
     * 创建会议 发起预约短信内容
     */
    public function actionTaskDataSMSMeetSend($data_info = '') {
        $data         = $data_info['data'];
        $meetId       = $data['meetId'];//会议ID
        $userID       = ! empty( $data['userId'] ) ? $data['userId'] : '';//用户ID 
        $describe     = ! empty( $data['describe'] ) ? $data['describe'] : ''; //预约内容

        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数
        if($userInfo) {
            $value = $userInfo[0];
            $activity = self::shortConnection($meetId, 3);//短连链接
            $mobile_phone = ! empty( $value['mobile_phone'] ) ? $value['mobile_phone'] : ''; 
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            $ccode        = ! empty( $value['ccode'] )        ? $value['ccode'] : ''; 
            $curl_data = [
                'mobile_phone' => $mobile_phone,
                'token'        => md5(md5($mobile_phone . $this->config['token'])),
                'validity'     => time() + 300,
                'activity'     => $activity,
                'template'     => 35, //35模板ID
                'ccode'        => $ccode, //手机号国家编码
            ];
            // 发送验证码接口
            $curlInfo = self::curls($curl_data,'phone_curl');
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = $this->config['subject'];
                $content = '尊敬的用户，您收到一份会议预约邀请函！预约内容:  '.$describe.'  请请点击:' . $activity;
                $send_mail =  self::send_mail($name, $email, $subject, $content);
            }
        }
        return true;
    }
    /**
     * 预约直播 开始直播 发送短信 邮件
     */
    public function actionTaskDataSMSAbout($data_info = '') {
        //数据库数据ID  直播间的ID
        $ID        = $data_info['rid'];
        if(!$ID) { return false;}
        $dataInfo  = self::selectAppointmentInfo($ID, 1);//查出直播间所对应的user_id  条件是 p46_exhibition_room 的 rid
        $userID    = ! empty ( $dataInfo['user_id'] ) ? $dataInfo['user_id'] : ''; //217 房间  user_id 3453
        if($userID) {
            $dataInfo1 =  self::selectAppointmentInfo($userID, 2); //关联查出我的收到预约列表人数
            if($dataInfo1) {
                $activity = self::shortConnection($ID);
                foreach ($dataInfo1 as $key => $value) { //循环处理数据
                    $mobile_phone = ! empty( $value['mobile_phone'] ) ? $value['mobile_phone'] : ''; 
                    $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
                    $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
                    $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
                    $ccode        = ! empty( $value['ccode'] )        ? $value['ccode'] : ''; 
                    /*$mobile_phone = '18201058764'; 
                    $email        = '2547977230@qq.com'; 
                    $first_name   = '任明明'; 
                    $user_name    = 'renmingming'; 
                    $ccode        = 86; */
                    $curl_data = [
                        'mobile_phone' => $mobile_phone,
                        'token'        => md5(md5($mobile_phone . $this->config['token'])),
                        'validity'     => time() + 300,
                        'activity'     => $activity,
                        'template'     => 34, //34模板ID
                        'ccode'        => $ccode, //手机号国家编码
                    ];
                    // 发送验证码接口
                    $curlInfo = self::curls($curl_data,'phone_curl');
                    //邮件发送
                    $name = $first_name ? $first_name : $user_name;
                    if($email && $name ) {
                        $subject = $this->config['subject'];
                        $content = '尊敬的用户，您预约的直播间即将开始！请点击:' . $activity;
                        $send_mail =  self::send_mail($name, $email, $subject, $content);
                    }
                }
            }
        }
        return true;
    }
    /**
     * 生成短连接
     * @param $ID 直播间ID
     */
    public function shortConnection($ID = '', $roomUrl = 1 ) {
        if(!$ID) { return false;}
        if($roomUrl == 1) {
            $room_url  = 'https://etest.eovobochina.com/index.php?app=exhibition/info&id=' . $ID . '&status=0';
        } else if($roomUrl == 2) {
            $room_url  = 'https://testn.eovobochina.com/myMeetingDetails?meetId='. $ID;
        } else if($roomUrl == 3) {
            $room_url  = 'https://testn.eovobochina.com/message';
        } 
        $token =  self::token();
        if(!$token) { return false;}
        //开始请求
        $curl = curl_init();
        $urlData = 'https://api.weixin.qq.com/cgi-bin/shorturl?access_token=' . $token ;
        $urlData1 = [
            'action'   => 'long2short',
            'long_url' => $room_url,
        ];
        curl_setopt_array($curl, array(
        CURLOPT_URL => $urlData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($urlData1),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $response = $response ? json_decode($response , true) : [];
        if($response['errcode'] == 0) {
            return $response['short_url'];
        }else {
            return '';
        }
    }

    //生成token
    public function token($cache = true) {
        $info = '';
        $redis_name  = $this->params['ver_get_weixin_token'];//redis缓存key
        $info = $this->redis->get($redis_name);
        if(!$info || $cache == false) {
            //删除
            $this->redis->del($redis_name);
            $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$this->config['appID'].'&secret='. $this->config['appsecret'] .'';
            $info =  file_get_contents($url);
            if($info) {
                $info = json_decode( $info , true );
                $access_token = ! empty ( $info['access_token'] ) ? $info['access_token'] : '';
                $expires_in   = ! empty ( $info['expires_in'] ) ? $info['expires_in'] : '';
                if($access_token && $expires_in) {
                    $this->redis->set($redis_name, $access_token);
                    $this->redis->expire($redis_name, $expires_in);
                    $info = $access_token;
                }
            }
        }
        return $info;
    }
    /**
     * 查询表数据 有关开直播预约的信息处理
     */
    public function selectAppointmentInfo($ID = '', $type = '') {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        if($type == 1) {
            $sql  = 'SELECT peu.user_id as user_id  FROM p46_exhibition_room AS per JOIN p46_exhibition_user AS  peu ON per.uid = peu.uid   WHERE  per.rid = ' . $ID . ' ';
        } else if($type == 2) {
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode  FROM  p46_appointment AS pa  JOIN p46_users  AS pu ON pa.from_id = pu.user_id WHERE pa.statu = 1 AND  pa.to_id = '$ID'";
        } else if($type == 3) {
            //用户信息
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode  FROM  p46_users  WHERE user_id = '$ID'";
        }
        $rs = $this->pdo->query($sql);
        $rs->setFetchMode(\PDO::FETCH_ASSOC);
        $dbData = $rs->fetchAll();
        $return_data = [];
        if($dbData) {
            $return_data =  $type == 1 ? $dbData[0] : $dbData;
        }
        return $return_data;
    }
    /**
     * 发送短信服务执行
     */
    public function actionTaskDataSMS($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $ID           = $data['id'];
        $activity     = ! empty( $data['t_name'] ) ? $data['t_name'] : ''; 
        $mobile_phone = ! empty( $data['mobile_phone'] ) ? $data['mobile_phone'] : ''; 
        $email        = ! empty( $data['email'] ) ? $data['email'] : ''; 
        $first_name   = ! empty( $data['first_name'] ) ? $data['first_name'] : ''; 
        $user_name    = ! empty( $data['user_name'] ) ? $data['user_name'] : ''; 
        $ccode        = ! empty( $data['ccode'] ) ? $data['ccode'] : ''; 
        
        $activity_info = [
            'exhibiton_preview' => '论坛',
            'exhibiton_room'    => '直播',
        ];
        $activity = $activity_info[$activity];
        $curl_data = [
            'mobile_phone' => $mobile_phone,
            'token'        => md5(md5($mobile_phone . $this->config['token'])),
            'validity'     => time() + 300,
            'activity'     => $activity,
            'template'     => $this->config['template'], //33模板ID
            'ccode'        => $ccode, //手机号国家编码
        ];
        // 发送验证码接口
        $curlInfo = self::curls($curl_data,'phone_curl');
        //邮件发送
        $name = $first_name ? $first_name : $user_name;
        if($email && $name ) {
            $subject = $this->config['subject'];
            $content = '尊敬的用户，您预约的'.$activity.'还有2分钟开始！';
            $send_mail =  self::send_mail($name, $email, $subject, $content);
            // if($send_mail != 'ok') {
            //     file_put_contents('send_mail_fail.txt', $send_mail);
            // } 
        }
        if( $curlInfo['code'] == 200 ) {
            self::updateAudioTranslation($ID);
        }

        return true;
    }

    /**
     * 更新表数据 更新翻译文字
     */
    public function updateAudioTranslation( $Id = '' , $TaskId = 1) {
        if(!$Id) { return false;}
        $where = '';
        $TaskId = intval($TaskId);
        if($TaskId) {
            $where .= 'is_success = '.  $TaskId ;
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_notice` SET $where WHERE `id`=:id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':id' => $Id));
       
    }
    

    /**
     * 发送短信服务执行 配对会议即将开始5分钟
     */
    public function actionTaskDataSMSMeetBegin($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $ID           = $data['id'];
        $mobile_phone = ! empty( $data['mobile_phone'] ) ? $data['mobile_phone'] : ''; 
        $email        = ! empty( $data['email'] ) ? $data['email'] : ''; 
        $first_name   = ! empty( $data['first_name'] ) ? $data['first_name'] : ''; 
        $user_name    = ! empty( $data['user_name'] ) ? $data['user_name'] : ''; 
        $ccode        = ! empty( $data['ccode'] ) ? $data['ccode'] : ''; 
        $meetId       = ! empty( $data['meet_id'] ) ? $data['meet_id'] : ''; 
        
        $activity = self::shortConnection($meetId, 2);
        $curl_data = [
            'mobile_phone' => $mobile_phone,
            'token'        => md5(md5($mobile_phone . $this->config['token'])),
            'validity'     => time() + 300,
            'activity'     => $activity,
            'template'     => 36, //33模板ID
            'ccode'        => $ccode, //手机号国家编码
        ];
        // 发送验证码接口
        $curlInfo = self::curls($curl_data,'phone_curl');
        //邮件发送
        $name = $first_name ? $first_name : $user_name;
        if($email && $name ) {
            $subject = $this->config['subject'];
            $content = '尊敬的用户，您配对成功的会议邀请即将开始！请点击:' . $activity;
            $send_mail =  self::send_mail($name, $email, $subject, $content);
        }
        if( $curlInfo['code'] == 200 ) {
            self::updateAudioTranslationMeet($ID);
        }

        return true;
    }

    /**
     * 更新表数据
     */
    public function updateAudioTranslationMeet( $Id = '' , $TaskId = 1) {
        if(!$Id) { return false;}
        $where = '';
        $TaskId = intval($TaskId);
        if($TaskId) {
            $where .= 'is_success = '.  $TaskId ;
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_appointment_meet_info` SET $where WHERE `id`=:id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':id' => $Id));
    }
    /**
     * 请求触发短信接口
     */
    public function curls($info = '', $type = 'phone_curl')
    {
        //判断参数完整性
        if(!$info){return false;}
        $ch = curl_init();      
        $allow_type = [
            'phone_curl'  => 'index.php?app=api/send/phone',
            'email_curl'  => 'index.php?app=api/send/email',
        ];
        $url = $allow_type[$type];    
        $url = $this->config['phone_url'] . $url;
        
        @$url = $url. '&' . http_build_query($info);
        curl_setopt($ch, CURLOPT_URL, $url);                                
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result  = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        try {
            if($result){
                $dataarr = json_decode($result,true);         
                return $dataarr;
            }            
        } catch (\Exception $e) {
            return [];
        }
       
    }
    

    /**
     * 邮件发送
     *
     * @param: $name[string]        接收人姓名
     * @param: $email[string]       接收人邮件地址
     * @param: $subject[string]     邮件标题
     * @param: $content[string]     邮件内容
     * @param: $type[int]           0 普通邮件， 1 HTML邮件
     * @param: $notification[bool]  true 要求回执， false 不用回执
     *
     * @return boolean
     */
    function send_mail($name, $email, $subject, $content, $type = 0, $notification=false) {

        /* 如果邮件编码不是EC_CHARSET，创建字符集转换对象，转换编码 */
        $name      = iconv("utf-8", 'gb2312', $name);
        $subject   = iconv("utf-8", 'gb2312', $subject);
        $content   = iconv("utf-8", 'gb2312', $content);
        $shop_name = iconv("utf-8", 'gb2312', $this->config['subject_title']);
        $charset   = 'GB2312';
        $smtp_mail = $this->config['smtp_mail'];
        /**
         * 使用smtp服务发送邮件
         */
        $flag = 1;
        if ($flag) {
            
            /* 邮件的头部信息 */
            $content_type = ($type == 0) ?
                'Content-Type: text/plain; charset=' . $charset : 'Content-Type: text/html; charset=' . $charset;
            $content   =  base64_encode($content);

            $headers = array();
            $headers[] = 'Date: ' . gmdate('D, j M Y H:i:s') . ' +0000';
            $headers[] = 'To: "' . '=?' . $charset . '?B?' . base64_encode($name) . '?=' . '" <' . $email. '>';
            $headers[] = 'From: "' . '=?' . $charset . '?B?' . base64_encode($shop_name) . '?='.'" <' . $smtp_mail . '>';
            $headers[] = 'Subject: ' . '=?' . $charset . '?B?' . base64_encode($subject) . '?=';
            $headers[] = $content_type . '; format=flowed';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $headers[] = 'Content-Disposition: inline';
            if ($notification) {
                $headers[] = 'Disposition-Notification-To: ' . '=?' . $charset . '?B?' . base64_encode($shop_name) . '?='.'" <' . $smtp_mail . '>';
            }

            /* 获得邮件服务器的参数设置 */
            $params['host'] = $this->config['smtp_host'];
            $params['port'] = $this->config['smtp_port'];
            $params['user'] = $this->config['smtp_user'];
            $params['pass'] = $this->config['smtp_pass'];
        


            if (empty($params['host']) || empty($params['port'])) {
                // 如果没有设置主机和端口直接返回 false

                return 'smtp_setting_error';
            } else {
                
                // 发送邮件
                if (!function_exists('fsockopen')) {
                    //如果fsockopen被禁用，直接返回
                    return 'disabled_fsockopen';
                }
                
                // include_once( 'cls_smtp.php');//加载不进来 会报错
                static $smtp;
                $send_params['recipients'] = $email;
                $send_params['headers']    = $headers;
                $send_params['from']       = $smtp_mail;
                $send_params['body']       = $content;

                if (!isset($smtp)) {
                    $smtp = new smtp($params);
                }

                if ($smtp->connect() && $smtp->send($send_params))  {
                    return 'ok';
                } else {
                    $err_msg = $smtp->error_msg();

                    if (empty($err_msg)) {
                        return 'Unknown Error';
                    } else {
                        if (strpos($err_msg, 'Failed to connect to server') !== false) {
                            return '连接到服务器失败';
                        } else if (strpos($err_msg, 'AUTH command failed') !== false) {
                            return '身份验证命令失败';
                        } elseif (strpos($err_msg, 'bad sequence of commands') !== false) {
                            return '命令顺序错误';
                        } else {
                            return $err_msg;
                        }
                    }

                    return false;
                }
            }
        }
    }
    //  关闭链接
    public function __destruct() {
        $this->redis->close();
        $this->pdo = null;
    }
   
}