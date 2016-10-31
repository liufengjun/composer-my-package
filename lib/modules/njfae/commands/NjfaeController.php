<?php

namespace Wcg\modules\njfae\commands;

use App\Model\CreditOrder;
use App\Model\Loan;
use App\Model\Order;
use yii\console\Controller;

class NjfaeController extends Controller
{
    private $TYPE_LOAN = 1;    //产品信息
    private $TYPE_PAYMENT = 2; //销售信息
    private $TYPE_CREDIT = 3;  //转让信息

    /**
     * 考虑到历史数据较多，故分时间段执行，避免时间执行过长
     *
     * @param string $startDate 开始日期 例：2010-05-01
     * @param string $endDate   结束日期 例：2010-10-01（不包含当天）
     * @param string $dateDir   日期文件夹
     *
     */
    public function actionInit($startDate, $endDate, $dateDir)
    {
        $data = $this->getLoansByDate($startDate, $endDate);
        $loanData = $this->createFileDataByType($this->TYPE_LOAN, $data);
        $loanFiles = $this->exportCsv($loanData, $this->TYPE_LOAN);
        $this->upload($loanFiles, \Yii::$app->params['njfae_upload_dir'].$dateDir);

        $data = $this->getPaymentsByDate($startDate, $endDate);
        $paymentData = $this->createFileDataByType($this->TYPE_PAYMENT, $data);
        $paymentFiles = $this->exportCsv($paymentData, $this->TYPE_PAYMENT);
        $this->upload($paymentFiles, \Yii::$app->params['njfae_upload_dir'].$dateDir);

        $data = $this->getCreditsByDate($startDate, $endDate);
        $creditData = $this->createFileDataByType($this->TYPE_CREDIT, $data);
        $creditFiles = $this->exportCsv($creditData, $this->TYPE_CREDIT);
        $this->upload($creditFiles, \Yii::$app->params['njfae_upload_dir'].$dateDir);
    }

    /**
     * 定时任务-每日给南金交上传对应的产品销售文件
     */
    public function actionInitLoans()
    {
        $data = $this->getLoansByDate();
        $loanData = $this->createFileDataByType($this->TYPE_LOAN, $data);
        $files = $this->exportCsv($loanData, $this->TYPE_LOAN);
        $this->upload($files);
    }

    /**
     * 定时任务-每日给南金交上传对应的客户销售明细文件
     */
    public function actionInitPayments()
    {
        $data1 = $this->getPaymentsByDate();
        $paymentData = $this->createFileDataByType($this->TYPE_PAYMENT, $data1);
        $files = $this->exportCsv($paymentData, $this->TYPE_PAYMENT);
        $this->upload($files);
    }

    /**
     * 定时任务-每日给南金交上传对应的产品转让文件
     */
    public function actionInitCredits()
    {
        $data2 = $this->getCreditsByDate();
        $creditData = $this->createFileDataByType($this->TYPE_CREDIT, $data2);
        $files = $this->exportCsv($creditData, $this->TYPE_CREDIT);
        $this->upload($files);
    }

    /**
     * 根据日期获得以标的的原始项目编号为键的产品要素信息的三维数组
     *
     * 若两者皆为null,则为昨天
     * @param  string $startDate 开始日期
     * @param  string $endDate   截止日期(不包含该天)
     *
     * @return array
     * @throws \Exception
     */
    private function getLoansByDate($startDate = null, $endDate = null)
    {
        $loans = Loan::find();
        if (null === $startDate && null === $endDate) {
            $todayAt  = strtotime(date('Y-m-d'));
            $loans->where(['>=', 'jixi_time', strtotime('-1 day', $todayAt)]);
            $loans->andWhere(['<', 'jixi_time', $todayAt]);
        } else {
            if ($startDate >= $endDate) {
                throw new \Exception('参数错误');
            }
            $loans->where(['>=', 'jixi_time', strtotime($startDate)]);
            $loans->andWhere(['<', 'jixi_time', strtotime($endDate)]);
        }

        $loansByDate = $loans->andWhere(['status' => 5, 'is_jixi' => true])->orderBy(['issuerSn' => SORT_DESC])->all();
        $finalLoans = [];
        foreach ($loansByDate as $loan) {
            $finalLoans[$loan->issuerSn][] = $loan;
        }

        return $finalLoans;
    }

