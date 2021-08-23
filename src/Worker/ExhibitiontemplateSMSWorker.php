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
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

define('SMTP_STATUS_NOT_CONNECTED', 1);
define('SMTP_STATUS_CONNECTED',     2);
/**
 * 邮件发送基类 展会模板
 */
class ExhibitiontemplateSMSWorker extends BaseWorker implements Worker
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
            
            if ($data_info['type'] == 'taskDataSMSSendMeet') {//创建会议发起预约
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetSend($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--展会模板初次邮件短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--展会模板初次邮件短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskDataSMSSendMeetBegin' ) {//创建会议发起 配对成功 即将开始    
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetBegin($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--展会模板即将开始邮件短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--展会模板即将开始邮件短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskDataSMSSendMeetCancel' ) {//创建会议发起 配对成功 取消会议    
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetSendCancel($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSSendMeetCancel--展会模板取消邮件和短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSSendMeetCancel--展会模板取消邮件和短信发送-失败' . $res;
                }
            } else if ($data_info['type'] == 'taskExhibitionPrepareSMS') {  //用户预约展会即将开始的前一天

                $res = $this->actionTaskExhibitionPrepareSMS($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskExhibitionPrepareSMS--提醒消息发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskExhibitionPrepareSMS--提醒消息发送-失败' . $res;
                }
            } else if ($data_info['type'] == 'taskExhibitionStartSMS') {  //用户预约展会即将开始当天
                $res = $this->actionTaskExhibitionStartSMS($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskExhibitionStartSMS--提醒消息发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskExhibitionStartSMS--提醒消息发送-失败' . $res;
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

    const hebeiurl = 'https://hebei.eovobo.com/';

        /**
     * 发送短信服务执行 之 展会即将开始的前一天
     */
    public function actionTaskExhibitionPrepareSMS($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $mobile_phone = ! empty( $data['mobile_phone'] ) ? $data['mobile_phone'] : ''; 
        $email        = ! empty( $data['email'] ) ? $data['email'] : ''; 
        $first_name   = ! empty( $data['first_name'] ) ? $data['first_name'] : ''; 
        $ccode        = ! empty( $data['ccode'] ) ? $data['ccode'] : '86'; 
        $client_domain_name    = ! empty( $data['client_domain_name'] ) ? $data['client_domain_name'] : ''; 
        $data_source           = ! empty( $data['data_source'] ) ? $data['data_source'] : 0; 
        $title           = ! empty( $data['title'] ) ? $data['title'] : ''; 
        $exhibition = $title . '，还有一天开始';
        $curl_data = [
            'mobile_phone' => $mobile_phone,
            'token'        => md5(md5($mobile_phone . $this->config['token'])),
            'validity'     => time() + 300,
            'template'     => $this->config['template'], //33模板ID
            'ccode'        => $ccode, //手机号国家编码
            'exhibition'   => $exhibition, //手机号国家编码
        ];
        // 发送验证码接口
        if($title) {
            $curlInfo = self::curls($curl_data,'phone_curl');
        }
        //邮件发送
        $name = $first_name ? $first_name : 'User';
        if($email && $name ) {
            $subject = 'You have a new remind';
            $content = 'Dear '.$name.',<br/><br/>
            Dear participant, your booked appointment will start in 1 day<br/><br/>';
            $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content,'EOVOBO');
        }
        $ID = [
            'fromid' => $data['fromid'],
            'toid'   => $data['toid'],
        ];
        self::update_p46_user_exhibition_follow($ID, 1);

        return true;
    }

     /**
     * 发送短信服务执行 之 展会即将开始当天
     */
    public function actionTaskExhibitionStartSMS($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $mobile_phone = ! empty( $data['mobile_phone'] ) ? $data['mobile_phone'] : ''; 
        $email        = ! empty( $data['email'] ) ? $data['email'] : ''; 
        $first_name   = ! empty( $data['first_name'] ) ? $data['first_name'] : ''; 
        $ccode        = ! empty( $data['ccode'] ) ? $data['ccode'] : '86'; 
        $client_domain_name    = ! empty( $data['client_domain_name'] ) ? $data['client_domain_name'] : ''; 
        $data_source           = ! empty( $data['data_source'] ) ? $data['data_source'] : 0; 
        $title           = ! empty( $data['title'] ) ? $data['title'] : ''; 
        $exhibition = $title . '，即将开始';
        $curl_data = [
            'mobile_phone' => $mobile_phone,
            'token'        => md5(md5($mobile_phone . $this->config['token'])),
            'validity'     => time() + 300,
            'template'     => $this->config['template'], //33模板ID
            'ccode'        => $ccode, //手机号国家编码
            'exhibition'   => $exhibition, //手机号国家编码
        ];
        // 发送验证码接口
        if($title) {
            $curlInfo = self::curls($curl_data,'phone_curl');
        }
        //邮件发送
        $name = $first_name ? $first_name : 'User';
        if($email && $name ) {
            $activity = '';
            $subject = 'You have a new remind';
            $content = 'Dear '.$name.',<br/><br/>
            your booked appointment will start soon, please click <a href="'.$activity.'">HERE</a> <br/><br/>';
            $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content,'EOVOBO');
        }
        $ID = [
            'fromid' => $data['fromid'],
            'toid'   => $data['toid'],
        ];
        self::update_p46_user_exhibition_follow($ID, 2);
        return true;
    }
    
    /**
     * 更新表数据
     */
    public function update_p46_user_exhibition_follow( $Id = '' , $TaskId = 1) {
        if(!$Id) { return false;}
        $where = '';
        $TaskId = intval($TaskId);
        if($TaskId) {
            $where .= 'is_success = '.  $TaskId ;
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_user_exhibition_follow` SET $where WHERE `fromid`=:fromid AND `toid` = :toid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array( ':fromid' => $Id['fromid'],  ':toid' => $Id['toid']  ));
       
    }

    /**
     * 创建会议 发起预约短信内容
     */
    public function actionTaskDataSMSMeetSend($data_info = '') {
        
        $data         = $data_info['data'];
        $meetId       = $data['meetId'];//会议ID
        $userMyId     = ! empty( $data['userMyId'] ) ? $data['userMyId'] : '';//用户ID  外国游客
        $userToId     = ! empty( $data['userToId'] ) ? $data['userToId'] : '';//用户ID  中国企业
        $clientDomainName = ! empty( $data['clientDomainName'] ) ? $data['clientDomainName'] : self::hebeiurl;//展会模板的个人用户域名  
        $SendChinaInfo  =  self::SendChinaInfo($meetId, $userToId, $clientDomainName);//userToId中国企业
        $SendWaiFanInfo =  self::SendWaiFanInfo($meetId, $userMyId, $clientDomainName);//userMyId外国企业
        return true;
    }
    /**
     * 中国企业 
     * meetId
     * userToId
    */
    public function SendChinaInfo ($meetId,$userID, $clientDomainName) {
        $send_mail = $nameEn = $selectedDay = $delete_date = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        $meetData =  self::selectAppointmentInfo($meetId, 4); //会议信息
        if($meetData) {
            $meetInfo = $meetData[0];
            $nameEn       = $meetInfo['nameEn'];
            $exhibitorsId = $meetInfo['exhibitors_id'];
            $selectedDay  = date('Y-m-d', $meetInfo['add_open_time'] );
            $delete_date  = date('H:i', $meetInfo['add_open_time'] ).'-'. date('H:i', $meetInfo['add_stop_time'] );
            $http_host_source  = $meetInfo['http_host_source'];

        }    
        if($userInfo) {
            $value = $userInfo[0];
            $sendUrl =  $clientDomainName.'index.php?app=expo/automaticLogin&flag=private&expoId='.$http_host_source.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
            $activity = self::shortConnection($sendUrl, 4);//短连链接
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = '[2021 Brand Online Promotion] You have a new appointment';
                $content = 'Dear '.$name.',<br/><br/>

                You have a new meeting with an overseas company at the 2021 Brand Online Promotion. Please find below the summary of your appointments: <br/><br/>

                Buyer: '.$nameEn.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>


                Please click  <a href="'.$activity.'">HERE</a> to view your appointment list. <br/>

                Looking forward to meeting you at the 2021  Brand Online Promotion.<br/><br/>

                We sincerely wish you a successful exhibition and fruitful new business connections. <br/><br/>
                Yours truly <br/><br/>
                Brand Online Promotion';
                $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
            }
        }
        return $send_mail;
    }

    /**
     * 外国游客
     * meetId
     * userMyId
     */
    public function SendWaiFanInfo ($meetId,$userID, $clientDomainName) {
        $send_mail = $nameEn = $selectedDay = $delete_date = '';

        $meetData =  self::selectAppointmentInfo($meetId, 4); //会议信息
        if($meetData) {
            $meetInfo = $meetData[0];
            $nameEn       = $meetInfo['nameEn'];
            $exhibitorsId = $meetInfo['exhibitors_id'];
            $selectedDay  = date('Y-m-d', $meetInfo['add_open_time'] );
            $delete_date  = date('H:i', $meetInfo['add_open_time'] ).'-'. date('H:i', $meetInfo['add_stop_time'] );
            $http_host_source  = $meetInfo['http_host_source'];
        }    
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数
        if($userInfo) {
            $value = $userInfo[0];
            $sendUrl  = $clientDomainName.'index.php?app=expo/automaticLogin&flag=private&expoId='.$http_host_source.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
            $activity = self::shortConnection($sendUrl, 4);//短连链接
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = '[2021  Brand Online Promotion] Appointments confirmation';
                $content = 'Dear '.$name.', <br/><br/>
                Thank you for making appointments with your Chinese partner companies at the 2021 Brand Online Promotion. Please find below the summary of your appointments: <br/><br/>
                Supplier: '.$nameEn.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>
                Should you wish to change an appointment or make a new one, please click <a href="'.$activity.'">HERE</a> . <br/><br/>
                Looking forward to meeting you at the 2021 Brand Online Promotion.<br/><br/>

                We sincerely wish you a successful exhibition and fruitful new business connections. <br/><br/>


                Yours truly <br/>
                Brand Online Promotion';
                $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
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
        if($roomUrl == 4) {
            $room_url  = $ID;
        } 

        $long_url    = $room_url;
        $now         = date('Y-m-d H:i:s',time());  
        $expire_date = date("Y-m-d",strtotime("+10years",strtotime($now)));
        $info =  self::getShortUrl($long_url, $expire_date);
        return $info;
       
    }

    /**
     * @param $long_url 长网址
     * @param $expire_date 过期日期
     * 
     * // 说明
     * 1. key 获取：登录后，进入首页-导航栏-短网址-API接口可查看您的key
     * 2. expire_date 过期日期，暂支持到年月日
     * key： 5fd9b04984188a7d2e2d5249aa@287d876c610fc097ba5ff61067c6e175 复制
     * 请求地址：http://api.3w.cn/api.htm
     * 请求方式：GET
     */
    function getShortUrl($long_url, $expire_date)
    {
        $url = urlencode($long_url);
        // $key = "5fd9b04984188a7d2e2d5249aa@287d876c610fc097ba5ff61067c6e175";
        $key =  $this->config['getShortUrlkey'];
        $request_url = "http://api.3w.cn/api.htm?format=json&url={$url}&key={$key}&expireDate={$expire_date}&domain=0";
        $result_str = file_get_contents($request_url);
        $url = "";
        if ($result_str) {
            $result_arr = json_decode($result_str, true);
            if ($result_arr && $result_arr['code'] == "0") {
                $url = $result_arr['url'];
            }
        }
        return $url;
    }

    /**
     * 查询表数据 有关开直播预约的信息处理
     */
    public function selectAppointmentInfo($ID = '', $type = '', $p46_notice_t_name = 'exhibiton_room') {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        if($type == 1) {
            $sql  = 'SELECT peu.user_id as user_id  FROM p46_exhibition_room AS per JOIN p46_exhibition_user AS  peu ON per.uid = peu.uid   WHERE  per.rid = ' . $ID . ' ';
        } else if($type == 2) {
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode  FROM  p46_appointment AS pa  JOIN p46_users  AS pu ON pa.from_id = pu.user_id WHERE pa.statu = 1 AND  pa.to_id = '$ID'";
        } else if($type == 3) {
            //用户信息
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode  FROM  p46_users  WHERE user_id = '$ID'";
        } else if($type == 4) {
            $sql = 'SELECT ua.name__en as nameEn, ni.add_open_time, ni.add_stop_time,ni.exhibitors_id,http_host_source FROM p46_user_apply as ua JOIN p46_negotiation_info as ni ON ua.id = ni.exhibitors_id WHERE ni.id = ' . $ID;
        } else if($type == 5) {
            $sql = 'SELECT name__en as nameEn  FROM p46_user_apply  WHERE id = ' . $ID;
        } else if($type == 6) {
            $sql = "SELECT user_id FROM p46_notice  WHERE meet_id = $ID  AND t_name = $p46_notice_t_name";
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
     * 发送短信服务执行 配对会议即将开始5分钟
     */
    public function actionTaskDataSMSMeetBegin($data_info = '') {
        $data        = $data_info['data'];
        //数据库数据ID
        $ID = $meetId = $data['id'];
        $userMyId     = ! empty( $data['user_my_id'] ) ? $data['user_my_id'] : ''; 
        $userToId     = ! empty( $data['user_to_id'] ) ? $data['user_to_id'] : ''; 
        $clientDomainName    = ! empty( $data['client_domain_name'] ) ? $data['client_domain_name'] :  self::hebeiurl;//展会模板的个人用户域名  
        $exhibitorsId        = ! empty( $data['exhibitors_id'] ) ? $data['exhibitors_id'] : '';
        $http_host_source    = ! empty( $data['http_host_source'] ) ? $data['http_host_source'] : '';
        $userInfoAll  = [
            $userMyId,
            $userToId,
        ];
        for ($i=0; $i < count($userInfoAll); $i++) { 
            $userID = $userInfoAll[$i];
            if( !is_numeric($userID) ) {
                continue;
            }
            $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数
            if($userInfo) {
                $value = $userInfo[0];
                $sendUrl  = $clientDomainName.'index.php?app=expo/automaticLogin&flag=private&expoId='.$http_host_source.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
                $activity = self::shortConnection($sendUrl, 4);//短连链接
                $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
                $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
                $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
                //邮件发送
                $name = $first_name ? $first_name : $user_name;
                if($email && $name ) {
                    $subject = '[2021 Brand Online Promotion] Reminder of upcoming appointment';
                    $content = 'Dear '.$name.',<br/><br/>

                    Your next video meeting starts in 10 minutes, please click <a href="'.$activity.'">HERE</a> to start the video conference:<br/><br/>
                    Yours truly<br/>
                    The Organisers';
                    $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
                }
            }
        }
        //修改   p46_negotiation_info 表里的 is_success
        self::updateAudioTranslationMeet($ID);
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
        $sql  = "UPDATE `p46_negotiation_info` SET $where WHERE `id`=:id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':id' => $Id));
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
        // Instantiation and passing `true` enables exceptions
        $mail = new PHPMailer(true); //PHPMailer对象
    
        $host = $this->config['smtp_host'];
        $port = $this->config['smtp_port'];
        $user = $this->config['smtp_user'];
        $pass = $this->config['smtp_pass'];
        $subject =  $this->config['subject'];
        $subject_title =  $this->config['subject_title'];
        $smtp_mail     =  $this->config['smtp_mail'];
        try {
            //Server settings
            $mail->CharSet = "UTF-8";	                                //设定邮件编码，默认ISO-8859-1，如果发中文此项必须设置，否则乱码
            $mail->SMTPDebug = 0;                                       // 启用 SMTP 验证功能
            $mail->isSMTP();                                            // 设定使用SMTP服务
            $mail->Host       = $host;                                  // Set the SMTP server to send through
            $mail->SMTPAuth   = true;                                   // 启用 SMTP 验证功能
            $mail->Username   = $user;                                  // SMTP username
            $mail->Password   = $pass;                                  // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
            $mail->Port       = $port;                                  // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
    
            //Recipients
            $mail->setFrom($smtp_mail, $subject_title);
            $mail->addAddress($email, $name);                    // Add a recipient
    
            // Content
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = $subject;
            $mail->Body    = $content;
    
            $result = $mail->Send() ? '200' : $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            return true;
        }
    }


    function send_mail_CECZ($name, $email, $subject, $content, $head = 'China', $notification=false) {    
        $mail = new PHPMailer(true); //PHPMailer对象
    
        $host = $this->config['smtp_host'];
        $port = $this->config['smtp_port'];
        $user = $this->config['smtp_user'];
        $pass = $this->config['smtp_pass'];
        // $subject =  $this->config['subject'];
        $subject_title =  $this->config['subject_title'];
        $smtp_mail     =  $this->config['smtp_mail'];
        try {
            //Server settings
            $mail->CharSet = "UTF-8";	                                //设定邮件编码，默认ISO-8859-1，如果发中文此项必须设置，否则乱码
            $mail->SMTPDebug = 0;                                       // 启用 SMTP 验证功能
            $mail->isSMTP();                                            // 设定使用SMTP服务
            $mail->Host       = $host;                                  // Set the SMTP server to send through
            $mail->SMTPAuth   = true;                                   // 启用 SMTP 验证功能
            $mail->Username   = $user;                                  // SMTP username
            $mail->Password   = $pass;                                  // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
            $mail->Port       = $port;                                  // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
    
            //Recipients
            $mail->setFrom($smtp_mail, $head);
            $mail->addAddress($email, $name);                    // Add a recipient
    
            // Content
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = $subject;
            $mail->Body    = $content;
    
            $result = $mail->Send() ? '200' : $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            return true;
        }
    }
       

    /**
     * 会议取消
     */
    public function actionTaskDataSMSMeetSendCancel($data_info = '') {
        $data           = $data_info['data'];
        $meetId         = $data['meetId'];//会议ID
        $userMyId       = ! empty( $data['userMyId'] ) ? $data['userMyId'] : '';//用户ID 
        $userToId       = ! empty( $data['userToId'] ) ? $data['userToId'] : '';//用户ID 
        $selectedDay    = ! empty( $data['selectedDay'] ) ? $data['selectedDay'] : '';//日期
        $deleteDate     = ! empty( $data['deleteDate'] ) ? $data['deleteDate'] : '';//时间段 
        $exhibitorsId     = ! empty( $data['exhibitorsId'] ) ? $data['exhibitorsId'] : '';//展示ID 
        $clientDomainName = ! empty( $data['clientDomainName'] ) ? $data['clientDomainName'] : self::hebeiurl;//展会模板的个人用户域名  
        self::ChinaSendCancelEmail($userToId,$exhibitorsId,$selectedDay,$deleteDate, $clientDomainName);//中方发送取消
        self::WaiFanSendCancelEmail($userMyId,$exhibitorsId,$selectedDay,$deleteDate, $clientDomainName);//外方发送取消
        return true;
    }
    /**
     * 中方发送取消邮件
     */
    public function ChinaSendCancelEmail($userID, $exhibitorsId = '', $selectedDay = '', $delete_date = '', $clientDomainName = '') {
        $send_mail = $nameEn = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        $exhibitorsInfo =  self::selectAppointmentInfo($exhibitorsId, 5); //展示信息
        $nameEn = !empty($exhibitorsInfo) ? $exhibitorsInfo[0]['nameEn'] : '';

        if($userInfo) {
            $value = $userInfo[0];
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = '[2021 Brand Online Promotion] Appointment cancellation';
                $content = 'Dear '.$name.',<br/><br/>

                Your appointment has been cancelled by the buyer:<br/><br/>

                Buyer: '.$nameEn.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>
              
                Yours truly <br/><br/>
                The Organisers';
                $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
            }
        }
        return $send_mail;
    }

     /**
     * 外方发送取消邮件
     */
    public function WaiFanSendCancelEmail($userID, $exhibitorsId = '', $selectedDay = '', $delete_date = '', $clientDomainName = '') {
        $send_mail = $nameEn = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        $exhibitorsInfo =  self::selectAppointmentInfo($exhibitorsId, 5); //展示信息
        $nameEn = !empty($exhibitorsInfo) ? $exhibitorsInfo[0]['nameEn'] : '';
        if($userInfo) {
            $value = $userInfo[0];
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = '[2021 Brand Online Promotion] Appointment cancellation';
                $content = 'Dear '.$name.',<br/><br/>
                Your have successfully cancelled your appointment :<br/><br/>
                Supplier: '.$nameEn.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>
                Yours truly<br/>
                The Organisers';
                $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
            }
        }
        return $send_mail;
    }

    public function hours_info_all($key = '',$flag = 1) {
        $data = [
            '09:00-09:30',
            '09:30-10:00',
            '10:00-10:30',
            '10:30-11:00',
            '15:30-16:00',
            '16:00-16:30',
            '16:30-17:00',
            '17:00-17:30',
            '17:30-18:00',
            '18:00-18:30',
        ];

        $data_buda = [
            '09:00-09:30' => '02:00-02:30',
            '09:30-10:00' => '02:30-03:00',
            '10:00-10:30' => '03:00-03:30',
            '10:30-11:00' => '03:30-04:00',
            '15:30-16:00' => '08:30-09:00',
            '16:00-16:30' => '09:00-09:30',
            '16:30-17:00' => '09:30-10:00',
            '17:00-17:30' => '10:00-10:30',
            '17:30-18:00' => '10:30-11:00',
            '18:00-18:30' => '11:00-11:30',
        ];
        $data_yindu = [
            '09:00-09:30' => '06:30-07:00',
            '09:30-10:00' => '07:00-07:30',
            '10:00-10:30' => '07:30-08:00',
            '10:30-11:00' => '08:00-08:30',
            '15:30-16:00' => '13:00-13:30',
            '16:00-16:30' => '13:30-14:00',
            '16:30-17:00' => '14:00-14:30',
            '17:00-17:30' => '14:30-15:00',
            '17:30-18:00' => '15:00-15:30',
            '18:00-18:30' => '15:30-16:00',
        ];
        if($flag == 1) {
            return $data;
        } else if($flag == 2) {
            return  $data_buda[$key];
        } else if($flag == 3) {
            return  $data_yindu[$key];
        }
        // return $flag == 2 ? $data_buda[$key] : $data;
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
            'phone_curl'  => 'index.php?app=api/send/phone_remind',
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

    //  关闭链接
    public function __destruct() {
        $this->redis->close();
        $this->pdo = null;
    }
   
}