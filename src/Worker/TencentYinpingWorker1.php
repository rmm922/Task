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

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Asr\V20190614\AsrClient;
use TencentCloud\Asr\V20190614\Models\DescribeTaskStatusRequest;


class TencentYinpingWorker1 extends BaseWorker implements Worker
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
            // 开幕式的视频
            if ($data_info['type'] == 'tencentYinping') 
            {
                $res = $this->actionTencentYinping($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-tencentYinping--音频翻译-成功';
                } else {
                    $msg = 'dwk完成处理-tencentYinping--音频翻译-失败' . $res;
                }
            }
            // 自动录屏的数据
            else if ($data_info['type'] == 'recording_video') 
            {
                
                $res = $this->actionRecordingVideo($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-recording_video--音频翻译-成功';
                } else {
                    $msg = 'dwk完成处理-recording_video--音频翻译-失败' . $res;
                }
            } 
            else 
            {
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
     * 云点播服务执行
     */
    public function actionTencentYinping($data = '') {
        //数据库数据ID
        $ID           = $data['insertID'];
        //查出数据
        $p46_exhibition_preview_data =  self::selectAudioTranslation($ID);
        if(!$p46_exhibition_preview_data) { return false;}
        //查询出来是二维数组
        $p46_exhibition_preview_info = $p46_exhibition_preview_data[0];
        $TaskId =       $p46_exhibition_preview_info['taskId'];//轮训 ID

        if( ! empty( $TaskId) ) {
            $info =  self::TaskIdInfo($TaskId);
            if( ! empty ( $info['Data'] )  ) {
                //更新数据库
                self::updateAudioTranslation($ID, $info['Data']);
            }
            
        } 

        return true;
    }

     /**
     * 获取数据
    */
    public function selectAudioTranslation( $ID = '' ) {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        $sql  = 'SELECT taskId  FROM p46_exhibition_preview WHERE  pid = ' . $ID . ' ';
        $rs = $this->pdo->query($sql);
        $rs->setFetchMode(\PDO::FETCH_ASSOC);
        $dbData = $rs->fetchAll();
        $return_data = [];
        if($dbData) {
            $return_data  =  $dbData;
        }
        return $return_data;
    }

    /**
     * 更新表数据 更新翻译文字
     */
    public function updateAudioTranslation( $Id = '' , $rsp = '') {
        if(!$Id) { return false;}
        $audio_translation_array = '';
        if($rsp['Result']) {
            $audio_translation_array  = self::audio_translation_array($rsp['Result']);
        }    
        $where = '';
        if($rsp['Status'] == 2) {
            $where .= 'tencent_status = 2';
        } else if($rsp['Status'] == 3) {
            $where .= 'tencent_status = 1';
        }
        if($rsp['Result']) {
            $where .= ', audio_translation = '. ' "' . trim( $rsp['Result'] ) . '"';
        }
        if($audio_translation_array) {
            $where .= ', audio_translation_text = '. "'" . json_encode($audio_translation_array, JSON_UNESCAPED_UNICODE) ."'";
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_exhibition_preview` SET $where WHERE `pid`=:pid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':pid' => $Id));
       
    }
    

    /**
     * 云点播服务执行
     */
    public function actionRecordingVideo($data = '') {
        //数据库数据ID
        $ID           = $data['insertID'];
        //查出数据
        $info   =  self::onlyData($ID, 1);
        $TaskId =  ! empty ( $info['taskId'] ) ? $info['taskId'] : '';

        if( ! empty( $TaskId) ) {
            $info =  self::TaskIdInfo($TaskId);
            if( ! empty ( $info['Data'] )  ) {
                //更新数据库
                self::update_p46_negotiation_info_video($ID, $info['Data']);
            }
            
        } 

        return true;
    }

    /**
     * 更新表数据 更新翻译文字
     */
    public function update_p46_negotiation_info_video( $Id = '' , $rsp = '') {
        if(!$Id) { return false;}
        $audio_translation_array = '';
        if($rsp['Result']) {
            $audio_translation_array  = self::audio_translation_array($rsp['Result']);
        }    
        $where = '';
        if($rsp['Status'] == 2) {
            $where .= 'tencent_status = 2';
        } else if($rsp['Status'] == 3) {
            $where .= 'tencent_status = 1';
        }
        if($rsp['Result']) {
            $where .= ', audio_translation = '. ' "' . trim( $rsp['Result'] ) . '"';
        }
        if($audio_translation_array) {
            $where .= ', audio_translation_text = '. "'" . json_encode($audio_translation_array, JSON_UNESCAPED_UNICODE) ."'";
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_negotiation_info_video` SET $where WHERE `vid`=:vid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':vid' => $Id));
       
    }

    // 查询数据的信息
    public function onlyData($ID = '', $type = '') {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        if($type == 1) {
            $sql  = "SELECT * FROM p46_negotiation_info_video WHERE vid = $ID";
        }  
        $rs = $this->pdo->query($sql);
        $rs->setFetchMode(\PDO::FETCH_ASSOC);
        $dbData = $rs->fetchAll();
        $return_data = [];
        if($dbData) {
            $return_data = $dbData[0];
        }
        return $return_data;
    }

    /**
     * 压缩回来的信息处理
     */
    public function audio_translation_array($rsp = '') {
        $arr_new = [];
        if($rsp) {
            $audio_translation = trim($rsp);
            $arr = explode('[',$audio_translation);
            unset($arr[0]);
            foreach ($arr as $k=>$v)
            {
                $key = substr($v,0,strpos($v,']'));
                $key_k = explode(',',$key);
                $key_nuw = '';
                foreach ($key_k as $kk=>$vv)
                {
                    $key_v = substr($vv,0,-4);
                    if($key_nuw !== '')
                    {
                        $key_nuw .=  '-' . self::HisToS($key_v);
                    }
                    else
                    {
                        $key_nuw = self::HisToS($key_v);
                    }
                }
                $v_new = trim(substr($v,strpos($v,']')+1));
                $arr_new[$key_nuw] = $v_new;
            }
        }    
        return  $arr_new ;
    }
    /**
     * 时间处理
     */
    public function HisToS($his)
    {
        $str = explode(':', $his);

        $len = count($str);

        if ($len == 3) {
            $time = $str[0] * 3600 + $str[1] * 60 + $str[2];
        } elseif ($len == 2) {
            $time = $str[0] * 60 + $str[1];
        } elseif ($len == 1) {
            $time = $str[0];
        } else {
            $time = 0;
        }
        return $time;
    }

    /**
     * Status 任务状态码，0：任务等待，1：任务执行中，2：任务成功，3：任务失败
     */
    function TaskIdInfo($TaskId = '') {
        if( ! $TaskId ) {
            return false;
        }
        $TaskId = intval($TaskId);
        try {
            $cred = new Credential($this->config['secretId'], $this->config['secretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com");
        
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new AsrClient($cred, "ap-shanghai", $clientProfile);
        
            $req = new DescribeTaskStatusRequest();
            
            $params = '{"TaskId":' . $TaskId . '}';
            $req->fromJsonString($params);
        
            $resp = $client->DescribeTaskStatus($req);
            $data = $resp->toJsonString();
            $msgInfo =  self::msgInfo($data);
            return $msgInfo;
        
        }
        catch(TencentCloudSDKException $e) {
            // echo $e;
        }
    }

    public function msgInfo($data = '') {
        $return = [];
        if($data) {
            $return  = json_decode($data, true);
        }
        return $return;
    }
    
    //  关闭链接
     public function __destruct() {
        $this->pdo = null;
    }
   
}