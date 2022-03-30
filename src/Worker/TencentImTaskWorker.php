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


class TencentImTaskWorker extends BaseWorker implements Worker
{
    private $config;
    public  $pdo;

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

    }

     /**
     * 处理job
     */
    public function handleJob():array
    {   
        try {
            //反序列化对象
            $data_info = json_decode($this->job->getData(),true);
            
            if ($data_info['type'] == 'taskImData') {
                //IM聊天异步回调
                $res = $this->actionTaskImData($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-tencentImTask-IM聊天异步回调-成功';
                } else {
                    $msg = 'dwk完成处理-tencentImTask-IM聊天异步回调-失败' . $res;
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
     * IM聊天异步回调
     */
    public function actionTaskImData($data = '') {
        //数据库数据ID
        $ip          = $data['ip'];
        $imcontent   = $data['imcontent'];//流文件数据
        $imcontent   = base64_decode($imcontent);
        $notifyData  = json_decode($imcontent, true);
        $userToId = $notifyData['To_Account'];// 接收者
        $userMyId = $notifyData['From_Account'];// 发送者
        $MsgTime  = $notifyData['MsgTime'];// 消息的发送时间戳，单位为秒 
        $OnlineOnlyFlag = $notifyData['OnlineOnlyFlag'];//在线消息，为1，否则为0；
        $UnreadMsgNum   = $notifyData['UnreadMsgNum'];// To_Account 未读的单聊消息总数量
        $MsgBody        = $notifyData['MsgBody']??[]; // 消息体

        $MsgType        = ['TIMTextElem','TIMCustomElem','TIMImageElem','TIMVideoFileElem','TIMSoundElem','TIMFileElem'];//文本消息。TIMCustomElem自定义
        $content = '';
        if($MsgBody) {
            foreach ($MsgBody as $key => $value) {
                if( in_array( $value['MsgType'], $MsgType )) {
                    //文本类型 
                    if($value['MsgType'] == 'TIMTextElem') {
                        $content .= $value['MsgContent']['Text'];
                    //自定义类型 pc  
                    } else if($value['MsgType'] == 'TIMCustomElem') {
                        $CustomElemInfo = $value['MsgContent']['Data'];
                        // $CustomElemData = $CustomElemInfo;//测试数据
                        // $CustomElemInfo = stripslashes($CustomElemInfo);//去除转义
                        $CustomElemData = json_decode($CustomElemInfo, true);//正式数据
                        $im_type = isset($CustomElemData['type']) ? $CustomElemData['type'] : 'sendText';
                        if(isset($CustomElemData['type']) && $CustomElemData['type'] != 'text' ) {// text 是特殊的一个类型 直接存储就可以 不需要 json_encode
                            $content = json_encode($CustomElemData['msg'], JSON_UNESCAPED_UNICODE );
                        } else {
                            $content = $CustomElemData['msg'];
                        }
                        
                    //图片类型     
                    } else if($value['MsgType'] == 'TIMImageElem') {
                        $TIMImageElem = $value['MsgContent']['ImageInfoArray'];
                        $content = $TIMImageElem  && is_array($TIMImageElem) ? json_encode($TIMImageElem, JSON_UNESCAPED_UNICODE ) : '';
                        $im_type = 'sendImage';
                    //视频类型     
                    } else if($value['MsgType'] == 'TIMVideoFileElem') {
                        $videoFileElem = $value['MsgContent'];
                        unset($videoFileElem['VideoUUID']);
                        unset($videoFileElem['VideoDownloadFlag']);
                        unset($videoFileElem['ThumbUUID']);
                        unset($videoFileElem['ThumbDownloadFlag']);
                        $content = $videoFileElem  && is_array($videoFileElem) ? json_encode($videoFileElem, JSON_UNESCAPED_UNICODE ) : '';
                        $im_type = 'sendVideo';
                    //语音消息元素     
                    } else if($value['MsgType'] == 'TIMSoundElem') {
                        $soundFileElem = $value['MsgContent'];
                        unset($soundFileElem['UUID']);
                        unset($soundFileElem['Download_Flag']);
                        $content = $soundFileElem  && is_array($soundFileElem) ? json_encode($soundFileElem, JSON_UNESCAPED_UNICODE ) : '';
                        $im_type = 'sendSound';
                    //文件消息消息元素     
                    } else if($value['MsgType'] == 'TIMFileElem') {
                        $fileElem = $value['MsgContent'];
                        unset($fileElem['UUID']);
                        unset($fileElem['Download_Flag']);
                        $content = $fileElem  && is_array($fileElem) ? json_encode($fileElem, JSON_UNESCAPED_UNICODE ) : '';
                        $im_type = 'sendFile';
                    }
                }
            }
        }

        // 先只记录有值的 cmd指令的回调不管了
        if($content) {
            $body = [];
            $body[':user_my_id']     = $userMyId;
            $body[':user_to_id']     = $userToId;
            $body[':content']        = ! empty ( $content ) ? $content  : '';
            $body[':add_time']       = time();
            $body[':add_ip']         = $ip;  //IP
            $body[':source_type']    = 1;  //1是手机IM聊天之后的待定
            $body[':tencent_im']     = $imcontent;  //腾讯IM三方回调来的数据包
            $body[':im_type']        = ! empty ( $im_type ) ? $im_type  : 'sendText';  //腾讯IM三方回调来的数据包
            foreach ($body as $key => $value) {
                $keys_prepare[]  = trim($key);
                $keys[]          = str_replace(':','',trim($key));
            }
            $this->pdo->query("SET NAMES utf8");
            $sql    =  "INSERT INTO p46_im_message  (".implode(', ', $keys).") VALUES ( ".implode(', ', $keys_prepare).")";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($body);
        } 

        return true;
    }
    
    //  关闭链接
    public function __destruct() {
        $this->pdo = null;
    }
   
}