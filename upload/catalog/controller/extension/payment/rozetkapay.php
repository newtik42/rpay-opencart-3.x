<?php

include_once DIR_SYSTEM .'library/payment/RozetkaPay/autoloader.php';

class ControllerExtensionPaymentRozetkaPay extends Controller {
    
    protected $version = '';
    
    private $type = 'payment';
    private $code = 'rozetkapay';
    private $path = 'extension/payment/rozetkapay'; 
    private $prefix = 'payment_';

    private $error = array();
    private $debug = false;
    private $extlog = false;
    private $rpay;

    public function __construct($registry) {
        parent::__construct($registry);

        $this->load->model('checkout/order');
        $this->language->load($this->path);

        $this->debug = $this->config->get($this->prefix.'rozetkapay_test_status') === "1";

        if ($this->config->get($this->prefix.'rozetkapay_log_status') === "1") {
            $this->extlog = new Log('rozetkapay');
        }

        $this->rpay = new \Payment\RozetkaPay\RozetkaPay();

        if ($this->config->get($this->prefix.'rozetkapay_test_status') === "1") {
            $this->rpay->setBasicAuthTest();
        } else {
            $this->rpay->setBasicAuth($this->config->get($this->prefix.'rozetkapay_login'), $this->config->get($this->prefix.'rozetkapay_password'));
        }
        
    }
    
    public function log($var){
        if($this->extlog !== false){
            $this->extlog->write($var);
        }
    }

    public function index() {

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_pay'] = $this->language->get('button_pay');
        $data['button_pay_holding'] = $this->language->get('button_pay_holding');
        
        $data['path'] = $this->path;
        
        return $this->load->view($this->path, $data);
    }
    
    public function confirm() {
        
    }
    
    public function createPay() {

        $json = [];

        $json['qrcode'] = false;
        $json['pay'] = false;
        $json['pay_holding'] = false;

        $json['alert'] = [];

        if ($this->session->data['payment_method']['code'] == 'rozetkapay') {

            $status_qrcode = $this->config->get($this->prefix.'rozetkapay_qrcode_status') === "1";
            $json['qrcode'] = $status_qrcode;
            
            $status_holding = $this->config->get($this->prefix.'rozetkapay_holding_status') === "1";

            $order_id = $this->session->data['order_id'];

            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            if ($order_info['order_status_id'] != $this->config->get($this->prefix.'rozetkapay_order_status_init')) {
                $this->model_checkout_order->addOrderHistory($order_id, $this->config->get($this->prefix.'rozetkapay_order_status_init'));
            }

            $this->rpay->setResultURL($this->url->link($this->path.'/result', 'order_id=' . $order_id, true));
            $this->rpay->setCallbackURL($this->url->link($this->path.'/callback', 'order_id=' . $order_id, true));

            $order_info = $this->model_checkout_order->getOrder($order_id);

            $dataCheckout = new \Payment\RozetkaPay\Model\PaymentCreateRequest();
            
            
            if($this->config->get($this->prefix.'rozetkapay_send_info_customer_status') == "1"){
                
                $customer = new \Payment\RozetkaPay\Model\Customer();
                
                $customer->email = $order_info['email'];
                
                $customer->first_name = (empty ($order_info['payment_firstname']))?$order_info['firstname']:$order_info['payment_firstname'];
                $customer->last_name = (empty ($order_info['payment_lastname']))?$order_info['lastname']:$order_info['payment_lastname'];
                
                $customer->country = $order_info['payment_iso_code_2'];
                $customer->city = $order_info['payment_city'];
                $customer->postal_code = $order_info['payment_postcode'];
                $customer->address = $order_info['payment_address_1'];
                $customer->phone = $order_info['telephone'];
                
                $langs = explode("-", $order_info['language_code']);
                
                if(isset($langs[0])){
                    $customer->locale = strtoupper($langs[0]);
                }     
                
                $dataCheckout->customer = $customer;
                
            }
            
            if($this->config->get($this->prefix.'rozetkapay_send_info_products_status') == "1"){
                
                $this->load->model('tool/image');
                $this->load->model('catalog/product');
                
                $products = $this->model_checkout_order->getOrderProducts($order_id);
                
                foreach ($products as $product_) {
                    
                    $product_info = $this->model_catalog_product->getProduct($product_['product_id']);
                    
                    $productNew = new \Payment\RozetkaPay\Model\Product();
                    
                    $productNew->id = $product_['product_id'];
                    $productNew->name = $product_['name'];
                    
                    if(!empty($product_info['image'])){
                        $productNew->image = $image = $this->model_tool_image->resize(
                                $product_info['image'], 
                                $this->config->get('theme_' . $this->config->get('config_theme') . '_image_cart_width'), 
                                $this->config->get('theme_' . $this->config->get('config_theme') . '_image_cart_height'));
                    }
                    $productNew->quantity = $product_['quantity'];
                    $productNew->net_amount = $product_['total'];
                    $productNew->vat_amount = $product_['tax'];
                    
                    $productNew->url = $this->url->link('product/product', 'product_id=' . $product_['product_id'] , true);
                    
                    $dataCheckout->products[] = $productNew;
                    
                }
                
            }

            if ($order_info['currency_code'] != "UAH") {
                $order_info['total'] = $this->currency->convert($order_info['total'], $order_info['currency_code'], "UAH");
                $order_info['currency_code'] = "UAH";
            }

            $dataCheckout->amount = $order_info['total'];
            $dataCheckout->external_id = $order_id;
            $dataCheckout->currency = $order_info['currency_code'];
            
            list($result, $error) = $this->rpay->paymentCreate($dataCheckout);

            $json['pay'] = false;
            if ($error === false && isset($result->is_success)) {
                if (isset($result->action) && $result->action->type == "url") {
                    $json['pay_href'] = $result->action->value;
                    $json['pay'] = true;
                }
            } else {
                $json['alert'][] = $error->message;
            }

            if ($status_qrcode && $json['pay']) {
                $json['pay_qrcode'] = (new \chillerlan\QRCode\QRCode)->render($json['pay_href']);
            }

            if ($status_holding) {

                $dataCheckout->callback_url = $this->url->link($this->path.'/callback', 'order_id=' . $order_id . "&holding", true);
                $dataCheckout->result_url = $this->url->link($this->path.'/result', 'order_id=' . $order_id . "&holding", true);
                list($result, $error) = $this->rpay->paymentCreate($dataCheckout);

                $json['pay_holding'] = false;

                if ($error === false && isset($result->is_success)) {

                    if (isset($result->action) && $result->action->type == "url") {

                        $json['pay_holding_href'] = $result->action->value;
                        $json['pay_holding'] = true;
                    }
                } else {
                    $json['alert'][] = $error->message;
                }

                if ($status_qrcode && $json['pay_holding']) {

                    $json['pay_holding_qrcode'] = (new \chillerlan\QRCode\QRCode)->render($json['pay_holding_href']);
                }
            }

            if (isset($result->data)) {
                $json['message'] = $result->data['message'];
            } elseif (isset($result->message)) {
                $json['message'] = $result->message;
            }

            $this->log($this->rpay->debug);
            $this->log($json['alert']);

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
        }
    }

