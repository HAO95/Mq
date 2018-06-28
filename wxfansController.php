<?php
/**
 * 获取微信加粉相关数据接口
 * Created by PhpStorm.
 * User: arno
 * Date: 2017/8/23
 * Time: 11:35
 */

require_once PUBLIB_PATH . "wxfans" . DIRECTORY_SEPARATOR . "wxfans.php";
class wxfansController extends Pi_AbstractController {
    public function __construct() {
        parent::__construct ();
    }

    /**
     * 获取微信加粉订单
     * $where  ['user'=>[],'order'=>[],'weixin'=>[]]
     * @return array  status: 0 失败  1 成功
     */
    public function getOrderAction(array $where){
        $result = ['status' => 0, 'data' => ''];
        if (!isset($where['user']) || !isset($where['weixin']) || !isset($where['order'])) return $result;
        //获取用户已关注的公众号
        $userWxId = $this->getUserWxId($where['user']);
        //获取满足条件的订单
        $orderList = $this->getOrderInfo($where['order']);
        //获取公众号信息
        $wxIdStr = $this->getWxIdStr($orderList);
        $weixinList = $this->getWeixinInfo($where['weixin'],$wxIdStr);
        //剔除订单中用户已关注的公众号订单
        $orderWxList = $this->mergeOrderWx($weixinList,$orderList);
        $orderData = [];
        $noOrderData = [];
        if (!empty($orderWxList)) {
            foreach ($orderWxList as $key => $value) {
                if (!in_array($value['id'], $userWxId)) {
                    $orderData[$value['id']] = $value;
                }else{
                    $noOrderData[$value['id']] = $value;
                }
            }
        }

        //根据优先级选出一个订单
        $resultOrder = !empty($orderData) ? $orderData : $noOrderData;
        $data = $this->randomOrder($resultOrder);

        unset($userWxId);
        unset($orderList);
        unset($weixinList);
        unset($orderWxList);
        unset($orderData);
        unset($noOrderData);
        if (!empty($data)){
            $result = ['status' => 1, 'data' => $data];
        }
        return $result;
    }

    /**
     * 获取可用订单
     * @param array $where   ['字段名' => '值']
     */
    public function getOrderInfo(array $where){
        $hous = date('H',time());
        $appkeyStr = '';//车站标识
        if (isset($where['environment']) && !empty($where['environment'])){
            $appkeyStr = $where['environment'];
            unset($where['environment']);
        }
        $where['status'] = 1;
        $where['start_time_<='] = $hous;
        $where['end_time_>='] = $hous;
        $where['order_start_<'] = time();
        $orderModel = new Pi_FansOrderModel();
        $resultList = $orderModel->getOrderList($where,'id,wid,day_num,priority,ctime,environment', 'priority DESC,ctime ASC');
        $orderList = [];
        $orderIdArr = [];
        if (isset($resultList['allrow']) && count($resultList['allrow']) > 0){
            foreach ($resultList['allrow'] as $key=>$value){//按优先级和时间去重，优先级最高时间最新的保留
                //车站选择
                $is = 1;
                if (!empty($value['environment']) && !empty($appkeyStr)){
                    $appkeyArr = explode(',',$value['environment']);
                    if (!in_array($appkeyStr,$appkeyArr)){
                        $is = 0;
                    }
                }
                if ($is == 1) {
                    $orderList[$value['wid']]  = $value;
                    $orderIdArr[$value['wid']] = $value['id'];
                }
            }
        }

        //去除今日关注人数已满的订单
        $orderIdStr = implode(',',$orderIdArr);
        $orderLogList = $this->getOrderLogList($orderIdStr);
        if (!empty($orderList)){
            foreach ($orderList as $key=>$value){
                if (isset($orderLogList[$value['id']]['sub_num']) && $orderLogList[$value['id']]['sub_num'] >= $value['day_num']){
                    unset($orderList[$key]);
                }
            }
        }

        unset($resultList);
        unset($orderIdArr);
        unset($orderLogList);

        return $orderList;
    }

    /**
     * 获取订单每日关注情况
     * @param $orderId 格式：1,2,23,4
     */
    public function getOrderLogList($orderId){
        $data = [];
        if (empty($orderId)) return $data;

        $where = [
            'oid_in' => $orderId,
            'date' => date('Y-m-d'),
        ];
        $orderLogModel = new Pi_FansOrderLogModel();
        $resultList = $orderLogModel->getOrderLogList($where,'oid,sub_num');
        if (isset($resultList['allrow']) && count($resultList['allrow']) > 0){
            foreach ($resultList['allrow'] as $key=>$value){
                $data[$value['oid']] = $value;
            }
        }

        unset($resultList);

        return $data;
    }

