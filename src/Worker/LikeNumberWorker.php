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


class LikeNumberWorker extends BaseWorker implements Worker
{
    private $config;
    public  $pdo;
    private $params;
    public  $redis;

	public function __construct(Container $container, Job $job)
    {
        parent::__construct($container, $job);

        //加载配置文件
        $params            = require('params.php');

        $this->config      = $params['connection'];

        //缓存名称
        $this->params      = $params['redis'];
        //时间
        date_default_timezone_set('PRC');
        //mysql配置
        $this->pdo   = new \PDO($this->config['mysql_host'], $this->config['mysql_user'], $this->config['mysql_password']);
        //redis配置
        $this->redis = new \Redis();
        $this->redis->connect($this->config['redis_host'], $this->config['redis_port']);
        $this->redis->auth($this->config['redis_auth']);
    }

     /**
     * 处理job
     */
    public function handleJob():array
    {   
        try {
            //反序列化对象
            $data_info = json_decode($this->job->getData(),true);
            
            if ($data_info['type'] == 'likeNumber') {
                //初始化原住民
                $res = $this->actionLikeNumber($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-likeNumber-点赞处理-成功';
                } else {
                    $msg = 'dwk完成处理-likeNumber--点赞处理-失败' . $res;
                }
            } else if ( $data_info['type'] == 'likeNumberMeet' ) {  
                //初始化原住民
                $res = $this->actionLikeNumberMeet($data_info);
                if ($res == 200) {
                    $msg = 'dwk完成处理-likeNumber-会议处理-成功';
                } else {
                    $msg = 'dwk完成处理-likeNumber-会议处理-失败' . $res;
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
     * 点赞数处理 会议
     * user_id 游客用户ID | 观众ID
     * host_id 主播用户ID | 展商ID
     * tid     场次ID（主键）
     * rid     直播间ID
     */
    public function actionLikeNumberMeet($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $tid        = ! empty( $data['tid'] ) ? $data['tid'] : '';//场次ID
        $pid        = ! empty ($data['pid']) ? $data['pid'] : '';//直播间ID
        //判断参数完整性
        if(!$tid || !$pid ){return false;}
        //删除直播音频记录
        $ver_get_host_translation_info_zh = $this->params['ver_get_host_translation_meet_info'].'zh_'.$pid;
        $ver_get_host_translation_info_en = $this->params['ver_get_host_translation_meet_info'].'en_'.$pid;

        //翻译结果保留数据
        $return_zh = $return_en = [];
        if ($this->redis->exists($ver_get_host_translation_info_zh)) {
            $return_zh = $this->redis->lrange($ver_get_host_translation_info_zh, 0,-1); 
        }
        if ($this->redis->exists($ver_get_host_translation_info_en)) {
            $return_en = $this->redis->lrange($ver_get_host_translation_info_en, 0,-1); 
        }
        self::p46_exhibition_room_translation_info($tid, $return_zh, $return_en);

        self::redis_key_del($ver_get_host_translation_info_zh);
        self::redis_key_del($ver_get_host_translation_info_en);

        return true;
    }

    /**
     * 点赞数处理
     * user_id 游客用户ID | 观众ID
     * host_id 主播用户ID | 展商ID
     * tid     场次ID（主键）
     * rid     直播间ID
     */
    public function actionLikeNumber($data_info = '') {
        $data = $data_info['data'];
        //数据库数据ID
        $tid        = ! empty( $data['tid'] ) ? $data['tid'] : '';//场次ID
        $rid        = ! empty ($data['rid']) ? $data['rid'] : '';//直播间ID
        $host_id    = ! empty( $data['uid'] ) ? $data['uid'] : ''; //主播用户ID | 展商ID
       
        //判断参数完整性
        if(!$tid || !$host_id || !$rid ){return false;}

        //主播点赞数记录
        $count = $ver_get_tourists_info_count =0;
        $ver_get_host_info     = $this->params['ver_get_host_info'].$rid.'_'.$host_id; //直播间ID + 主播用户ID
        if ($this->redis->exists($ver_get_host_info)) {
            $count = self::myGet($ver_get_host_info, 1); 
        }
        self::p46_exhibition_room_times_info($tid, $host_id, $count);

        //本场直播下的用户
        $ver_get_host_info_audience = $ver_get_host_info . '_audience';
        if ($this->redis->exists($ver_get_host_info_audience)) {
            //处理游客用户点赞数
            $info = $this->redis->zrevrange($ver_get_host_info_audience, 0, -1);
            if($info) {
                for ($i=0; $i < count($info); $i++) { 
                    //获取 游客 key  ver_wz_tourists_info_1_2_1 第一个参数 直播间ID 第二个 直播人ID 第三个参数 游客ID
                    $ver_get_tourists_info = $info[$i];
                    //游客信息
                    $tourists_info   =  str_replace('ver_wz_tourists_info_', '', $ver_get_tourists_info);                         
                    $tourists_data   =  explode('_', $tourists_info);
                    $tourists_id  = $tourists_data[2];//游客ID
                    if ($this->redis->exists($ver_get_tourists_info)) {
                        $ver_get_tourists_info_count = self::myGet($ver_get_tourists_info, 1); 
                    }
                    self::p46_exhibition_room_up_info($tid, $rid, $tourists_id, $ver_get_tourists_info_count);
                    //删除本场直播下游客信息key value是点赞数
                    self::redis_key_del($ver_get_tourists_info);
                  
                }
            }
        }

        //删除本场直播下主播人key value是点赞数
        self::redis_key_del($ver_get_host_info);
        //删除本场直播下主播人key value zadd的有序集合 里面是存的  ver_wz_tourists_info_1_2_1 第一个参数 直播间ID 第二个 直播人ID 第三个参数 游客ID
        self::redis_key_del($ver_get_host_info_audience);
        //删除本场直播下的所有用户
        $ver_get_host_info_audience_room = $ver_get_host_info_audience . '_room';
        self::redis_key_del($ver_get_host_info_audience_room);

        //删除直播音频记录
        $ver_get_host_translation_info_zh = $this->params['ver_get_host_translation_info'].'zh_'.$rid;
        $ver_get_host_translation_info_en = $this->params['ver_get_host_translation_info'].'en_'.$rid;

        //翻译结果保留数据
        $return_zh = $return_en = [];
        if ($this->redis->exists($ver_get_host_translation_info_zh)) {
            $return_zh = $this->redis->lrange($ver_get_host_translation_info_zh, 0,-1); 
        }
        if ($this->redis->exists($ver_get_host_translation_info_en)) {
            $return_en = $this->redis->lrange($ver_get_host_translation_info_en, 0,-1); 
        }
        self::p46_exhibition_room_translation_info($tid, $return_zh, $return_en);

        self::redis_key_del($ver_get_host_translation_info_zh);
        self::redis_key_del($ver_get_host_translation_info_en);


        return true;
    }
    
    /**
     * 直播翻译
     * tid        场次ID
     * return_zh    中文翻译
     * return_en    英文翻译
     */
    public function p46_exhibition_room_translation_info($tid = '', $zh = '', $en = '') {

        $this->pdo->query("SET NAMES utf8");
        $sql  = "INSERT INTO `p46_exhibition_room_translation` (tid,zh,en)  VALUES (:tid,:zh,:en)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':tid' => $tid ,':zh' => json_encode($zh, JSON_UNESCAPED_UNICODE) ,':en' => json_encode($en, JSON_UNESCAPED_UNICODE) ));
        return true;

    }

     /**
     * 直播点赞数
     * tid        场次ID
     * rid        直播间ID
     * user_id    观众ID
     * up_num     点赞次数
     */
    public function p46_exhibition_room_up_info($tid = '', $rid = '', $user_id = '', $up_num = 0) {

        $this->pdo->query("SET NAMES utf8");
        $sql  = "INSERT INTO `p46_exhibition_room_up` (tid,rid,user_id,up_num)  VALUES (:tid,:rid,:user_id,:up_num)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':tid' => $tid ,':rid' => $rid ,':user_id' => $user_id, ':up_num' => $up_num ));
        return true;

    }
    /**
     * 直播间 直播场次
     * tid        场次ID（主键）
     * uid        展商ID
     * up_num     点赞次数
     */
    public function p46_exhibition_room_times_info($tid = '',  $uid = '', $up_num = 0) {

        $where = '';
        $up_num = intval($up_num);
        if($up_num) {
            $where .= 'up_num = '.  $up_num ;
        }
        $this->pdo->query("SET NAMES utf8");
        $sql  = "UPDATE `p46_exhibition_room_times` SET $where WHERE `tid`=:tid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':tid' => $tid));
        return true;

    }
	
    public  function myGet($redis_name,$type=0){   
        if($type==1){
            return $this->redis->get($redis_name);            
        }else{
            return json_decode($this->redis->get($redis_name), true);   
        }
	}
   
    public function redis_key_del($key){

        return $this->redis->del($key);
    }
    
    //  关闭链接
    public function __destruct() {
        $this->redis->close();
        $this->pdo = null;
    }
   
}