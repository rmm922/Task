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
 * 邮件发送基类
 */
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
                    $msg = 'dwk完成处理-taskDataSMSAbout--直播或者会议开始短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSAbout--直播或者会议开始短信发送-失败' . $res;
                }
            } else if ($data_info['type'] == 'taskDataSMSSendMeet') {//创建会议发起预约
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetSend($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--初次邮件短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSAboutMeetSend--初次邮件短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskDataSMSSendMeetBegin' ) {//创建会议发起 配对成功 即将开始    
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetBegin($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--即将开始邮件短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSMeetBegin--即将开始邮件短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskDataSMSSendMeetCancel' ) {//创建会议发起 配对成功 即将开始    
                //初始发送短信接口
                $res = $this->actionTaskDataSMSMeetSendCancel($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskDataSMSSendMeetCancel--取消邮件和短信发送-成功';
                } else {
                    $msg = 'dwk完成处理-taskDataSMSSendMeetCancel--取消邮件和短信发送-失败' . $res;
                }
            } else if( $data_info['type'] == 'taskSubmitOrder' ) {//请求报价给卖家发邮件
                //请求报价给卖家发邮件
                $res = $this->actionTaskSubmitOrder($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-taskSubmitOrder--请求报价给卖家发邮件-成功';
                } else {
                    $msg = 'dwk完成处理-taskSubmitOrder--请求报价给卖家发邮件-失败' . $res;
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

    const zdourl = 'https://chinabrandfair.eovobo.com/';//中东欧
    const hburl  = 'https://hebei.eovobo.com/';//河北
    const nmurl  = 'https://www.efair123.com/';//内蒙古


    /*-------------20210409请求报价 提交意向订单 给 卖家发送邮件 触发队列邮件接口 开始--------------*/
    /**
     * 20210409
     * 请求报价消息添加
     * 提交意向订单 给 卖家发送邮件 触发队列邮件接口
     */
    public function actionTaskSubmitOrder($data_info = '') {
        $data         = $data_info['data'];
        $goodsID      =  ! empty( $data['goodsId'] ) ? $data['goodsId'] : '';//商品ID
        $userId       =  ! empty( $data['userId'] ) ? $data['userId'] : '';//用户ID
        $template_id  =  ! empty( $data['template_id'] ) ? $data['template_id'] : '';//模板ID
        $clientDomainName = ! empty( $data['clientDomainName'] ) ? $data['clientDomainName'] : '';//商品详情

        $user_to_goods_info   = self::selectAppointmentInfo( $goodsID , 11);//商品对应的用户ID 和商品的详情
        if ( empty( $user_to_goods_info ) ) {
            return false;
        }

        //2小时内不能重复报价同一个商品
        $redis_goods_name ='ver_wz_get_user_message_num_sms_goods_'.$goodsID.'_uid_'.$userId;
        if ( $this->redis->exists( $redis_goods_name ) ) {
            return false;
        }
        try {
            $user_to_id               = !empty($user_to_goods_info['user_id']) ? $user_to_goods_info['user_id'] : '';//商品对应的用户ID
            //验证是否是自己是商品
            if( $userId == $user_to_id ) {
                return false;
            }
            $goods_name               = !empty($user_to_goods_info['goods_name']) ? $user_to_goods_info['goods_name'] : '';//zh商品名称
            $goods_name__en           = !empty($user_to_goods_info['goods_name__en']) ? $user_to_goods_info['goods_name__en'] : '';//en商品名称

            $userData                 = self::selectAppointmentInfo($userId, 3); //请求人的用户信息
            $userinfo                 = $userData[0];
            $first_name               = ! empty ( $userinfo['first_name'] ) ?  $userinfo['first_name'] : '';//登录用户的用户名
            $email                    = ! empty ( $userinfo['email'] ) ?  $userinfo['email'] : '';//登录用户的邮箱
            $mobile_phone             = ! empty ( $userinfo['mobile_phone'] ) ?  $userinfo['mobile_phone'] : '';//登录用户的电话
            $address                  = ! empty ( $userinfo['address'] ) ?  $userinfo['address'] : '';//登录用户的地址

            $template_info_zh   = [
                '{user_name}'   => $first_name,
                '{goods_name}'  => $goods_name,
                '{real_name}'   => $first_name,
                '{email}'       => $email,
                '{mobile}'      => $mobile_phone,
                '{address}'     => $address,
                '{time}'        => date('Y-m-d H:i:s', time())
            ];
            $template_info_en   = [
                '{user_name}'   => $first_name,
                '{goods_name}'  => $goods_name__en,
                '{real_name}'   => $first_name,
                '{email}'       => $email,
                '{mobile}'      => $mobile_phone,
                '{address}'     => $address,
                '{time}'        => date('Y-m-d H:i:s', time())
            ];
            
            $template_sql_data      = self::selectAppointmentInfo( $template_id , 12);//查找模板

            //邮件内容
            $content    =  str_replace(array_keys($template_info_zh), array_values($template_info_zh), $template_sql_data['content']);
            $content_en =  str_replace(array_keys($template_info_en), array_values($template_info_en), $template_sql_data['content_en']);

            //给发邮件人的信息
            $userDataTo  = self::selectAppointmentInfo($user_to_id, 3); //卖家的用户信息
            $userinfoTo  = $userDataTo[0];

            $first_name_to   = ! empty( $userinfoTo['first_name'] )   ? $userinfoTo['first_name'] : ''; 
            $user_name_to    = ! empty( $userinfoTo['user_name'] )    ? $userinfoTo['user_name'] : ''; 
            $nameTo     = $first_name_to ? $first_name_to : $user_name_to;
            $emailTo   = ! empty( $userinfoTo['email'] )        ? $userinfoTo['email'] : ''; 
            
            $subject   = '[Request quotation]';
            $send_mail =  self::send_mail_CECZ($nameTo, $emailTo, $subject, $content);
            ######################模板变量替换结束#############
            //标记商品ID 2小时不能重复添加
            $this->redis->set($redis_goods_name,1);
            $this->redis->expire($redis_goods_name, 7200 );
            return true;
        } catch (\Exception $e) {
            return false;
        }
        
    }
     /*-------------20210409请求报价 提交意向订单 给 卖家发送邮件 触发队列邮件接口 结束--------------*/

    /**
     * 创建会议 发起预约短信内容
     */
    public function actionTaskDataSMSMeetSend($data_info = '') {
        
        $data         = $data_info['data'];
        $meetId       = $data['meetId'];//会议ID
        $userMyId     = ! empty( $data['userMyId'] ) ? $data['userMyId'] : '';//用户ID  外国游客
        $userToId     = ! empty( $data['userToId'] ) ? $data['userToId'] : '';//用户ID  中国企业
        $SendWaiFanInfo =  self::SendWaiFanInfo($meetId, $userMyId, $userToId);//userMyId外国企业
        $SendChinaInfo  =  self::SendChinaInfo($meetId, $userToId, $userMyId);//userToId中国企业
        return true;
    }

     /**
     * 展会ID 定义展会的开始时间 和 结束时间
     */
    public function exhibitionInfo($eid = 8) {
        $data = [
            8 => [//芜湖
                // 'city' => '2021 China Brand Online Fair',
                // 'time' => 'December 13-17, 2021.'
                'city' => 'Shandong B2B Online Meeting',
                'time' => 'June 15-17, 2022.'
            ],
            143 => [//辽宁
                'city' => 'Liaoning B2B Online Meeting',
                'time' => 'October 26-29, 2021.'
            ],
            144 => [//山东
                'city' => 'Shandong B2B Online Meeting',
                'time' => 'November 22-24, 2021.'
            ],
            145 => [//河北
                'city' => 'Hebei B2B Online Meeting',
                'time' => 'January 13-20, 2022.'
            ],
            151 => [//中东欧的展会eovobo  （2021年12月13日至17日）
                'city' => '2021 China Brand Online Fair',
                'time' => 'December 13-17, 2021.'
            ],
            152 => [//内蒙古  （2021年11月23日至25日）  12月13-15
                'city' => 'Neimenggu B2B Online Meeting',
                'time' => 'December 13-15, 2021.'
            ],
            154 => [//内蒙古  （2022年6月15日至17日）  6月15-17
                'city' => 'Shandong B2B Online Meeting',
                'time' => 'June 15-17, 2022.'
            ],
        ];

        return $data[$eid] ? $data[$eid] : $data[8];
    }
   

    /**
     * 中国企业 
     * meetId
     * userToId
    */
    public function SendChinaInfo ($meetId,$userID, $userMyId) {
        $send_mail = $nameEn = $selectedDay = $delete_date = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        // 外方人员信息
        $toUserInfo = self::selectAppointmentInfo($userMyId, 3); 
        $tovalue = $toUserInfo[0];
        $to_first_name   = ! empty( $tovalue['first_name'] )   ? $tovalue['first_name'] : ''; 
        $to_user_name    = ! empty( $tovalue['user_name'] )    ? $tovalue['user_name'] : ''; 
        $toname = $to_first_name ? $to_first_name : $to_user_name;

        //参加的展会信息 中方企业
        $exhibition_user = self::selectAppointmentInfo($userID, 13);
        $eid             = ! empty ( $exhibition_user ) ? $exhibition_user['eid'] : 8;
        $email_info      = self::exhibitionInfo($eid);
        $cityinfo        = $email_info['city'];
        $timeinfo        = $email_info['time'];

        $meetData =  self::selectAppointmentInfo($meetId, 4); //会议信息
        if($meetData) {
            $meetInfo = $meetData[0];
            $exhibitorsId = $meetInfo['exhibitors_id'];
            $nameEn       = $meetInfo['nameEn'];
            $selectedDay  = date('Y-m-d', $meetInfo['add_open_time'] );
            $delete_date  = date('H:i', $meetInfo['add_open_time'] ).'-'. date('H:i', $meetInfo['add_stop_time'] );
        }    
        if($userInfo) {
            $value = $userInfo[0];
            if($eid == 145) 
            {
                // $sendUrl  = self::hburl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id=' . $eid;
                $sendUrl  = self::hburl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;

            } 
            else if ($eid == 152)  
            {
                $sendUrl  = self::nmurl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
            } 
            else 
            {
                $sendUrl  = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id=' . $eid;
            }
            // $sendUrl  = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id=' . $eid;
            $activity = self::shortConnection($sendUrl, 4);//短连链接
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = 'You have a new appointment';
                $content = 'Dear '.$name.',<br/><br/>

                You have a new meeting with an overseas company at the '.$cityinfo.'. Please find below the summary of your appointments: <br/><br/>

                Buyer: '.$toname.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>


                Please click  <a href="'.$activity.'">HERE</a> to view your appointment list. <br/>

                Looking forward to meeting you at the '.$cityinfo.': '.$timeinfo.'<br/><br/>

                We sincerely wish you a successful exhibition and fruitful new business connections. <br/><br/>
                Yours truly <br/><br/>
                CECZ Central-European Trade and Logistics Cooperation Zone';
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
    public function SendWaiFanInfo ($meetId,$userID, $userToId = '') {
        $send_mail = $nameEn = $selectedDay = $delete_date = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        //参加的展会信息
        $exhibition_user = self::selectAppointmentInfo($userToId, 13);
        $eid             = ! empty ( $exhibition_user ) ? $exhibition_user['eid'] : 8;
        $email_info      = self::exhibitionInfo($eid);
        $cityinfo        = $email_info['city'];
        $timeinfo        = $email_info['time'];
        $meetData =  self::selectAppointmentInfo($meetId, 4); //会议信息
        if($meetData) {
            $meetInfo = $meetData[0];
            $exhibitorsId = $meetInfo['exhibitors_id'];
            $nameEn       = $meetInfo['nameEn'];
            $selectedDay  = date('Y-m-d', $meetInfo['add_open_time'] );
            $delete_date  = date('H:i', $meetInfo['add_open_time'] ).'-'. date('H:i', $meetInfo['add_stop_time'] );
        }    
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数
        if($userInfo) {
            $value = $userInfo[0];
            if($eid == 145) 
            {
                // $sendUrl = self::hburl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
                $sendUrl  = self::hburl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;

            } 
            else if ($eid == 152)  
            {
                $sendUrl  = self::nmurl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
            } 
            else 
            {
                $sendUrl = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
            }
            // $sendUrl = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
            $activity = self::shortConnection($sendUrl, 4);//短连链接
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = 'Appointments confirmation';
                $content = 'Dear '.$name.', <br/><br/>
                Thank you for making appointments with your Chinese partner companies at the '.$cityinfo.'. Please find below the summary of your appointments: <br/><br/>
                Supplier: '.$nameEn.'. <br/><br/>
                Date：'.$selectedDay.' <br/><br/>
                Budapest Time: '.self::hours_info_all($delete_date,2).' <br/><br/>
                Beijing Time: '.$delete_date.' <br/><br/>
                Should you wish to change an appointment or make a new one, please click <a href="'.$activity.'">HERE</a> . <br/><br/>
                Looking forward to meeting you at the '.$cityinfo.': '.$timeinfo.'<br/><br/>

                We sincerely wish you a successful exhibition and fruitful new business connections. <br/><br/>


                Yours truly <br/>
                CECZ Central-European Trade and Logistics Cooperation Zone ';
                $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
            }
        }
        return true;
    }

    /**
     * 预约直播 开始直播 发送短信 邮件
     */
    public function actionTaskDataSMSAbout($data_info = '') {
        //两小时内不能重复发送
        $redis_name_key = 'ver_wz_task_sms_';
        //数据库数据ID  直播间的ID
        $ID               = $data_info['rid'];
        $exhibition_type  = $data_info['exhibition_type'] ? $data_info['exhibition_type'] : 'exhibition_room';
        if(!$ID) { return false;}
        $dataInfo1  = self::selectAppointmentInfo($ID, 6, $exhibition_type);//查出 web 端 p46_notice 里面的数据 条件 t_name = exhibiton_room  meet_id = $ID
        if($dataInfo1) {
            foreach ($dataInfo1 as $key => $val) { //循环处理数据
                $userID   =  $val['user_id'];
                $live_address_url   =  $val['live_address_url'];
                $userInfo =  self::selectAppointmentInfo($userID, 3); //用户的基本信息

                if($userInfo && $live_address_url ) {
                    //检测是否已经发送过
                    $redis_name = $redis_name_key .$ID .'_'. $userID;
                    if( ! $this->redis->exists($redis_name) ) {

                        $activity = self::shortConnection($live_address_url, 4);
                        $value = $userInfo[0];
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
                            'template'     => 34, //34模板ID
                            'ccode'        => $ccode, //手机号国家编码
                        ];
                        // 发送验证码接口
                        // $curlInfo = self::curls($curl_data,'phone_curl');
                        //邮件发送
                        $name = $first_name ? $first_name : $user_name;
                        if($email && $name ) {
                            $subject = '[2021  Brand Online Promotion] Appointments confirmation';
                            $content = 'Dear '.$name.',<br/><br/>
                            your booked appointment will start soon, please click <a href="'.$activity.'">HERE</a> <br/><br/>';
                            $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content,'EOVOBO');
                        }

                        //存入redis
                        $this->redis->set($redis_name, 1);
                        $this->redis->expire($redis_name, 7200);
                    }    
                }
            }
        }
        return true;
    }
    /**
     * 生成短连接新方法
     * @param $ID 直播间ID
     */
    public function shortConnection($ID = '', $roomUrl = 1 ) {
        if(!$ID) { return false;}
        if($roomUrl == 1) {
            $room_url  = self::zdourl.'index.php?app=exhibition/info&id=' . $ID . '&status=0';
        } else if($roomUrl == 2) {
            $room_url  = self::zdourl.'index.php?app=user/meeting_details&meetId='. $ID;
        } else if($roomUrl == 3) {
            $room_url  = self::zdourl.'index.php?app=User/myMeeting_wrap';
        } else if($roomUrl == 4) {
            $room_url  = $ID;
        } 
        return $room_url;
        $long_url    = $room_url;
        $now         = date('Y-m-d H:i:s',time());  
        $expire_date = date("Y-m-d",strtotime("+10years",strtotime($now)));
        $info        =  self::getShortUrl($long_url, $expire_date);
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
     * 生成短连接
     * @param $ID 直播间ID
     */
    public function shortConnection_bak($ID = '', $roomUrl = 1 ) {
        if(!$ID) { return false;}
        if($roomUrl == 1) {
            $room_url  = self::zdourl.'index.php?app=exhibition/info&id=' . $ID . '&status=0';
        } else if($roomUrl == 2) {
            $room_url  = self::zdourl.'index.php?app=user/meeting_details&meetId='. $ID;
        } else if($roomUrl == 3) {
            $room_url  = self::zdourl.'index.php?app=User/myMeeting_wrap';
        } else if($roomUrl == 4) {
            $room_url  = $ID;
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
    public function selectAppointmentInfo($ID = '', $type = '', $p46_notice_t_name = 'exhibition_room') {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        if($type == 1) {
            $sql  = 'SELECT peu.user_id as user_id  FROM p46_exhibition_room AS per JOIN p46_exhibition_user AS  peu ON per.uid = peu.uid   WHERE  per.rid = ' . $ID . ' ';
        } else if($type == 2) {
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode  FROM  p46_appointment AS pa  JOIN p46_users  AS pu ON pa.from_id = pu.user_id WHERE pa.statu = 1 AND  pa.to_id = '$ID'";
        } else if($type == 3) {
            //用户信息
            $sql  = "SELECT  mobile_phone,email,first_name, user_name,ccode, `address`  FROM  p46_users  WHERE user_id = '$ID'";
        } else if($type == 4) {
            $sql = 'SELECT ua.name__en as nameEn, ni.add_open_time, ni.add_stop_time,ni.exhibitors_id,http_host_source FROM p46_user_apply as ua JOIN p46_negotiation_info as ni ON ua.id = ni.exhibitors_id WHERE ni.id = ' . $ID;
        } else if($type == 5) {
            $sql = 'SELECT name__en as nameEn  FROM p46_user_apply  WHERE id = ' . $ID;
        } else if($type == 6) {
            $sql = "SELECT user_id,live_address_url FROM p46_notice  WHERE meet_id = $ID  AND t_name = '$p46_notice_t_name'";
        //商品    
        } else if($type == 11) {
            $sql = "SELECT user_id,goods_name,goods_name__en FROM  p46_goods WHERE  goods_id = $ID";
        //模板    
        } else if($type == 12) {
            $sql =  "SELECT * FROM  p46_msg_system WHERE  sy_id = $ID";
        } else if($type == 13) {
            $sql =  "SELECT eid FROM p46_exhibition_user WHERE user_id = $ID ORDER BY `uid` DESC LIMIT 1";
        }
        $rs = $this->pdo->query($sql);
        $rs->setFetchMode(\PDO::FETCH_ASSOC);
        $dbData = $rs->fetchAll();
        $return_data = [];
        if($dbData) {
            $return_data =  $type  > 10 ? $dbData[0] : $dbData;
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
        // $curlInfo = self::curls($curl_data,'phone_curl');
        //邮件发送
        $name = $first_name ? $first_name : $user_name;
        if($email && $name ) {
            // $subject = $this->config['subject'];
            // $content = '尊敬的用户，您预约的'.$activity.'还有2分钟开始！';
            // $send_mail =  self::send_mail($name, $email, $subject, $content);
            $subject = '';
            $content = 'Dear '.$name.',<br/><br/>
            Dear participant, your booked appointment will start in 2 minutes<br/><br/>';
            $send_mail =  self::send_mail_CECZ($name, $email, $subject, $content);
           
        }
        // if( $curlInfo['code'] == 200 ) {
            self::updateAudioTranslation($ID);
        // }

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
        $data        = $data_info['data'];
        //数据库数据ID
        $ID = $meetId = $data['id'];
        $userMyId     = ! empty( $data['user_my_id'] ) ? $data['user_my_id'] : ''; 
        $userToId     = ! empty( $data['user_to_id'] ) ? $data['user_to_id'] : ''; 
        $clientDomainName    = ! empty( $data['client_domain_name'] ) ? $data['client_domain_name'] :  self::zdourl;//展会模板的个人用户域名  
        $exhibitorsId        = ! empty( $data['exhibitors_id'] ) ? $data['exhibitors_id'] : '';
        $http_host_source    = ! empty( $data['http_host_source'] ) ? $data['http_host_source'] : '';
        $userInfoAll  = [
            $userMyId,
            $userToId,
        ];
        //参加的展会信息
        $exhibition_user = self::selectAppointmentInfo($userToId, 13);
        $eid             = ! empty ( $exhibition_user ) ? $exhibition_user['eid'] : 8;
        $email_info      = self::exhibitionInfo($eid);
        $cityinfo        = $email_info['city'];
        $timeinfo        = $email_info['time'];
 
        for ($i=0; $i < count($userInfoAll); $i++) { 
            $userID = $userInfoAll[$i];
            if( !is_numeric($userID) ) {
                continue;
            }
            $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数
            if($userInfo) {
                $value = $userInfo[0];
                if($eid == 145) 
                {
                    // $sendUrl  = self::hburl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
                    $sendUrl  = self::hburl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;

                } 
                else if ($eid == 152)  
                {
                    $sendUrl  = self::nmurl.'index.php?app=expo/automaticLogin&flag=private&expoId='.$eid.'&userId='.$userID.'&meetId='.$meetId.'&exhibitorsId='.$exhibitorsId;
                } 
                else 
                {
                    $sendUrl  = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
                }
                // $sendUrl  = self::zdourl.'index.php?app=User/automaticLogin&userId='.$userID.'&meetId='.$meetId.'&id='.$eid;
                $activity = self::shortConnection($sendUrl, 4);//短连链接
                $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
                $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
                $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
                //邮件发送
                $name = $first_name ? $first_name : $user_name;
                if($email && $name ) {
                    $subject = 'Reminder of upcoming appointment';
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


    function send_mail_CECZ($name, $email, $subject, $content, $head = 'CECZ', $notification=false) {
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
        self::ChinaSendCancelEmail($userToId,$exhibitorsId,$selectedDay,$deleteDate,$userMyId);//中方发送取消
        self::WaiFanSendCancelEmail($userMyId,$exhibitorsId,$selectedDay,$deleteDate, $userToId);//外方发送取消
        return true;
    }
    /**
     * 中方发送取消邮件
     */
    public function ChinaSendCancelEmail($userID, $exhibitorsId = '', $selectedDay = '', $delete_date = '', $userMyId = '') {
        $send_mail = $nameEn = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        // 外方人员信息
        $toUserInfo = self::selectAppointmentInfo($userMyId, 3); 
        $tovalue = $toUserInfo[0];
        $to_first_name   = ! empty( $tovalue['first_name'] )   ? $tovalue['first_name'] : ''; 
        $to_user_name    = ! empty( $tovalue['user_name'] )    ? $tovalue['user_name'] : ''; 
        $toname = $to_first_name ? $to_first_name : $to_user_name;

        $exhibitorsInfo =  self::selectAppointmentInfo($exhibitorsId, 5); //展示信息
        $nameEn = !empty($exhibitorsInfo) ? $exhibitorsInfo[0]['nameEn'] : '';

        //参加的展会信息
        $exhibition_user = self::selectAppointmentInfo($userID, 13);
        $eid             = ! empty ( $exhibition_user ) ? $exhibition_user['eid'] : 8;
        $email_info      = self::exhibitionInfo($eid);
        $cityinfo        = $email_info['city'];
        $timeinfo        = $email_info['time'];

        if($userInfo) {
            $value = $userInfo[0];
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = 'Appointment cancellation';
                $content = 'Dear '.$name.',<br/><br/>

                Your appointment has been cancelled by the buyer:<br/><br/>

                Buyer: '.$toname.'. <br/><br/>
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
    public function WaiFanSendCancelEmail($userID, $exhibitorsId = '', $selectedDay = '', $delete_date = '', $userToId = '') {
        $send_mail = $nameEn = '';
        $userInfo =  self::selectAppointmentInfo($userID, 3); //关联查出我的收到预约列表人数

        $exhibitorsInfo =  self::selectAppointmentInfo($exhibitorsId, 5); //展示信息
        $nameEn = !empty($exhibitorsInfo) ? $exhibitorsInfo[0]['nameEn'] : '';
        
        //参加的展会信息
        $exhibition_user = self::selectAppointmentInfo($userToId, 13);
        $eid             = ! empty ( $exhibition_user ) ? $exhibition_user['eid'] : 8;
        $email_info      = self::exhibitionInfo($eid);
        $cityinfo        = $email_info['city'];
        $timeinfo        = $email_info['time'];
        
        if($userInfo) {
            $value = $userInfo[0];
            $email        = ! empty( $value['email'] )        ? $value['email'] : ''; 
            $first_name   = ! empty( $value['first_name'] )   ? $value['first_name'] : ''; 
            $user_name    = ! empty( $value['user_name'] )    ? $value['user_name'] : ''; 
            //邮件发送
            $name = $first_name ? $first_name : $user_name;
            if($email && $name ) {
                $subject = 'Appointment cancellation';
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
            '11:00-11:30',
            '11:30-12:00',
            '12:00-12:30',
            '12:30-13:00',
            '13:00-13:30',
            '13:30-14:00',
            '14:00-14:30',
            '14:30-15:00',
            '15:00-15:30',
            '15:30-16:00',
            '16:00-16:30',
            '16:30-17:00',
            '17:00-17:30',
            '17:30-18:00',
            '18:00-18:30',
            '18:30-19:00',
            '19:00-19:30',
            '19:30-20:00',
            '20:00-20:30',
            '20:30-21:00',
            '21:00-21:30',
            '21:30-22:00',
        ];

        $data_buda = [
            '09:00-09:30' => '02:00-02:30',
            '09:30-10:00' => '02:30-03:00',
            '10:00-10:30' => '03:00-03:30',
            '10:30-11:00' => '03:30-04:00',
            '11:00-11:30' => '04:00-04:30',
            '11:30-12:00' => '04:30-05:00',
            '12:00-12:30' => '05:00-05:30',
            '12:30-13:00' => '05:30-06:00',
            '13:00-13:30' => '06:00-06:30',
            '13:30-14:00' => '06:30-07:00',
            '14:00-14:30' => '07:00-07:30',
            '14:30-15:00' => '07:30-08:00',
            '15:00-15:30' => '08:00-08:30',//预约页，我们现在和北京的时差是6个小时
            '15:30-16:00' => '08:30-09:00',//预约页，我们现在和北京的时差是6个小时
            '16:00-16:30' => '09:00-09:30',//预约页，我们现在和北京的时差是6个小时
            '16:30-17:00' => '09:30-10:00',//预约页，我们现在和北京的时差是6个小时
            '17:00-17:30' => '10:00-10:30',//预约页，我们现在和北京的时差是6个小时
            '17:30-18:00' => '10:30-11:00',//预约页，我们现在和北京的时差是6个小时
            '18:00-18:30' => '11:00-11:30',
            '18:30-19:00' => '11:30-12:00',
            '19:00-19:30' => '12:00-12:30',
            '19:30-20:00' => '12:30-13:00',
            '20:00-20:30' => '13:00-13:30',
            '20:30-21:00' => '13:30-14:00',
            '21:00-21:30' => '14:00-14:30',
            '21:30-22:00' => '14:30-15:00',
        ];
        return $flag == 2 ? $data_buda[$key] : $data;
    }

    //  关闭链接
    public function __destruct() {
        $this->redis->close();
        $this->pdo = null;
    }
   
}