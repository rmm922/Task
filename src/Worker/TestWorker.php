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


class TestWorker extends BaseWorker implements Worker
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
            file_put_contents('data_info.txt', print_r($this->config, true));
            $this->logger->info('job处理成功日志信息输出', [
                'id' => $this->job->getId(),
            ]);
            echo 11;
            return [
                'code' => Code::$success
            ];

        } catch (\Exception $e) {
            $this->logger->info('运行错误捕捉', ['id' => $this->job->getId(), 'data' => $e->getMessage()]);
            return ['code' => Code::$success];
        }
    }

    //  关闭链接
    public function __destruct() {
        $this->pdo = null;
    }
   
}