    /**
     * 根据日期获得以标的的原始项目编号为键的客户销售信息的三维数组
     *
     * @param  string $startDate 开始日期，若为null,则为昨天
     * @param  string $endDate   截止日期
     *
     * @return array
     * @throws \Exception
     */
    private function getPaymentsByDate($startDate = null, $endDate = null)
    {
        $o = Order::tableName();
        $l = Loan::tableName();

        $orders = Order::find()->innerJoinWith('loan')->innerJoinWith('user')->where(["$o.status" => 1]);
        if (null === $startDate && null === $endDate) {
            $todayAt = strtotime(date('Y-m-d'));
            $orders->andWhere(['>=', 'order_time', strtotime('-1 day', $todayAt)]);
            $orders->andWhere(['<', 'order_time', $todayAt]);
        } else {
            if ($startDate >= $endDate) {
                throw new \Exception('参数错误');
            }
            $orders->andWhere(['>=', 'order_time', strtotime($startDate)]);
            $orders->andWhere(['<', 'order_time', strtotime($endDate)]);
        }
        $ordersByDate = $orders->orderBy(["$l.issuerSn" => SORT_DESC])->all();

        $finalOrders = [];
        foreach ($ordersByDate as $order) {
            $finalOrders[$order->loan->issuerSn][] = $order;
        }

        return $finalOrders;
    }

    /**
     * 根据日期获得转让信息的三维数组
     *
     * @param  string $startDate 开始日期
     * @param  string $endDate   截止日期
     *
     * @return array
     * @throws \Exception
     */
    private function getCreditsByDate($startDate = null, $endDate = null)
    {
        $creditOrders = CreditOrder::find()->innerJoinWith('asset')->where(['status' => 1]);
        if (null === $startDate && null === $endDate) {
            $creditOrders->andWhere(['>=', 'settleTime', date('Y-m-d', strtotime('-1 day'))]);
            $creditOrders->andWhere(['<', 'settleTime', date('Y-m-d')]);
        } else {
            if ($startDate >= $endDate) {
                throw new \Exception('参数错误');
            }
            $creditOrders->andWhere(['>=', 'settleTime', $startDate]);
            $creditOrders->andWhere(['<', 'settleTime', $endDate]);
        }
        $creditOrdersByDate = $creditOrders->all();
        $creditOrderNew = [];
        $agent = \Yii::$app->params['channelSn_in_njfae'];
        foreach ($creditOrdersByDate as $creditOrder) {
            //将一条记录拆分两条，分为转让与转出
            $sn = $this->generateCreditSn($creditOrder->id);
            $seller = $creditOrder->asset->user;
            $buyer = $creditOrder->user;
            $loan = $creditOrder->asset->loan;
            $creditOrderNew[$sn][] = [
                $sn,
                0,
                $seller->real_name,
                $seller->real_name,
                0,
                0,
                '',
                $seller->idcard,
                date('Ymd', strtotime($creditOrder->createTime)),
                $loan->sn,
                bcdiv($creditOrder->principal, 100, 2),
                bcdiv($creditOrder->sellerAmount, 100, 2),
                bcdiv($creditOrder->amount, $creditOrder->principal, 2),
                bcdiv($creditOrder->amount, 100, 2),
                bcdiv($creditOrder->fee, 100, 2),
                $agent,
                '1A',
                $loan->issuerSn,
                $seller->mobile,
                $seller->mobile,
                '',
            ];
            $creditOrderNew[$sn][] = [
                $sn,
                1,
                $buyer->real_name,
                $buyer->real_name,
                0,
                0,
                '',
                $buyer->idcard,
                date('Ymd', strtotime($creditOrder->createTime)),
                $loan->sn,
                bcdiv($creditOrder->principal, 100, 2),
                bcdiv($creditOrder->buyerAmount, 100, 2),
                bcdiv($creditOrder->amount, $creditOrder->principal, 2),
                bcdiv($creditOrder->amount, 100, 2),
                '0.00',
                $agent,
                '1A',
                $loan->issuerSn,
                $buyer->mobile,
                $buyer->mobile,
                '',
            ];
        }

        return $creditOrderNew;
    }

