<?php

	require_once 'SocketServer.class.php';
	
	header('Content-type: text/html; charset=UTF-8');
	mb_internal_encoding('UTF-8');
	
	$server = new SocketServer();
	
	$server->connect($host, $port);
  
  // Използване на функциите
	
	$server->disconnect();
