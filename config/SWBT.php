<?php
/**
 * Created by PhpStorm.
 * User: chenbo
 * Date: 18-5-28
 * Time: 下午5:44
 */
return [
    'tubes' => [
        'tencentDianboTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\TencentWorker::class
        ],
        'hebeiTencentDianboTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\HebeiTencentWorker::class
        ],
        'tencentYinpingTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\TencentYinpingWorker::class
        ],
        'hebeiTencentYinpingTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\HebeiTencentYinpingWorker::class
        ],
        'tencentYinpingTask1' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\TencentYinpingWorker1::class
        ],
        'hebeiTencentYinpingTask1' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\HebeiTencentYinpingWorker1::class
        ],
        'tencentSMSTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\TencentSMSWorker::class
        ],
        //河北
        'hebeiTencentSMSTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\HebeiTencentSMSWorker::class
        ],
        'likeNumber' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\LikeNumberWorker::class
        ],
        'hebeilikeNumber' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\HebeiLikeNumberWorker::class
        ],
        //私人企业队列
        'privateShowroomSMSTask' => [
            'worker_num' => 2,
            'class' => \SWBT\Worker\PrivateShowroomSMSWorker::class
        ],
    ],
    'beanstalkd' => [
        'host' => '127.0.0.1',
        'port' => '11300',
        'reserve_timeout' => 5
    ],
    'pid' => [
        'file_path' => 'storage/master.pid'
    ],
    'log' => [
        'name' => 'WZ-Tencent',
        'path' => 'storage/logs/'
    ]
];