    /**
     * 获取用户关注的公众号
     * @param $where ['字段名' => '值']
     */
    public function getUserWxId(array $where){
        $where['status'] = 2;//状态为已关注
        $userModel = new Pi_FansUserModel();
        $userWxList = $userModel->getUserList($where,'wid');
        $wxarray = [];
        if (isset($userWxList['allrow']) && count($userWxList['allrow']) > 0){
            foreach ($userWxList['allrow'] as $key=>$value){
                $wxarray[$value['wid']] = $value['wid'];
            }
        }

        unset($userWxList);

        return $wxarray;
    }

    /**
     * 获取公众号信息
     * @param $where  条件 ['字段名' => '值']
     * @param $wxId 公众号id
     */
    public function getWeixinInfo(array $where,$wxId){
        $data = [];
        if (empty($wxId)) return $data;
        $where['id_in'] = $wxId;
        $where['status'] = 1;
        $weixinModel = new Pi_FansWeixinModel();
        $result = $weixinModel->getWeixinList($where,'id,wx_type,principal_name,appid,shopid,secretkey,service_type,verify_type,accredit,status,wx_photo,success_url');
        if (isset($result['allrow']) && count($result['allrow']) > 0){
            $data = $result['allrow'];
        }

        unset($result);

        return $data;
    }

    /**
     * 获取公众号id字符串 格式：1,2,23,4
     * @param array $orderId
     * @return string
     */
    public function getWxIdStr(array $orderId){
        $str = '';
        if (empty($orderId)) return $str;
        foreach ($orderId as $key=>$value){
            if (isset($value['wid'])) {
                if ($str == '') {
                    $str = $value['wid'];
                } else {
                    $str .= ',' . $value['wid'];
                }
            }
        }
        return $str;
    }

    /**
     * 合并公众号和订单
     * @param array $weixinList  公众号列表
     * @param array $orderList  订单列表
     */
    public function mergeOrderWx(array $weixinList, array $orderList){
        $data = [];
        foreach ($weixinList as $key=>$value){
            if ($value['status'] == 1 && !empty($value['appid']) && !empty($value['shopid']) && !empty($value['secretkey']) && isset($orderList[$value['id']])){
                $data[$value['id']] = $value;
                $data[$value['id']]['order_id'] = $orderList[$value['id']]['id'];
                $data[$value['id']]['priority'] = $orderList[$value['id']]['priority'];
            }
        }
        return $data;
    }

    /**
     * 根据优先级随机选择一个订单
     * @param array $orderList
     */
    public function randomOrder(array $orderList){
        //等级概率精度,['key' => '值']，值越大概率越大
        $priorityArr = [
            1 => 80,
            2 => 14,
            3 => 3,
            4 => 2,
            5 => 1,
        ];
        
        $order = [];
        if (empty($orderList)) return $order;

        //按等级分组订单
        $orderPrList = [];
        $proSum = 0; //概率数组的总概率精度
        $proArr = []; //当前包含的等级
        foreach ($orderList as $key=>$value){
            $orderPrList[$value['priority']][] = $value;
            $proSum += $priorityArr[$value['priority']];
            $proArr[$value['priority']] = $priorityArr[$value['priority']];
        }

        if (count($orderPrList) == 1){
            $ordKey = array_rand($orderList);
            $order = $orderList[$ordKey];
        }elseif (count($orderPrList) > 1 && count($proArr) > 1){
            //概率数组循环
            $proKey = 1;
            foreach ($proArr as $key => $proCur) {
                $randNum = mt_rand(1, $proSum);
                if ($randNum <= $proCur) {
                    $proKey = $key;
                    break;
                } else {
                    $proSum -= $proCur;
                }
            }

            if (count($orderPrList[$proKey]) > 1){
                $ordKey = array_rand($orderPrList[$proKey]);
                $order = $orderPrList[$proKey][$ordKey];
            }else{
                $order = $orderPrList[$proKey][0];
            }
        }

        unset($orderList);
        unset($orderPrList);
        unset($proArr);

        return $order;
    }

    /**
     * 新增用户记录
     * @param array $params 新增字段值  ['字段名' => '值']
     */
    public function addFansUserAction(array $params){
        $result = ['status' => 0, 'data' => ''];
        if (empty($params) || !isset($params['mobile']) || !isset($params['openid']) || !isset($params['wid']) || !isset($params['oid'])) return $result;

        $params['status'] = 1;
        $params['subtime'] = 0;
        $params['unsubtime'] = 0;
        $params['ctime'] = time();
        $userModel = new Pi_FansUserModel();

        //检查是否存在
        $where = ['openid' => $params['openid']];
        $isE = $userModel->getFansUser($where,'id,oid,mobile,status');
        if (isset($isE['id']) && $isE['id'] > 0) {//存在
            if (($isE['mobile'] != $params['mobile'] || $isE['oid'] != $params['oid']) && $isE['status'] != 2){//更新

                $data = ['mobile' => $params['mobile'], 'oid' => $params['oid']];
                $addResult = $userModel->updateUser($data,$where);

                if (!empty($addResult) && $addResult > 0) {
                    $result = ['status' => 1, 'data' => ['id' => $isE['id']]];
                }
            }else{
                $result = ['status' => 1, 'data' => ['id' => $isE['id']]];
            }
        }else{
            $addResult = $userModel->addFansUser($params);
            if ($addResult != false && $addResult > 0) {
                $result = ['status' => 1, 'data' => ['id' => $addResult]];
            }
        }
        return $result;
    }

