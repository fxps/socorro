<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Model class for ADU, a.k.a. Active Daily Users / Installs.
 *
 * @package 	SocorroUI
 * @subpackage 	Models
 * @author 		Ryan Snyder <rsnyder@mozilla.com>
 */

/**
 * Begin Daily_Model class
 */
class Daily_Model extends Model {

	/** 
	 * The Web Service class.
	 */
 	protected $service = '';

	/**
     * Class Constructor
     */
    public function __construct()
    {
        parent::__construct();
		$this->service = new Web_Service(array('credentials' => Kohana::config('webserviceclient.basic_auth')));
    }

	/**
     * Prepare the statistics for Crashes per ADU by Operating System.
	 * 
	 * @access 	public
	 * @param 	object	The response object from the API call
	 * @return 	array 	An array of statistics
     */
	public function calculateStatisticsByOS ($results)
	{
		if (!empty($results)) {
			$statistics = array(
				'ratio' => 0.00,
				'crashes' => 0,
				'os' => array(),
				'users' => 0,
			);

			foreach ($results->versions as $version) {
				foreach ($version->statistics as $v) {
					$date = $v->date;
					$key = $v->os;
					
					if (!isset($statistics['os'][$key])) {
						$statistics['os'][$key] = array(
							'crashes' => 0,
							'users' => 0,
							'ratio' => 0.00,
						);						
					} 				

					if (!isset($statistics['os'][$key][$date])) {
						$statistics['os'][$key][$date] = array(
							'crashes' => 0,
							'users' => 0,
							'ratio' => 0.00,
						);						
					} 				
						
					$statistics['os'][$key][$date]['crashes'] += $v->crashes;
					$statistics['os'][$key][$date]['users'] += $v->users;
					if ($statistics['os'][$key][$date]['crashes'] > 0 && $statistics['os'][$key][$date]['users'] > 0) {
						$statistics['os'][$key][$date]['ratio'] = $statistics['os'][$key][$date]['crashes'] / $statistics['os'][$key][$date]['users'];
					} else {
						$statistics['os'][$key][$date]['ratio'] = 0.00;
					}

					$statistics['crashes'] += $v->crashes;
					$statistics['users'] += $v->users;					
					$statistics['os'][$key]['crashes'] += $v->crashes;
					$statistics['os'][$key]['users'] += $v->users;

					if ($statistics['os'][$key]['crashes'] > 0 && $statistics['os'][$key]['users'] > 0) { 
						$statistics['os'][$key]['ratio'] = $statistics['os'][$key]['crashes'] / $statistics['os'][$key]['users'];
					} else {
						$statistics['os'][$key]['ratio'] = 0.00;
					}
				}
			}

			if ($statistics['crashes'] > 0 && $statistics['users'] > 0) { 
				$statistics['ratio'] = $statistics['crashes'] / $statistics['users'];
			} else {
				$statistics['ratio'] = 0.00;
			}
			
			return $statistics;
		}
		return false;
	}

	/**
     * Prepare the statistics for Crashes per ADU by Version.
	 * 
	 * @access 	public
	 * @param 	object	The response object from the API call
	 * @return 	array 	An array of statistics
     */
	public function calculateStatisticsByVersion ($results)
	{
		if (!empty($results)) {
			$statistics = array(
				'ratio' => 0.00,
				'crashes' => 0,
				'versions' => array(),
				'users' => 0,
			);

			foreach ($results->versions as $version) {
				$key = $version->version;
				$statistics['versions'][$key] = array(
					'ratio' => 0.00,
					'crashes' => 0,
					'users' => 0,
					'version' => $key,
				);

				foreach ($version->statistics as $v) {
					$date = $v->date;
					if (!isset($statistics['versions'][$key][$date])) {
						$statistics['versions'][$key][$date] = array(
							'crashes' => 0,
							'users' => 0,
							'ratio' => 0.00,
						);						
					} 				
						
					$statistics['versions'][$key][$date]['crashes'] += $v->crashes;
					$statistics['versions'][$key][$date]['users'] += $v->users;
					if ($statistics['versions'][$key][$date]['crashes'] > 0 && $statistics['versions'][$key][$date]['users'] > 0) {
						$statistics['versions'][$key][$date]['ratio'] = $statistics['versions'][$key][$date]['crashes'] / $statistics['versions'][$key][$date]['users'];
					} else {
						$statistics['versions'][$key][$date]['ratio'] = 0.00;
					}

					$statistics['crashes'] += $v->crashes;
					$statistics['users'] += $v->users;					
					$statistics['versions'][$key]['crashes'] += $v->crashes;
					$statistics['versions'][$key]['users'] += $v->users;
				}
				
				if ($statistics['versions'][$key]['crashes'] > 0 && $statistics['versions'][$key]['users'] > 0) { 
					$statistics['versions'][$key]['ratio'] = $statistics['versions'][$key]['crashes'] / $statistics['versions'][$key]['users'];
				} else {
					$statistics['versions'][$key]['ratio'] = 0.00;
				}
			}

			if ($statistics['crashes'] > 0 && $statistics['users'] > 0) { 
				$statistics['ratio'] = $statistics['crashes'] / $statistics['users'];
			} else {
				$statistics['ratio'] = 0.00;
			}
			
			return $statistics;
		}
		return false;
	}