    public function callback() {
        
        $this->log('fun: callback');
        $this->log(file_get_contents('php://input'));
        
        $result = $this->rpay->сallbacks();
        
        if(!isset($result->external_id)){
            $this->log('Failure error return data:');
            return;
        }
        
        $this->log('result:');
        $this->log($result);
        $order_id = $result->external_id;
        $status = $result->details->status;
        
        $this->log('    order_id: ' . $order_id);
        $this->log('    status: ' . $status);
        
        $orderStatus_id = $this->getRozetkaPayStatusToOrderStatus($status);
        
        $this->log('    orderStatus_id: ' . $orderStatus_id);
        
        $status_holding = isset($this->request->get['holding']);        
        $this->log('    hasHolding: ' . $status_holding);
        
        $refund = isset($this->request->get['refund']);        
        $this->log('    hasRefund: ' . $refund);

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if(!$refund){
            if ($orderStatus_id != "0" && $order_info['order_status_id'] != $orderStatus_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $orderStatus_id, 'RozetkaPay' . (($status_holding)?' holding':''), false);
            }
        }
    }

    public function result() {
        
        $this->log('fun: result');

        if (isset($this->request->get['order_id'])) {
            $order_id = $this->request->get['order_id'];
        } elseif (isset($this->session->data['order_id'])) {
            $order_id = $this->session->data['order_id'];
        }
        
        $this->log('    order_id: ' . $order_id);

        $order_info = $this->model_checkout_order->getOrder($order_id);

        $complete = false;

        foreach ((array) $this->config->get('config_complete_status') as $order_status_id) {
            
            if ($order_status_id == $order_info['order_status_id']) {             
                $complete = true;
                break;
            }
        }
        
        if($this->config->get($this->prefix.'rozetkapay_order_status_success') == $order_info['order_status_id']){
            $complete = true;
        }
        
        $status_holding = isset($this->request->get['holding']);
        
        if($status_holding){
            
            $dataPay = new \Payment\RozetkaPay\Model\PaymentCreateRequest();
            
            $dataPay->external_id = $order_id;
            $dataPay->amount = $order_info['total'];
            $dataPay->currency = $order_info['currency_code'];
            $dataPay->callback_url = $this->url->link($this->path.'/callback', 'order_id=' . $order_id . "&holding");
            
            list($result, $error) = $this->rpay->paymentConfirm($dataPay);
            
        }else{
            if ($complete) {
                $url = $this->url->link('checkout/success', '', true);
            } else {
                $url = $this->url->link('checkout/failure', '', true);
            }
        }

        $this->response->redirect($url);
        
    }

    public function getRozetkaPayStatusToOrderStatus($status) {

        switch ($status) {
            case "init":
                return $this->config->get($this->prefix.'rozetkapay_order_status_init');
                break;
            case "pending":
                return $this->config->get($this->prefix.'rozetkapay_order_status_pending');
                break;
            case "success":
                return $this->config->get($this->prefix.'rozetkapay_order_status_success');
                break;
            case "failure":
                return $this->config->get($this->prefix.'rozetkapay_order_status_failure');
                break;

            default:
                return "0";
                break;
        }
    }

}
