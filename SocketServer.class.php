<?php

class SocketServer
{
	protected $config;
	protected $hooks;
	protected $socket;
	
	public $max_clients = 10;
	public $max_read = 4096;
	public $clients;
	
	const CMD_GET_SETTINGS = 'SI';
	const CMD_SYNC_ROOMS = 'SR';
	const CMD_AVAL_ROOMS = 'RI';
	const CMD_AVAL_ROOMS_RANGE = 'R1';
	const CMD_CREATE_RESV = 'NR';
	const DS = '|';
	const RESP_OK = 'OK';
	
	protected static $vars = array(0 => 'code', 1 => 'name', 2 => 'count', 3 => 'max_beds', 4 => 'max_extra_beds', 5 => 'description');
	
	const LNG_DAY = 'ден';
	const LNG_DAYS = 'дни';
	
	public function __construct()
	{
		set_time_limit(0);
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	}
	
	public function connect($host, $port)
	{
		$sock = $this->socket;
		
		if ($sock === false) {
			echo "socket_create() failed: reason: " . 
				 socket_strerror(socket_last_error()) . "\n";
		}
		
		$result = @socket_connect($sock, $host, $port);
		
		if ($result === false) {
			echo "socket_connect() failed.\nReason: ($result) " . 
				  socket_strerror(socket_last_error($sock)) . "\n";
		}
	}
	
	public function send_command($cmd)
	{
		return socket_write($this->socket, $cmd . chr(13) . chr(10)) or die("socket_write failure. Request " . $cmd . " is not sent!");
	}
	
	public function createResv($room = false, $booking)
	{
		// Формиране на командата
		$cmd = self::CMD_CREATE_RESV . self::DS .  'PSMe';
		$cmd .= self::DS . 'RN' . $booking['id'] . self::DS; // Номер на резервация
		$cmd .= self::DS . 'CL' . $booking['fullname'] . self::DS; // Клиент по резервацията
		$cmd .= self::DS . 'DA' . $booking['start_date'] . self::DS; // дата на Check in YYYYMMDD
		$cmd .= self::DS . 'NN' . $booking['days'] . self::DS; // Брой на нощувките
		$cmd .= self::DS . 'CT' . $booking['client_notes'] . self::DS; // Данни за контакт
		$cmd .= self::DS . 'SW' . $booking['special_notes'] . self::DS; // Специфични изисквания на гостите
		$cmd .= self::DS . 'RT' . $booking['room_code'] . self::DS; // Указател за резервиране на стая от даден вид
		$cmd .= self::DS . 'GN' . $booking['guests'] . self::DS; // Общ брой на гостите (възрастни + деца) в съответната стая
		$cmd .= self::DS . 'GC' . $booking['children'] . self::DS; // Брой на големите деца
		$cmd .= self::DS . 'GI' . $booking['children'] . self::DS; // Брой на малките деца
		$cmd .= self::DS . 'GF' . $booking['children'] . self::DS; // Брой на безплатните деца
		$cmd .= self::DS . 'EM' . $booking['email'] . self::DS; // eMail на клиента
		$cmd .= self::DS . 'OP' . $booking['total'] . self::DS; // Общата цена на офертата дадена на клиента за резервирания престой
		
		$this->send_command($cmd);
		$response = $this->read();
		
		if (mb_substr($response, 0, 2) == self::RESP_OK)
		{
			$split = explode(self::DS, $response);
			$response_id = $split[1]; // Обратен номер на резервацията в ЕлТур
		}
	}
	
	public function checkAvailability($data)
	{
		$res = [];
		$hotel_id = $data['hotel_id'];
		$days = self::dateDiff($data['start_date'], $data['end_date']);
		
		$date = str_replace('-', '', $data['start_date']);
		
		$dataConnect = $this->getHotelConnection($hotel_id);
		
		$this->connect($dataConnect['ip'], $dataConnect['port']);
		
		$rooms = $this->iaDb->all(iaDb::ALL_COLUMNS_SELECTION, "`hotel_id` = '{$hotel_id}'", 0, null, self::$_tableRooms);
		
		// Формиране на командата
		$cmd = self::CMD_AVAL_ROOMS . self::DS .  'PSMe|LABG';
		$cmd .= self::DS . 'DA' . $date . self::DS; // дата във формат YYYYMMDD
		$cmd .= self::DS . 'NN' . $days . self::DS; // брой нощувки
		
		$this->send_command($cmd);
		
		$response = $this->read();
		
		$result = array();
		
		if (mb_substr($response, 0, 2) == self::RESP_OK)
		{
			$resp = mb_substr($response, 3, null);
			$resp = rtrim($resp);
			$resp = rtrim($resp, PHP_EOL);
			$resp = rtrim($resp, self::DS);
			
			$resp = explode(self::DS, $resp);
			
			$i = 0;
			foreach ($resp as $k => $re)
			{
				$key = false;
				$code = ($k % 2 == 0) ? 'code' : 'availability';
				$even = ($k % 2 == 0) ? false : true;
				$res[$i][$code] = $re;
				
				if ($code == 'code')
				{
					$key = array_search($re, array_column($rooms, 'code'));
					if ($key)
					{
						$res[$i]['data'] = $rooms[$key];
					}
				}
				
				if ($even) $i++;
			}
			
			// Записване на наличността на стаите в базата данни
			$this->saveAvailability($res);
			
			return $res;
		}
		
		return false;
	}
	
	public function read()
	{
		$result = @socket_read($this->socket, $this->max_read) or die('Неуспешна връзка със сървъра. Моля, опитайте по-късно.');
		return mb_convert_encoding($result, 'UTF-8', 'WINDOWS-1251');
	}
	
	public function getSystemSettings()
	{
		// Формиране на командата
		$cmd = self::CMD_GET_SETTINGS . self::DS .  'PSMe|LABG|';
		$this->send_command($cmd);
		
		$response = $this->read();
		
		return explode(PHP_EOL, $response);
	}
	
	public function getRoomsFromServer()
	{
		$res = [];
		
		// Формиране на командата
		$cmd = self::CMD_SYNC_ROOMS . self::DS .  'PSMe|LABG|';
		$this->send_command($cmd);
		
		$response = $this->read();
		
		$result = array();
		
		if (mb_substr($response, 0, 2) == self::RESP_OK)
		{
			$resp = mb_substr($response, 3, null);
			
			$resp = rtrim($resp);
			$resp = rtrim($resp, PHP_EOL);
			$resp = rtrim($resp, self::DS);
			
			$resp = explode(self::DS, $resp);
			
			$r = 0;
			foreach ($resp as $k => $re)
			{
				if ($re || $re === "0")
				{
					$cc = (isset($res[$r])) ? count($res[$r]) : 0;
					$res[$r][self::$vars[$cc]] = $re;
				}
				if (($k + 1) % 6 == 0) $r++;
			}
			
			return $res;
		}
		
		return false;
	}
	
	public function saveAvailability($results)
	{
		// Функция за записване на наличността в базата данни
	}
	
	public static function dateDiff($date_start, $date_end, $_suffix = false)
	{
		$result = round(abs(strtotime($date_end)-strtotime($date_start))/86400);
		
		if ($_suffix)
		{
			$result .= ' ';
			$result .= ($result > 1) ? self::LNG_DAYS : self::LNG_DAY;
		}
		
		$result = ($result == 0) ? 1 : $result;
		
		return $result;
	}
	
	public function disconnect()
	{
		socket_close($this->socket);
	}
}	