    /**
     * 生成16位以wdjf开头，$str为尾，中间由0补齐的交易sn
     *
     * @param  string $str 转让订单id
     *
     * @return string
     */
    private function generateCreditSn($str)
    {
        return 'wdjf'.str_pad($str, 12, '0', STR_PAD_LEFT);
    }

    /**
     * 根据文件的类别获得对应的表头信息
     *
     * @param  int   $type 文件类别（1产品、2销售、3转让）
     *
     * @return array
     */
    private function getTitleByType($type)
    {

        $title = [
            $this->TYPE_LOAN => [
                '产品代码|交易板块|债券简称|债券全称|总发行量|总销售量|发行价|持仓户数上限|票面利率|年付息次数|付息安排|发行模式|发行日期|上市日期|到期日期|起息日期|兑息日期|交易状态|委托数量下限|委托数量上限|交易基数',
            ],
            $this->TYPE_PAYMENT => [
                '交易编号|交易时间戳|产品代码|资产数量|客户姓名|证件编号|手机|性别|风险承受级别|利率',
            ],
            $this->TYPE_CREDIT => [
                '交易流水号|转让方向|客户姓名|客户全称|机构标志|证件类别|性别|证件编号|转让日期|产品代码|股权数量|剩余份额|转让价格|成交金额|手续费|营业部|客户群组编号|基础资产编号|平台账户|手机|风险承受能力',
            ],
        ];

        return $title[$type];
    }

    /**
     * 根据文件的类别及文件内容数组生成可用来生成最终文件的数组
     *
     * @param  int   $type 文件类别（1产品、2销售、3转让）
     * @param  array $data 文件内容数组
     *
     * @return array
     */
    private function createFileDataByType($type, $data)
    {
        $fileData = [];
        if ($type === $this->TYPE_LOAN) {
            foreach ($data as $sn => $row) {
                foreach ($row as $key => $loan) {
                    $fileData[$sn][$key][] = $loan->sn;
                    $fileData[$sn][$key][] = '';
                    $fileData[$sn][$key][] = $loan->title;
                    $fileData[$sn][$key][] = $loan->title;
                    $fileData[$sn][$key][] = $loan->money;
                    $fileData[$sn][$key][] = $loan->funded_money;
                    $fileData[$sn][$key][] = '1.00';
                    $fileData[$sn][$key][] = $loan->order_limit > 0 ? $loan->order_limit : 200;
                    $fileData[$sn][$key][] = $loan->yield_rate;
                    $fileData[$sn][$key][] = '';
                    $fileData[$sn][$key][] = \Yii::$app->params['refund_method'][$loan->refund_method];
                    $fileData[$sn][$key][] = '';
                    $fileData[$sn][$key][] = date('Ymd', $loan->start_date);
                    $fileData[$sn][$key][] = date('Ymd', $loan->end_date);
                    $fileData[$sn][$key][] = date('Ymd', $loan->finish_date);
                    $fileData[$sn][$key][] = date('Ymd', $loan->jixi_time);
                    $repaymentDates = $loan->getRepaymentDates();
                    $lastPaymentDate = count($repaymentDates) === 1 ? $repaymentDates[0] : end($repaymentDates);
                    $fileData[$sn][$key][] = date('Ymd', strtotime($lastPaymentDate));
                    $fileData[$sn][$key][] = '';
                    $fileData[$sn][$key][] = $loan->start_money;
                    $fileData[$sn][$key][] = $loan->money;
                    $fileData[$sn][$key][] = $loan->dizeng_money;
                }
                array_unshift($fileData[$sn], $this->getTitleByType($type));
            }
        } elseif ($type === $this->TYPE_PAYMENT) {
            foreach ($data as $psn => $row) {
                foreach ($row as $pkey => $payment) {
                    $fileData[$psn][$pkey][] = $payment->sn;
                    $fileData[$psn][$pkey][] = date('YmdHis', $payment->created_at);
                    $fileData[$psn][$pkey][] = $payment->loan->sn;
                    $fileData[$psn][$pkey][] = $payment->order_money;
                    $fileData[$psn][$pkey][] = $payment->username;
                    $fileData[$psn][$pkey][] = $payment->user->idcard;
                    $fileData[$psn][$pkey][] = $payment->mobile;
                    $fileData[$psn][$pkey][] = '';
                    $fileData[$psn][$pkey][] = '';
                    $fileData[$psn][$pkey][] = $payment->yield_rate;
                }
                array_unshift($fileData[$psn], $this->getTitleByType($type));
            }
        } elseif ($type === $this->TYPE_CREDIT) {
            $fileData = $data;
            array_unshift($fileData, [$this->getTitleByType($type)]);
        }

        return $fileData;
    }

