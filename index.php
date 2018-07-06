<?php

	require_once 'SocketServer.class.php';
	
	header('Content-type: text/html; charset=UTF-8');
	mb_internal_encoding('UTF-8');
	
	$server = new SocketServer();
	
	$server->connect($host, $port);
  
	// Използване на функциите
	
	// Пример:
	
	$rooms = $server->getRoomsFromServer();
	
	/* 
	    getRoomsFromServer();
		Връща информация във формат
		
		array(
			'code' => 'Код на стаята',
			'name' => 'Име на стаята',
			'count' => 'Брой налични стаи за периода',
			'max_beds' => 'Макс. брой легла',
			'max_extra_beds' => 'Макс. брой допъл. легла',
			'description' => 'Описание на стаята'
		);
	*/

	$server->disconnect();
