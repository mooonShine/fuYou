<?php
/**
 * Author: zhanghuang
 * Since : 2020/5/15 : 13:38
 * Description: 支付调用类
 */
class FuYouApi
{
    protected $option;
    protected $data = [
        'ins_cd' => FY_INS_CD, //机构号 08A9999999
        'version' => '1.0', //支付才需要这个参数，余额相关的不需要！！！！！
    ];
    protected $api_name = [
        1 => 'https://fundwx.fuiou.com/wxPreCreate', //商户信息登记接口
        2 => 'https://fundwx.fuiou.com/commonQuery', //订单查询接口
        3 => 'https://spay-mc.fuioupay.com/commonRefund', //退款接口  线上地址
        //4 => 'https://fundwx.fuiou.com/queryWithdrawAmt', //获取余额
        4 => 'https://spay-mc.fuioupay.com/queryWithdrawAmt', //获取余额
        5 => 'https://spay-mc.fuioupay.com/queryFeeAmt', //获取手续费
        //6 => 'https://fundwx.fuiou.com/withdraw', //发起提现
        6 => 'https://spay-mc.fuioupay.com/withdraw', //发起提现 线上  https://spay-mc.fuioupay.com
        7 => 'http://www-1.fuiou.com:28090/wmp/wxMchntMng.fuiou?action=wxMchntAddScanPrePay', //扫码预授权申请开通接口
        8 => 'https://spay-mc.fuioupay.com/wxPreCreate', //线上订单创建接口 fundwx.fuiou.com
        9 => 'https://scan-rim-mc.fuioupay.com/queryChnlPayAmt', //资金划拨查询
        //10 => 'https://fundwx.fuiou.com/commonQuery', //订单查询接口
        10 => 'https://spay-mc.fuioupay.com/commonQuery', //订单查询接口 线上
        11 => 'https://fundwx.fuiou.com/closeorder', //订单关闭接口
        12 => 'https://fzfw.fuiou.com/tradeAllocate.fuiou', //订单分账接口    http://180.168.100.155:9600
        13 => 'https://fzfw.fuiou.com/allocateTradeCancel.fuiou', //分账订单撤单接口
        14 => 'https://fzfw.fuiou.com/queryAllocate.fuiou', //分账交易查询接口
        15 => 'https://fzfw.fuiou.com/queryAllocateByAccountIn.fuiou',//分账入账方资金汇总查询
        16 => 'https://fzfw.fuiou.com/queryContractDetail.fuiou', //分账合同明细查询
    ];
    protected $api_show_name = [
        1 => '下单接口',
        2 => '订单查询接口',
        3 => '退款接口',
        4 => '获取余额',
        5 => '获取手续费',
        6 => '发起提现',
        7 => '扫码预授权申请开通接口',
        8 => '线上订单创建接口',
        9 => '资金划拨查询',
        10 => '订单查询接口',
        11 => '订单关闭接口',
        12 => '订单分账接口',
        13 => '分账订单撤单接口',
        14 => '分账交易查询接口',
        15 => '分账入账方资金汇总查询',
        16 => '分账合同明细查询',
    ];
    protected $api_type;

    public function __construct()
    {
        date_default_timezone_set('Asia/Shanghai');
        $this->xml = new XmlWriter();
    }

    /**
     * @param $params
     * @return $this
     * @throws Exception
     * @author zay
     */
    public function checkData($params)
    {
        $this->option = $params;
        if (!isset($this->api_name[$this->option['api_type']])) {
            throw new Exception('接口名称不存在');
        }
        $this->option['api'] = $this->api_name[$this->option['api_type']];
        $this->api_type = $this->option['api_type'];
        unset($this->option['api_type']);
        return $this;
    }

    /**
     * @name 参数加密规则
     * @author zay
     * @return string
     */

    protected function getSignContent($params)
    {
        ksort($params);
        $pieces = [];
        foreach ($params as $param => $value) {
            //$value = iconv("UTF-8","GBK",$value);
            $pieces[] = "$param=$value";
        }
        return implode('&', $pieces);
    }

