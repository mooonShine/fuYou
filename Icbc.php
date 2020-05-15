<?php
/**
 * Author: zhanghuang
 * Since : 2020/5/15 : 16:27
 * Description:浙江工行支付demo
 */

class icbcPay
{
    protected $option;
    protected $data = [
        'apigw_appid' => ICBC_APPID, //工行渠道appid
        'apigw_format' => 'json',
        'apigw_version' => '1.0',
        'apigw_signtype' => 'RSA',
    ];
    protected $api_name = [
        '1' => 'com.icbc.upay.alipay.unifiedorder', //支付宝生成预支付交易单
        '2' => 'com.icbc.upay.wxpay.unifiedorder', //微信生成预支付交易单
        '3' => 'com.icbc.upay.copay.refund', //退款接口
        '4' => 'com.icbc.upay.copay.orderquery', //订单查询接口
        '6' => 'com.icbc.upay.merch.vacct.withdraw',  //子账户提现
    ];

    public function __construct()
    {
        include APPPATH . "libraries/HttpClient.class.php";
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
            return false;
        }
        $this->option['apigw_apiname'] = $this->api_name[$this->option['api_type']];
        unset($this->option['api_type']);
        return $this;
    }

    /**
     * @return mixed
     * @throws Exception
     * @internal param $请求api
     * @author zay
     */
    public function post_api()
    {
        $this->data['apigw_timestamp'] = date('Y-m-d H:i:s');
        //转化子商户唯一标识符
        $this->option['subInstId'] = 'djkj_' . $this->option['subInstId'];
        $merge_data = array_merge($this->data, $this->option);
        $private_key = file_get_contents(APPPATH . 'icbcpay/rsa/rsa_icbc_2048_private_key.pem');
        $pi_key = openssl_pkey_get_private($private_key);//这个函数可用来判断私钥是否是可用的，可用返回资源id Resource id

        $stringToBeSigned = $this->getSignContent($merge_data);
        openssl_sign($stringToBeSigned, $encrypted, $pi_key, OPENSSL_ALGO_SHA1);//生成签名
        $api_sign = base64_encode($encrypted);//
        $icbc_domain = ICBC_API_URL;
        $url = $icbc_domain . '/' . $this->option['apigw_apiname'];
        $merge_data['apigw_sign'] = $api_sign;
        $result = $this->execute($url, $merge_data);
        //返回结果写入日志
        $file_name = explode('.', $this->option['apigw_apiname']);
        $file_name = end($file_name);
        $file = $this->is_exist(DIRECTORY_SEPARATOR . 'icbc' . DIRECTORY_SEPARATOR . $file_name) . '/' . date('Y-m-d') . '.log';
        $this->print_log($file, date('Y-m-d H:i:s')."：\r\n".$result . "\r\n");

        if ($result === false) {
            return false;
        }
        $res = json_decode($result, true);
        if (!is_array($res)) {
            $res = $result;
        }
        if (!isset($res['apigw_rspdata']['ICBC_API_RETCODE'])) {
            return false;
        }
        if ($res['apigw_rspdata']['ICBC_API_RETCODE'] != 0) {
            return false;
        } elseif (isset($res['apigw_rspdata']['hostRspCode']) && $res['apigw_rspdata']['hostRspCode'] != '00000') {
            return false;
        }
        return $res['apigw_rspdata']['response'];
    }

    /**
     * @name curl -Post请求
     * @param $url
     * @param $para
     * @param int $time
     * @return mixed
     * @throws Exception
     */
    protected function httpPost($url, $para, $time = 5)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $time);//超时时间
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);// 显示输出结果
        curl_setopt($curl, CURLOPT_POST, 1); // post传输数据
        curl_setopt($curl, CURLOPT_POSTFIELDS, $para);// post传输数据
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE); // 从证书中检查SSL加密算法是否存在
        //curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)'); // 模拟用户使用的浏览器
        //curl_setopt($curl, CURLOPT_USERAGENT, 'api-sdk-java'); // 模拟用户使用的浏览器
        $headers = [
            'Content-type: application/x-www-form-urlencoded;charset=UTF-8',
            'Accept:text/xml,application/xml,application/xhtml+xml,text/html,text/plain,image/png,image/jpeg,image/gif,*/*',
            'User-Agent:api-sdk-java',
        ];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, 0); // 过滤HTTP头

        curl_setopt($curl, CURLINFO_HEADER_OUT, 0);
        $responseText = curl_exec($curl);
        curl_close($curl);
        return $responseText;
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
            $pieces[] = "$param=$value";
        }
        return implode('&', $pieces);
    }

    /**
     * @name 加密
     * @author zay
     * @return bool|string
     */
    protected function encrypt($data)
    {
        $split = str_split($data, 117);  // 1024 bit && OPENSSL_PKCS1_PADDING  不大于117即可
        $private_key = file_get_contents(APPPATH . 'icbcpay/rsa/rsa_icbc_private_key.pem');
        $pi_key = openssl_pkey_get_private($private_key);
        $crypt_to = '';
        foreach ($split as $chunk) {
            $isOkay = openssl_private_encrypt($chunk, $encryptData, $pi_key);
            if (!$isOkay) {
                return false;
            }
            $crypt_to .= base64_encode($encryptData);
        }
        return $crypt_to;
    }

    /**
     * @name 解密
     * @author zay
     * @return bool|string
     */
    protected function decrypt($data)
    {
        $public_key = file_get_contents(APPPATH . 'icbcpay/rsa/rsa_icbc_public_key.pem');
        $pu_key = openssl_pkey_get_public($public_key);
        $split = str_split($data, 172);  // 1024 bit  固定172
        $decrypt_to = '';
        foreach ($split as $chunk) {
            $isOkay = openssl_public_decrypt(base64_decode($chunk), $decryptData, $pu_key);  // base64在这里使用，因为172字节是一组，是encode来的
            if (!$isOkay) {
                return false;
            }
            $decrypt_to .= $decryptData;
        }
        return $decrypt_to;
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

    /**
     * @param $url
     * @param $para
     * @return mixed
     * author: ZhangHuang
     * Since : 2019/5/30 : 14:53
     * Description:请求api
     */
    public function execute($url, $para){
        $ch = curl_init();
        $curlVersion = curl_version();

        curl_setopt($ch, CURLOPT_URL, $url); // 要访问的地址
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // 从证书中检查SSL加密算法是否存在
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); // 模拟用户使用的浏览器
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE); // 使用自动跳转
        curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE); // 自动设置Referer
        curl_setopt($ch, CURLOPT_POST, TRUE); // 发送一个常规的Post请求
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 设置超时限制防止死循环
        curl_setopt($ch, CURLOPT_HEADER, FALSE); // 显示返回的Header区域内容
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE); // 获取的信息以文件流的形式返回
        curl_setopt($ch, CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_0);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Expect:"));
        $reqdata = http_build_query($para);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $reqdata);
        $info = curl_getinfo($ch);
        //运行curl
        $data = curl_exec($ch);

        $file_name = explode('.', $this->option['apigw_apiname']);
        $file_name = end($file_name);
        $file = $this->is_exist(DIRECTORY_SEPARATOR . 'icbc' . DIRECTORY_SEPARATOR . $file_name) . '/' . date('Y-m-d') . '.log';
        $this->print_log($file, date('Y-m-d H:i:s')."：curl返回结果:\r\n".$data . "\r\n");

        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        } else {
            $this->print_log($file, date('Y-m-d H:i:s')."：\r\n".'curl请求工行失败' . "\r\n");
            return false;
        }

    }
}