    /**
     * 生成对应类型的csv文件
     *
     * @param  string  $data  文件主体内容
     * @param  string  $type  文件类别（1产品、2销售、3转让）
     *
     * @return array   $fileNames
     */
    private function exportCsv($data, $type)
    {
        $fileNames = [];
        if ($type === $this->TYPE_LOAN || $type === $this->TYPE_PAYMENT) {
            $fileNames = array();
            foreach ($data as $sn => $row) {
                $fileContent = '';
                foreach ($row as $content) {
                    $fileContent .= (implode('|', $content) . "\n");
                }
                $fileName = $this->createFileName($sn, $type);
                file_put_contents($fileName, $fileContent, FILE_APPEND);
                array_push($fileNames, $fileName);
            }
        } elseif ($type === $this->TYPE_CREDIT) {
            $fileContent = '';
            foreach ($data as $sn => $row) {
                foreach ($row as $content) {
                    $fileContent .= (implode('|', $content) . "\n");
                }
            }
            $fileName = $this->createFileName(\Yii::$app->params['channelSn_in_njfae'], $type);
            file_put_contents($fileName, $fileContent, FILE_APPEND);
            array_push($fileNames, $fileName);
        }

        return $fileNames;
    }

    /**
     * 生成带有绝对路径的中文名称
     *
     * @param  string  $sn   文件名编号
     * @param  string  $type 哪类文件（1产品、2销售、3转让）
     *
     * @return string  文件名
     */
    private function createFileName($sn, $type)
    {
        $savepath = \Yii::$app->params['njfae_save_filePath'];
        $absolutePath = __DIR__.(substr($savepath, 0, 1) === '/' ? $savepath : '/'.$savepath);
        if (!file_exists($absolutePath) || !is_dir($absolutePath)) {
            @mkdir($absolutePath);
        }

        $title = '';
        if ($type === $this->TYPE_LOAN) {
            $title = '产品销售表';
        } elseif ($type === $this->TYPE_PAYMENT) {
            $title = '客户销售明细表';
        } elseif ($type === $this->TYPE_CREDIT) {
            $title = 'credit';
        }
        $fileName = $absolutePath.date('YmdHis').'_wdjf_'.$sn.'_'.$title.'.csv';

        return $fileName;
    }

    /**
     * 文件上传，可指定上传路径
     *
     * @param  array/string $files         文件的绝对路径
     * @param  string       $dateUploadDir 文件的上传文件路径
     *
     * @return bool
     * @throws \Exception
     */
    private function upload($files, $dateUploadDir = null)
    {
        if (null === $dateUploadDir) {
            $dateUploadDir = \Yii::$app->params['njfae_upload_dir'].date('Ymd');
        }

        if (is_string($files)) {
            $files[] = $files;
        }
        $gftp = \Yii::$app->njfaeFtp;
        foreach ($files as $file) {
            try {
                $backDir = $gftp->pwd();
                if (!$gftp->fileExists($dateUploadDir)) {
                    $gftp->mkdir($dateUploadDir);
                }
                $remoteFileName = substr($file, intval(strrpos($file, '/')) + 1);
                $gftp->chdir($dateUploadDir);
                $gftp->put($file, $remoteFileName);
                $gftp->chdir($backDir);
                return true;
            } catch(\Exception $ex) {
                throw new \Exception($ex->getMessage());
            }
        }
    }
}
