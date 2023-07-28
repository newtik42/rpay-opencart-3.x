<?php

include_once DIR_SYSTEM .'library/payment/RozetkaPay/autoloader.php';

class ControllerExtensionPaymentRozetkaPay extends Controller {
    protected $version = '1.2.10';

    private $type = 'payment';
    private $code = 'rozetkapay';
    private $path = 'extension/payment/rozetkapay';    
    private $prefix = 'payment_';    
    private $token_name = 'user_token';
    
    
    private $type_code = '';
    
    private $error = array();
    
    private $token_value = '';
    private $tokenUrl = '';
    
    private $log_file = 'rozetkapay';    
    private $extLog;
    
    public function __construct($registry) {
        parent::__construct($registry);
        
        $this->load->language($this->path);
        $this->token_value = $this->session->data[$this->token_name];
        $this->tokenUrl = '&' . $this->token_name . '=' . $this->token_value;
        
    }

    public function index() {
        
        $this->SysDBCheck();
        
        $this->document->setTitle($this->language->get('heading_title'));
        
        $this->save($data);
        
        $data['breadcrumbs'] = $this->breadcrumbs();

        $data['action'] = $this->SysUrl($this->path, $this->tokenUrl, true);
        $data['cancel'] = $this->SysUrl('extension/extension', $this->tokenUrl . '&type=payment', true);
        
        $data['href_log_download'] = $this->SysUrl($this->path . '/logdownload', $this->tokenUrl, true);
        $data['href_log_clear'] =  $this->SysUrl($this->path . '/logclear', $this->tokenUrl, true);
        $data['log'] = '';        

        $arr = array("rozetkapay_login", "rozetkapay_password", "rozetkapay_status", 
            "rozetkapay_sort_order", "rozetkapay_geo_zone_id", "rozetkapay_holding_status",
            "rozetkapay_order_status_init","rozetkapay_order_status_pending","rozetkapay_qrcode_status",
            "rozetkapay_order_status_success","rozetkapay_order_status_failure","rozetkapay_test_status", "rozetkapay_log_status",
            'rozetkapay_send_info_customer_status', 'rozetkapay_send_info_products_status');

        foreach ($arr as $v) {
            $data[$this->prefix.$v] = (isset($this->request->post[$this->prefix.$v])) ? $this->request->post[$this->prefix.$v] : $this->config->get($this->prefix.$v);            
        }
        
        if (isset($this->request->post[$this->prefix.'rozetkapay_view_icon_status'])) {
			$data[$this->prefix.'rozetkapay_view_icon_status'] = $this->request->post[$this->prefix.'rozetkapay_view_icon_status'];
		} elseif ($this->config->get($this->prefix.'rozetkapay_view_icon_status') !== null) {
			$data[$this->prefix.'rozetkapay_view_icon_status'] = $this->config->get($this->prefix.'rozetkapay_view_icon_status');
		} else {
			$data[$this->prefix.'rozetkapay_view_icon_status'] = true;
		}
        
        if (isset($this->request->post[$this->prefix.'rozetkapay_view_title_default'])) {
			$data[$this->prefix.'rozetkapay_view_title_default'] = $this->request->post[$this->prefix.'rozetkapay_view_title_default'];
		} elseif ($this->config->get($this->prefix.'rozetkapay_view_title_default') !== null) {
			$data[$this->prefix.'rozetkapay_view_title_default'] = $this->config->get($this->prefix.'rozetkapay_view_title_default');
		} else {
			$data[$this->prefix.'rozetkapay_view_title_default'] = true;
		}
        
        if (isset($this->request->post[$this->prefix.'rozetkapay_view_title'])) {
			$data[$this->prefix.'rozetkapay_view_title'] = $this->request->post[$this->prefix.'rozetkapay_view_title'];
		} elseif ($this->config->get($this->prefix.'rozetkapay_view_title') !== null) {
			$data[$this->prefix.'rozetkapay_view_title'] = $this->config->get($this->prefix.'rozetkapay_view_title');
		} else {
			$data[$this->prefix.'rozetkapay_view_title'] = array();
		}
        
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages(array('start' => 0,'limit' => 999));
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();     
        
        $data['path'] = $this->path;
        $data['tokenUrl'] = $this->tokenUrl;
		$data['prefix'] = $this->prefix;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->path, $data));
    }

    private function validate() {
        
        if (!$this->user->hasPermission('modify', $this->path)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        
        if((isset($this->request->post[$this->prefix.'rozetkapay_status']) && $this->request->post[$this->prefix.'rozetkapay_status'] == "1")){
            
            if(isset($this->request->post[$this->prefix.'rozetkapay_test_status']) && $this->request->post[$this->prefix.'rozetkapay_test_status'] != "1"){

                if (empty($this->request->post[$this->prefix.'rozetkapay_login'])) {
                    $this->error['login'] = $this->language->get('error_login');
                }

                if (empty($this->request->post[$this->prefix.'rozetkapay_password'])) {
                    $this->error['password'] = $this->language->get('error_password');
                }

            }
            
            $this->load->model('localisation/currency');
            
            $iUAH = $this->model_localisation_currency->getCurrencyByCode('UAH');
            
            if(empty($iUAH)){
                $this->error['warning'] = $this->language->get('error_currency_not_uah');
            }
            
        }
        
        return  !$this->error;
    }
    
    private function save(&$data) {
        
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            
            if(isset($this->request->post[$this->prefix.'rozetkapay_login'])){
                $this->request->post[$this->prefix.'rozetkapay_login'] = trim($this->request->post[$this->prefix.'rozetkapay_login']);
            }
            
            if(isset($this->request->post[$this->prefix.'rozetkapay_password'])){
                $this->request->post[$this->prefix.'rozetkapay_password'] = trim($this->request->post[$this->prefix.'rozetkapay_password']);
            }
            
            
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting($this->prefix . $this->code, $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->SysUrl($this->path, $this->tokenUrl, true));
        }
        
        if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];

			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		if (isset($this->session->data['error'])) {
			$data['error'] = $this->session->data['error'];

			unset($this->session->data['error']);
		} else {
			$data['error'] = '';
		}
        
        $arr = array('warning', 'login', 'password', 'order_status_success', 'order_status_failure', 'title');
        
        foreach ($arr as $v)
            $data['error_' . $v] = (isset($this->error[$v])) ? $this->error[$v] : false;
        
    }
    
    public function breadcrumbs() {
        
        $breadcrumbs = array();
        
        $breadcrumbs[] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->SysUrl('common/dashboard', $this->tokenUrl, true),
            'separator' => false            
        );

        $breadcrumbs[] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->SysUrl('marketplace/extension', '&type=payment'. $this->tokenUrl, true),
            'separator' => "::"
        );

        $breadcrumbs[] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->SysUrl($this->path, $this->tokenUrl, true),
            'separator' => "::"
        );
        
        return $breadcrumbs;
        
    }
    
    public function logdownload() {

		$file = DIR_LOGS . $this->log_file;

		if (file_exists($file) && filesize($file) > 0) {
			$this->response->addheader('Pragma: public');
			$this->response->addheader('Expires: 0');
			$this->response->addheader('Content-Description: File Transfer');
			$this->response->addheader('Content-Type: application/octet-stream');
			$this->response->addheader('Content-Disposition: attachment; filename="' . $this->config->get('config_name') . '_' . date('Y-m-d_H-i-s', time()) . $th .'.log"');
			$this->response->addheader('Content-Transfer-Encoding: binary');

			$this->response->setOutput(file_get_contents($file, FILE_USE_INCLUDE_PATH, null));
		} else {
			$this->session->data['error'] = sprintf($this->language->get('error_warning'), basename($file), '0B');

			$this->response->redirect($this->SysUrl($this->path, $this->tokenUrl, true));
		}
	}
	
	public function logclear() {

        $file = DIR_LOGS . $this->log_file;

        $handle = fopen($file, 'w+');

        fclose($handle);

        $this->session->data['success'] = $this->language->get('text_success');

		$this->response->redirect($this->SysUrl($this->path, $this->tokenUrl, true));
        
	}
    
    public function logrefresh(){
        
        $json = [];
        
        $json['ok'] = true;
        
        $file = DIR_LOGS . $this->log_file;

		if (file_exists($file)) {
			$size = filesize($file);

			if ($size >= 5242880) {
				$suffix = array(
					'B',
					'KB',
					'MB',
					'GB',
					'TB',
					'PB',
					'EB',
					'ZB',
					'YB'
				);

				$i = 0;

				while (($size / 1024) > 1) {
					$size = $size / 1024;
					$i++;
				}
                $json['ok'] = false;
				$json['warning'] = sprintf($this->language->get('error_log_warning'), round(substr($size, 0, strpos($size, '.') + 4), 2) . $suffix[$i]);
			} else {
				$json['log'] = file_get_contents($file, FILE_USE_INCLUDE_PATH, null);
			}
		}
        
        $this->response->addHeader('Content-Type: application/json');        
        $this->response->setOutput(json_encode($json));
    }
    
    public function order() {
        
        $data['text_confirm'] = $this->language->get('text_confirm');
        
        $order_id = isset($this->request->get['order_id']) ?$this->request->get['order_id'] : false;
        
        $rpay = new \Payment\RozetkaPay\RozetkaPay();
        
        if($this->config->get($this->prefix.'rozetkapay_test_status') === "1"){
            $rpay->setBasicAuthTest();
        }else{
            $rpay->setBasicAuth($this->config->get($this->prefix.'rozetkapay_login'), $this->config->get($this->prefix.'rozetkapay_password'));
        }
        
        $data['order_id'] = $order_id;        
        $data['tokenUrl'] = $this->tokenUrl;
        $data['path'] = $this->path;
        
        $this->load->model('sale/order');
        
        $order_info = $this->model_sale_order->getOrder($order_id);
        $data['total'] = $order_info['total'];  
        
        $lang_keys = ['text_refund_amount','text_refund_button','text_transaction_datatime',
            'text_transaction_amount','text_transaction_status', 'text_transaction_type'];
        
        $data = array_merge($data, $this->SysloadLanguage($lang_keys));
        
        $data['path'] = $this->path;
        $data['tokenUrl'] = $this->tokenUrl;
		$data['prefix'] = $this->prefix;
        
        return $this->load->view($this->path.'_order', $data);
        
    }
    
    public function payRefund() {
        
        $json = [];
        
        $json['ok'] = false;
        $json['error'] = [];
        
        if(isset($this->request->post['order_id'])){
            $order_id = (int)$this->request->post['order_id'];
        }else{
            $json['error']['error_order_id'] = $this->language->get('text_payRefund_error_order_id');
        }
        
        if(isset($this->request->post['total'])){
            $total = (float)$this->request->post['total'];
        }else{
            $json['error']['error_total'] = $this->language->get('text_payRefund_error_total');
        }
        
        if($total <= 0){
            $json['error']['error_total'] = $this->language->get('text_payRefund_error_total');
        }
        
        if(empty($this->error)){
            
            $this->load->model('sale/order');
            
            $order_info = $this->model_sale_order->getOrder($order_id);

            $rpay = new \Payment\RozetkaPay\RozetkaPay();

            if($this->config->get($this->prefix.'rozetkapay_test_status') === "1"){
                $rpay->setBasicAuthTest();
            }else{
                $rpay->setBasicAuth($this->config->get($this->prefix.'rozetkapay_login'), $this->config->get($this->prefix.'rozetkapay_password'));
            }

            $rpay->setCallbackURL($this->SysUrl($this->path . '/callback', 'order_id=' . $order_id . '&refund'));

            $dataPay = new \Payment\RozetkaPay\Model\PaymentRequest();

            $dataPay->external_id = (string)$order_id;        
            $dataPay->amount = $total;
            $dataPay->currency = $order_info['currency_code'];
            
            list($status, $error) = $rpay->paymentRefund($dataPay);
            
            if($error !== false){
                $json['error'][$error->code] = $error->message;
            }
            
            $json['ok'] = $status;
            
        }
        
        if($json['ok']){
            $json['alert'] = $this->language->get('text_success');
        }else{
            $json['alert'] = $this->language->get('text_failure');
        }
        
        $this->response->addHeader('Content-Type: application/json');        
        $this->response->setOutput(json_encode($json));
        
    }
    
    public function payInfo() {
                
        $json = [];
        
        $json['ok'] = false;
        $json['details'] = [];
        $json['error'] = [];
        
        if(isset($this->request->post['order_id'])){
            $order_id = (int)$this->request->post['order_id'];
        }else{
            $json['error']['error_order_id'] = $this->language->get('text_pay_error_order_id');
        }
        
        
        if(empty($this->error)){        
            
            $this->load->model('sale/order');
            
            $order_info = $this->model_sale_order->getOrder($order_id);

            $rpay = new \Payment\RozetkaPay\RozetkaPay();

            if($this->config->get($this->prefix.'rozetkapay_test_status') === "1"){
                $rpay->setBasicAuthTest();
            }else{
                $rpay->setBasicAuth($this->config->get($this->prefix.'rozetkapay_login'), $this->config->get($this->prefix.'rozetkapay_password'));
            }

            $rpay->setCallbackURL($this->SysUrl($this->path . '/callback'));

            list($results, $json['error']) = $rpay->paymentInfo((string)$order_id);
            
            $details = [];
            if(empty($json['error'])){
                if(isset($results['purchase_details']) && !empty($results['purchase_details'])){
                    foreach ($results['purchase_details'] as $detail) {
                        $details[] = [
                            'amount' => $detail->amount,
                            'currency' => $detail->currency,
                            'status' => $detail->status,
                            'created_at' => (new \DateTime($detail->created_at))->getTimestamp(),
                            'type' => 'purchase'
                        ];
                    }
                }

                if(isset($results['confirmation_details']) && !empty($results['purchase_details'])){
                    foreach ($results['confirmation_details'] as $detail) {
                        $details[] = [
                            'amount' => $detail->amount,
                            'currency' => $detail->currency,
                            'status' => $detail->status,
                            'created_at' => (new \DateTime($detail->created_at))->getTimestamp(),
                            'type' => 'confirmation'
                        ];
                    }
                }

                if(isset($results['cancellation_details']) && !empty($results['purchase_details'])){
                    foreach ($results['cancellation_details'] as $detail) {
                        $details[] = [
                            'amount' => $detail->amount,
                            'currency' => $detail->currency,
                            'status' => $detail->status,
                            'created_at' => (new \DateTime($detail->created_at))->getTimestamp(),
                            'type' => 'cancellation'
                        ];
                    }
                }

                if(isset($results['refund_details']) && !empty($results['purchase_details'])){
                    foreach ($results['refund_details'] as $detail) {
                        $details[] = [
                            'amount' => $detail->amount,
                            'currency' => $detail->currency,
                            'status' => $detail->status,
                            'created_at' => (new \DateTime($detail->created_at))->getTimestamp(),
                            'type' => 'refund'
                        ];
                    }
                }
            }
            
            $sort_order = array();

            foreach ($details as $key => $value) {
                $sort_order[$key] = $value['created_at'];
            }

            array_multisort($sort_order, SORT_DESC, $details);
            
            $dat = new \DateTime();
            foreach ($details as $key => $detail) {
                $details[$key]['created_at'] = $dat->setTimestamp($detail['created_at'])->format($this->language->get('datetime_format'));
            }
            
            $json['ok'] = true;
            $json['details'] = $details;            
            $json['alert'] = $this->language->get('text_success');
            
        
        }else{
            
            $json['alert'] = $this->language->get('text_error');
            
        }
        
        $json['debug'] = $rpay->debug;
        
        $this->response->addHeader('Content-Type: application/json');        
        $this->response->setOutput(json_encode($json));
        
    }
    
    public function SysloadLanguage($langs_key) {
        $results = [];
        foreach ($langs_key as $key) {
            $results[$key] = $this->language->get($key);
        }
        return $results;
    }
    
    public function SysUrl($route, $args = '', $secure = false) {
        
        return  str_replace("&amp;","&", $this->url->link($route, $args, "SSL"));
        
    }
    
    public function SysDBCheck() {
        
        $row = $this->db->query("select column_name, data_type, character_maximum_length from information_schema.columns  where "
                . "TABLE_SCHEMA = '" . DB_DATABASE . "' and   table_name = '" . DB_PREFIX . "order' and column_name = 'payment_method'")->row;

        if(!empty($row)){
            $length = (int)$row['character_maximum_length'];
            $lengthNow = strlen('<img src="'.HTTPS_SERVER.'image/payment/rozetkapay/rpay.png" height="32">')+80;
            
            if($length <= $lengthNow){
                $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` MODIFY `payment_method` varchar(".$lengthNow.");");
            }
            
        }
        
    }
    

}