    /**
     * @return mixed
     * @throws Exception
     * @internal param $请求api
     * @author zay
     */
    public function post_api()
    {
        $merge_data = array_merge($this->data, $this->option);
        $url = $this->option['api'];
        unset($merge_data['api']);
        //剔除reserved开头的字段
        $handle_data = [];
        foreach ($merge_data as $k => $v) {
            if (strpos($k, 'reserved') === false) $handle_data[$k] = $v;
        }
        $sign = $this->getSignContent($handle_data);//参数拼接
        $merge_data['sign']=$this->sign($sign);// 签名
        //完整的xml格式
        $a = "<?xml version=\"1.0\" encoding=\"GBK\" standalone=\"yes\"?><xml>".$this->toXml($merge_data)."</xml>";
        //经过两次urlencode()之后的字符串
        $last_data = "req=".urlencode(urlencode($a));
        //提交记录日志
        $file_name = explode('/', $this->option['api']);
        $file_name = end($file_name);
        $file = $this->is_exist(DIRECTORY_SEPARATOR . 'fuyou' . DIRECTORY_SEPARATOR . $file_name) . '/'. date('Y-m-d') . '.log';
        $result = $this->SendDataByCurl($url, $last_data);
        $result = $this->FromXml($result);
        $this->print_log($file, date('Y-m-d H:i:s') . "：\r\n ". $this->api_show_name[$this->api_type] . "发送数据: \r\n" . json_encode($merge_data) . "\r\n");
        $this->print_log($file, date('Y-m-d H:i:s') . "：\r\n". $this->api_show_name[$this->api_type] . "接收数据: \r\n"  . json_encode($result) . "\r\n");
        if ($result === false || !isset($result['result_code'])) {
            throw new Exception('请求无响应');
        }
        if ($result['result_code'] != '000000' || $result['result_msg'] != 'SUCCESS') {
            throw new Exception('操作失败,错误码:' .$result['result_code'] . ',错误原因:' . $result['result_msg']);
        }
        return $result;
    }



    /**
     * @name 验证路径
     * @author zay
     * @return string
     */

    protected function is_exist($file)
    {
        $dir = APPPATH . 'logs' . $file;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * @name 保存日志
     * @param $log
     * @author zay
     */

    protected function print_log($file, $log)
    {
        file_put_contents($file, $log, FILE_APPEND);
    }

    //通过curl模拟post的请求；
    function SendDataByCurl($url,$data){
        //对空格进行转义
        $url = str_replace(' ','+',$url);
        $ch = curl_init();
        //设置选项，包括URL
        curl_setopt($ch, CURLOPT_URL, "$url");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch,CURLOPT_TIMEOUT,6); //定义超时6秒钟
        // POST数据
        curl_setopt($ch, CURLOPT_POST, 1);
        // 把post的变量加上
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  //所需传的数组用http_bulid_query()函数处理一下，就ok了
        //执行并获取url地址的内容
        $output = curl_exec($ch);
        $errorCode = curl_errno($ch);
        //释放curl句柄
        curl_close($ch);
        if(0 !== $errorCode) {
            return false;
        }
        return $output;
    }

    /**
     * @param $data
     * @param bool $eIsArray
     * @return mixed
     * author: ZhangHuang
     * Since : 2020/1/4 : 16:51
     * Description:数组转xml
     */
    function toXml($data, $eIsArray=FALSE) {
        if(!$eIsArray) {
            $this->xml->openMemory();
        }
        foreach($data as $key => $value){
            if(is_array($value)){
                $this->xml->startElement($key);
                $this->toXml($value, TRUE);
                $this->xml->endElement();
                continue;
            }
            $this->xml->writeElement($key, $value);
        }
        if(!$eIsArray) {
            $this->xml->endElement();
            return $this->xml->outputMemory(true);
        }
    }

    /**
     * @param $xml
     * @return mixed
     * author: ZhangHuang
     * Since : 2020/1/4 : 16:33
     * Description:将xml转为array
     */
    public function FromXml($xml)
    {
        if(!$xml){
            return false;
        }
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $res = json_decode(json_encode(simplexml_load_string(urldecode($xml))), true);
        return $res;
    }

    /**
     * @param $xml
     * @return mixed
     * author: ZhangHuang
     * Since : 2020/1/4 : 16:33
     * Description:将xml转为array 包含中文
     */
    public function FromXmlMoney($xml)
    {
//        if(!$xml){
//            return false;
//        }
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        //
        $res = json_decode(json_encode(simplexml_load_string(iconv("GBK","UTF-8",urldecode($xml)))), true);
        return $res;
    }

    //签名加密流程
    function sign($data){
        //读取密钥文件
        $pem = file_get_contents(APPPATH . 'fuyou/rsa/rsa_fy_private_key.pem');
//        //获取私钥
        $pkeyid = openssl_pkey_get_private($pem);
        //MD5WithRSA私钥加密
        openssl_sign($data,$sign,$pkeyid,OPENSSL_ALGO_MD5);
        //返回base64加密之后的数据
        $t=base64_encode($sign);
        //解密-1:error验证错误 1:correct验证成功 0:incorrect验证失败
        // $pubkey = openssl_pkey_get_public($pem);
        // $ok = openssl_verify($data,base64_decode($t),$pubkey,OPENSSL_ALGO_MD5);
        // var_dump($ok);
        return $t;
    }
}