	/**
     * Prepare an array of parameters for the url.
	 * 
	 * @access 	private
	 * @param 	array 	The array that needs to be parameterized.
	 * @return 	string 	The url-encoded string.
     */
	private function encodeArray (array $parameters) {
		$uri = '';
		$parameters = array_unique($parameters);
		$num_parameters = count($parameters);
		for ($i = 0; $i <= $num_parameters; $i++) {
			$parameter = array_shift($parameters);
			if (!empty($parameter)) {
				if ($i > 0) {
					$uri .= ";";
				}
				$uri .= urlencode($parameter);
			}
		}
		return $uri;
	}

	/**
     * Format the URL for the ADU web service call.
	 * 
	 * @access 	private
	 * @param 	string 	The product name (e.g. 'Camino', 'Firefox', 'Seamonkey, 'Thunderbird')
	 * @param 	string 	The version number (e.g. '3.5', '3.5.1', '3.5.1pre', '3.5.2', '3.5.2pre')
	 * @param 	string	The start date for this product YYYY-MM-DD
	 * @param 	string	The end date for this product YYYY-MM-DD (usually +90 days)
	 * @return 	string 	The URL.
     */
	private function formatURL ($product, $versions, $operating_systems, $start_date, $end_date) {
		$host = Kohana::config('webserviceclient.socorro_hostname');
		
		$p = urlencode($product);
		$v = $this->encodeArray($versions);
		$os = $this->encodeArray($operating_systems);
		$start = urlencode($start_date);
		$end = urlencode($end_date);
		
		$url = $host . "/200912/adu/byday/p/".$p."/v/".$v."/os/".$os."/start/".$start."/end/".$end;
		return $url;
	}
	
	/**
     * Fetch records for active daily users / installs. 
	 * 
	 * @access 	public
	 * @param 	string 	The product name (e.g. 'Camino', 'Firefox', 'Seamonkey, 'Thunderbird')
	 * @param 	array 	An array of versions of this product
	 * @param 	array 	An array of operating systems to query
	 * @param 	string	The start date for this product YYYY-MM-DD
	 * @param 	string	The end date for this product YYYY-MM-DD (usually +90 days)
	 * @return 	object	The database query object
     */
	public function get($product, $versions, $operating_systems, $start_date, $end_date) {
		$url = $this->formatURL($product, $versions, $operating_systems, $start_date, $end_date);
		$lifetime = Kohana::config('webserviceclient.topcrash_vers_rank_cache_minutes', 60) * 60; // number of seconds
		$response = $this->service->get($url, 'json', $lifetime);

		if (isset($response) && !empty($response)) {
			return $response;
		} else {
			Kohana::log('error', "No ADU data was avialable at \"$url\" via soc.web daily.get()");
		}
		return false;
	}
	
	/**
     * Prepare the data for the crash graph for ADU by Operating System.
	 * 
	 * @access 	public
	 * @param 	string	The start date for this product YYYY-MM-DD
	 * @param 	string	The end date for this product YYYY-MM-DD (usually +90 days)
	 * @param 	array 	An array of dates
	 * @param 	array 	An array of operating_systems
	 * @param 	array 	An array of statistics
	 * @return 	array	The array prepared for the crash data graph
     */
	public function prepareCrashGraphDataByOS($date_start, $date_end, $dates, $operating_systems, $statistics) {
		if (!empty($statistics)) {
			$data = array(
				'startDate' => $date_start,
				'endDate'   => $date_end,
				'count' 	=> count($operating_systems),
			);
			
			for($i = 1; $i <= $data['count']; $i++) {
				$key_ratio = 'ratio' . $i;
				$key_item = 'item' . $i; 
				$item = array_shift($operating_systems);
				$data[$key_item] = $item;
				$data[$key_ratio] = array();
				foreach ($dates as $date) {
					if (isset($statistics['os'][$item][$date])) {
						array_push($data[$key_ratio], array(strtotime($date)*1000, $statistics['os'][$item][$date]['ratio']));
					} else {
						array_push($data[$key_ratio], array(strtotime($date)*1000, null));
					}
				}
			}
			return $data;
		} 
		return false;
	}
	
	/**
     * Prepare the data for the crash graph for ADU by Version.
	 * 
	 * @access 	public
	 * @param 	string	The start date for this product YYYY-MM-DD
	 * @param 	string	The end date for this product YYYY-MM-DD (usually +90 days)
	 * @param 	array 	An array of dates
	 * @param 	array 	An array of version numbers
	 * @param 	array 	An array of statistics
	 * @return 	array	The array prepared for the crash data graph
     */
	public function prepareCrashGraphDataByVersion($date_start, $date_end, $dates, $versions, $statistics) {
		if (!empty($statistics)) {
			$data = array(
				'startDate' => $date_start,
				'endDate'   => $date_end,
				'count' 	=> count($versions),
			);
			
			for($i = 1; $i <= $data['count']; $i++) {
				$key_ratio = 'ratio' . $i;
				$key_item = 'item' . $i; 
				$item = array_shift($versions);
				$data[$key_item] = $item;
				$data[$key_ratio] = array();
				foreach ($dates as $date) {
					if (isset($statistics['versions'][$item][$date])) {
						array_push($data[$key_ratio], array(strtotime($date)*1000, $statistics['versions'][$item][$date]['ratio']));
					} else {
						array_push($data[$key_ratio], array(strtotime($date)*1000, null));
					}
				}
			}
			
			return $data;
		} 
		return false;
	}


	/* */
}
