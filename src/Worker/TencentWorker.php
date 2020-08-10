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
use Vod\VodUploadClient;
use Vod\Model\VodUploadRequest;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Vod\V20180717\VodClient;
use TencentCloud\Vod\V20180717\Models\DeleteMediaRequest;
use TencentCloud\Vod\V20180717\Models\ModifyMediaInfoRequest;

class TencentWorker extends BaseWorker implements Worker
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
            
            if ($data_info['type'] == 'tencentDianbo') {
                //初始化原住民
                $res = $this->actionTencentDianbo($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-tencentDianbo--云点播存储-成功';
                } else {
                    $msg = 'dwk完成处理-tencentDianbo--云点播存储-失败' . $res;
                }
            } else if ($data_info['type'] == 'tencentDeleteDianbo') {
                //初始化原住民
                $res = $this->actionDeleteTencentDianbo($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-tencentDianbo--云点播删除-成功';
                } else {
                    $msg = 'dwk完成处理-tencentDianbo--云点播删除-失败' . $res;
                }
            } else if ($data_info['type'] == 'tencentUpdateDianbo') {
                //初始化原住民
                $res = $this->actionUpdateTencentDianbo($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-tencentDianbo--云点播修改-成功';
                } else {
                    $msg = 'dwk完成处理-tencentDianbo--云点播修改-失败' . $res;
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
     * 修改云点播数据
     */
    public function actionUpdateTencentDianbo($data = '') {

        try {

            //数据库数据ID
            $ID = $data['insertID'];
            //查出数据
            $p46_exhibition_preview_data =  self::selectAudioTranslation($ID);
            if(!$p46_exhibition_preview_data) { return false;}
            //查询出来是二维数组
            $p46_exhibition_preview_info = $p46_exhibition_preview_data[0];

            //拼接路径
            $FileId        = $p46_exhibition_preview_info['tencent_file_id'];
            $CoverFilePath = $p46_exhibition_preview_info['img_url'];//图片路径
            $ClassId       = $p46_exhibition_preview_info['gid'];//分类 ID
            $insertId      = $ID;//插入表数据ID
            if(!$FileId) { return false;}

            $CoverData = '';
            if($CoverFilePath) {
                //绝对路径
                $CoverFilePath = $this->config['url'] . $CoverFilePath;
                $CoverData     = self::base64EncodeImage($CoverFilePath);
            }
    
            //所属分类
            if($ClassId) {
                $ClassIdInfo =  $this->config['ClassId'];
                //分类 ID
                $ClassId = $ClassIdInfo[$ClassId];
            }

            $cred = new Credential($this->config['secretId'], $this->config['secretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("vod.tencentcloudapi.com");
              
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new VodClient($cred, "ap-chongqing", $clientProfile);
        
            $req = new ModifyMediaInfoRequest();
            if($CoverData) {
                $params = array(
                    "FileId" => $FileId,
                    "ClassId" => intval( $ClassId ),
                    "CoverData" => $CoverData
                );
            } else {
                $params = array(
                    "FileId" => $FileId,
                    "ClassId" => intval( $ClassId ),
                );
            }
            $req->fromJsonString(json_encode($params));
        
        
            $resp = $client->ModifyMediaInfo($req);
        
            $info = $resp->toJsonString();
            $rsp =  json_decode($info, true);
            if(! empty ($rsp['CoverUrl'])) {
                $rspData = [
                    'FileId'     => $FileId,
                    'CoverUrl'   => $rsp['CoverUrl']
                ];
                self::updateAudioTranslation($insertId, $rspData);
    
            }
        }
        catch(TencentCloudSDKException $e) {
            echo $e;
        }
        return true;
    }
    /**
     * 删除云点播数据
     */
    public function actionDeleteTencentDianbo($data = '') {

        try {
            //数据库数据ID
            $FileId = $data['FileId'];
            if(!$FileId) { return false;}

            $cred = new Credential($this->config['secretId'], $this->config['secretKey']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("vod.tencentcloudapi.com");
                
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new VodClient($cred, "ap-chongqing", $clientProfile);

            $req = new DeleteMediaRequest();

            $params = array(
                "FileId" => $FileId
            );

            $req->fromJsonString(json_encode($params));

            $resp = $client->DeleteMedia($req);

            $info = $resp->toJsonString();

        } catch (TencentCloudSDKException $e) {
            // 处理上传异常
            echo $e;
        }
        return true;
    }
     /**
     * 云点播服务执行
     */
    public function actionTencentDianbo($data = '') {
        //数据库数据ID
        $ID = $data['insertID'];
        //查出数据
        $p46_exhibition_preview_data =  self::selectAudioTranslation($ID);
        if(!$p46_exhibition_preview_data) { return false;}
        //查询出来是二维数组
        $p46_exhibition_preview_info = $p46_exhibition_preview_data[0];
        
        //拼接路径
        $MediaFilePath = $p46_exhibition_preview_info['video_url'];//视频路径
        $CoverFilePath = $p46_exhibition_preview_info['img_url'];//图片路径
        $ClassId =       $p46_exhibition_preview_info['gid'];//分类 ID
        $insertId =      $ID;//插入表数据ID

        $client = new VodUploadClient($this->config['secretId'], $this->config['secretKey']);

        $req = new VodUploadRequest();
        if($MediaFilePath) {
            //绝对路径
            $MediaFilePath = $this->config['url'] . $MediaFilePath;
            $req->MediaFilePath = $MediaFilePath;  //待上传的媒体文件路径。必须为本地路径，不支持 URL。	
           
        }

        if($CoverFilePath) {
            //绝对路径
            $CoverFilePath = $this->config['url'] . $CoverFilePath;
            //封面
            $req->CoverFilePath = $CoverFilePath;
        }

        //所属分类
        if($ClassId) {
            $ClassIdInfo  =  $this->config['ClassId'];
            //分类 ID
            $ClassId      =  $ClassIdInfo[$ClassId];
            $req->ClassId = intval($ClassId);
        }
        try {
            $rsp = $client->upload("ap-guangzhou", $req); //（"ap-guangzhou"）是指上传实例的接入地域，不是指视频上传后的存储地域。该参数固定填为"ap-guangzhou"即可，如果需要指定视频上传后的存储地域，请设置req.StorageRegion参数。

            $rspData = [
                'FileId'     => $rsp->FileId,
                'MediaUrl'   => $rsp->MediaUrl,
                'CoverUrl'   => $rsp->CoverUrl
            ];
            self::updateAudioTranslation($insertId, $rspData);

        } catch (\Exception $e) {
            // 处理上传异常
            echo $e;
        }
        
        return true;
    }

    /**
     * 获取数据
    */
    public function selectAudioTranslation( $ID = '' ) {
        if(!$ID) { return false;}
        $this->pdo->query("SET NAMES utf8");
        $sql  = 'SELECT video_url,img_url,gid,tencent_file_id  FROM p46_exhibition_preview WHERE  pid = ' . $ID . ' ';
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
     * 更新表数据 
     */
    public function updateAudioTranslation( $Id = '' , $rsp = '') {
        if(!$Id) { return false;}
        $where = '';
        if($rsp['FileId']) {
            $where .= 'tencent_file_id = '. $rsp['FileId'];
        }
        if($rsp['MediaUrl']) {
            $where .= ', tencent_video_url = '. ' "' . $rsp['MediaUrl'] . '"';
        }
        if($rsp['CoverUrl']) {
            $where .= ', tencent_img_url = '. ' "' . $rsp['CoverUrl'] . '"';
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_exhibition_preview` SET $where WHERE `pid`=:pid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':pid' => $Id));
       
    }
    /**
     * 图片处理
     */
    public function base64EncodeImage ($image_file) {
        $base64_image = '';
        $image_info = getimagesize($image_file);
        $image_data = file_get_contents($image_file);
        $base64_image = base64_encode($image_data);
        return $base64_image;
    }
   
    //  关闭链接
     public function __destruct() {
        $this->pdo = null;
    }
   
}