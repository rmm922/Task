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
use TencentCloud\Asr\V20190614\Models\CreateRecTaskRequest;


class TencentYinpingWorker extends BaseWorker implements Worker
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
                    $msg = 'dwk完成处理-tencentYinping--音频翻译获取taskId-成功';
                } else {
                    $msg = 'dwk完成处理-tencentYinping--音频翻译获取taskId-失败' . $res;
                }
            } 
            // 自动录屏的数据
            else if ($data_info['type'] == 'recording_video') 
            {
                
                $res = $this->actionRecordingVideo($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-recording_video--音频翻译获取taskId-成功';
                } else {
                    $msg = 'dwk完成处理-recording_video--音频翻译获取taskId-失败' . $res;
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
        //创建音频目录
        $out_name = $ID. '.wav';
        $mkdir_url = $this->config['url'] . '/data/audioTranslation/' . date('Y_m_d');
        //音频完整路径
        $out = $mkdir_url . '/' . $out_name;//音频完整路径 本地服务器绝对路径
        $task_out_url = 'data/audioTranslation/' . date('Y_m_d') . '/'. $out_name;//音频完整路径  域名路径

        if( $out && file_exists($out) ) {
            // http://test.eovobochina.com/data/audioTranslation/2020_08_03/23.wav
            $url = $this->config['eovobochina_url'] . $task_out_url;
            //走 语音识别 API接口
            $cred        = new Credential($this->config['secretId'], $this->config['secretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com"); //API 支持就近地域接入，本产品就近地域接入域名为 asr.tencentcloudapi.com

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $clientProfile->setSignMethod("TC3-HMAC-SHA256");  //application/json（推荐），必须使用签名方法 v3（TC3-HMAC-SHA256）。
            $client = new AsrClient($cred, "ap-shanghai", $clientProfile);

            $req = new CreateRecTaskRequest();

            $params = '{
                "EngineModelType":"16k_0",
                "ChannelNum":1,
                "ResTextFormat":0,
                "SourceType":0,
                "Url":"'.$url .'"
            }';
            
            $req->fromJsonString($params);

            $resp = $client->CreateRecTask($req);

            $resp_data = $resp->toJsonString();

            $msgInfo =  self::msgInfo($resp_data);
            if( ! empty( $msgInfo['Data']['TaskId']) ) {
                //更新数据库 taskId
                $TaskId = $msgInfo['Data']['TaskId'];
                self::updateAudioTranslation($ID, $TaskId);
            }

        }

        return true;
    }

    /**
     * 更新表数据 更新翻译文字
     */
    public function updateAudioTranslation( $Id = '' , $TaskId = '') {
        if(!$Id) { return false;}
        $where = '';
        $TaskId = intval($TaskId);
        if($TaskId) {
            $where .= 'taskId = '.  $TaskId ;
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
        $ID     = $data['insertID'];
        $info   =  self::onlyData($ID, 1);
        $url = ! empty ( $info['video_url'] ) ? $info['video_url'] : '';
        if( $url ) {
            // http://test.eovobochina.com/data/audioTranslation/2020_08_03/23.wav
            //走 语音识别 API接口
            $cred        = new Credential($this->config['secretId'], $this->config['secretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com"); //API 支持就近地域接入，本产品就近地域接入域名为 asr.tencentcloudapi.com

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $clientProfile->setSignMethod("TC3-HMAC-SHA256");  //application/json（推荐），必须使用签名方法 v3（TC3-HMAC-SHA256）。
            $client = new AsrClient($cred, "ap-shanghai", $clientProfile);

            $req = new CreateRecTaskRequest();

            $params = '{
                "EngineModelType":"16k_0",
                "ChannelNum":1,
                "ResTextFormat":0,
                "SourceType":0,
                "Url":"'.$url .'"
            }';
            
            $req->fromJsonString($params);

            $resp = $client->CreateRecTask($req);

            $resp_data = $resp->toJsonString();

            $msgInfo =  self::msgInfo($resp_data);
            if( ! empty( $msgInfo['Data']['TaskId']) ) {
                //更新数据库 taskId
                $TaskId = $msgInfo['Data']['TaskId'];
                self::update_p46_negotiation_info_video($ID, $TaskId);
            }
        }

        return true;
    }

     /**
     * 更新表数据 更新翻译文字
     */
    public function update_p46_negotiation_info_video( $Id = '' , $TaskId = '') {
        if(!$Id) { return false;}
        $where = '';
        $TaskId = intval($TaskId);
        if($TaskId) {
            $where .= 'taskId = '.  $TaskId ;
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