    /**
     * 获取用户信息
     * @param array $where
     */
    public function getUserAction(array $where){
        $result = ['status' => 0, 'data' => ''];
        if (empty($where ) || !isset($where['where']) || !isset($where['field'])) return $result;

        $userModel = new Pi_FansUserModel();
        $data = $userModel->getFansUser($where['where'],$where['field']);
        if (!empty($data)) $result = ['status' => 1, 'data' => $data];
        return $result;
    }

    /**
     * 当用户关注时，修改订单数量和用户的状态
     * @param array $where
     */
    public function subscribeAction(array $where){
        $result = ['status' => 0, 'data' => ''];

        if (!isset($where['openid'])) return $result;
        //更改用户记录
        $this->updateWxFansLog($where['openid']);

        $wxfans = new wxfans();
        $upResult = $wxfans->updateOrder($where['openid']);
        if ($upResult == true){
            $result = ['status' => 1, 'data' => ''];
        }
        return $result;
    }

    /**
     * 取消关注时修改数据
     * @param array $where
     * @return array|bool
     */
    public function unsubscribeAction(array $where){
        $result = ['status' => 0, 'data' => ''];

        if (!isset($where['openid'])) return $result;
        $wxfans = new wxfans();
        $upResult = $wxfans->unsubscribe($where['openid']);
        if ($upResult == true){
            $result = ['status' => 1, 'data' => ''];
        }
        return $result;
    }

    /**
     * 新增用户微信唤起记录,用于判断是否把用户踢下线
     * @param $data
     */
    public function addWxFansLogAction(array $data){
        $result = ['status' => 0, 'data' => ''];
        if (isset($data['ip']) && !empty($data['mac']) && !empty($data['mobile']) && !empty($data['openid']) && isset($data['type']) && isset($data['appkey']) && isset($data['subscribe']) && !empty($data['wid'])){
            $from = $data['mobile'] == '12345678900' ? 100 : 0;
            $data = [
                'mobile' => $data['mobile'],
                'openid' => $data['openid'],
                'ip' => $data['ip'],
                'mac' => $data['mac'],
                'subscribe' => $data['subscribe'],
                'type' => $data['type'],
                'appkey' => $data['appkey'],
                'wid' => $data['wid'],
                'from' => $from,
                'ctime' => time(),
            ];
            $fansModel = new Pi_WeixinFansLogModel();
            $add = $fansModel->addOneLog($data);
            if ($add >= 0) {
                $result = ['status' => 1, 'data' => $add];
            }
        }
        return $result;
    }

    /**
     * 修改用户记录最新一条的状态
     * @param $openid
     */
    public function updateWxFansLog($openid){
        if (empty($openid)) return 0;
        $wxRule = new Pi_WeixinFansLogRule();
        $num = $wxRule->updateNewOne($openid);
        return $num;
    }

    /**
     * 获取一条微信加粉记录,脚本处理未关注用户
     * @param array $params
     */
    public function getOneWxFansLogAction(array $params){
        $result = ['status' => 0, 'data' => ''];
        if (empty($params['where']) || empty($params['field'])) return $result;

        $wxModel = new Pi_WeixinFansLogRule();
        $data = $wxModel->getOneWxLog($params['where'],$params['field']);
        if (!empty($data)){
            $result = ['status' => 1, 'data' => $data];
        }
        return $result;
    }

    /**
     * 新增/累加微信加粉获取订单数据
     * @param array $params
     */
    public function addWxOrderCountAction(array $params){
        $result = ['status' => 0, 'data' => ''];
        if (empty($params['date']) || !isset($params['type'])) return $result;

        $wxModel = new Pi_FansOrderCountModel();
        $where = ['date' => $params['date']];
        $oneCount = $wxModel->getOrderCount($where,'id');

        if (!isset($oneCount['id'])){//记录不存在
            $data = ['date' => $params['date'], 'ctime' => time(),];
            if ($params['type'] == 1){//获取订单成功数
                $data['success_num'] = 1;
            }else{//获取订单数
                $data['get_num'] = 1;
            }
            $add = $wxModel->addOrderCount($data);
            if ($add >= 0){
                $result = ['status' => 1, 'data' => $add];
            }
        }else{//记录存在
            $wxRule = new Pi_FansOrderCountRule();
            $upResult = $wxRule->updateData($oneCount['id'],$params['type']);
            if ($upResult){
                $result = ['status' => 1, 'data' => $oneCount['id']];
            }
        }
        return $result;
    }
}
